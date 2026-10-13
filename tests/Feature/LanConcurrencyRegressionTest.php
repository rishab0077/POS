<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Menu;
use App\Models\Table;
use App\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockCategory;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchasePostingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LanConcurrencyRegressionTest extends TestCase
{
    // Workers need committed fixtures; the regular test transaction hides them.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('category_id', 1)->firstOrFail())
            ->withSession(['auth.mfa_passed' => true]);
    }

    private function invoice(): PurchaseInvoice
    {
        $stock = StockItem::create([
            'category_id' => StockCategory::firstOrFail()->id, 'name' => 'Concurrent ingredient',
            'unit' => 'kg', 'current_quantity' => 10,
        ]);
        $invoice = PurchaseInvoice::create([
            'supplier_id' => Supplier::create(['name' => 'Concurrent supplier'])->id,
            'invoice_no' => 'CONCURRENT-1', 'bill_date' => now(), 'status' => 'draft',
            'subtotal' => 100, 'taxable_amount' => 100, 'total_amount' => 100,
            'paid_amount' => 0, 'balance_amount' => 100,
        ]);
        $invoice->items()->create([
            'stock_item_id' => $stock->id, 'item_name' => $stock->name, 'unit' => 'kg',
            'quantity' => 2, 'unit_price' => 50, 'subtotal' => 100,
            'taxable_amount' => 100, 'total_amount' => 100,
        ]);
        return $invoice;
    }

    private function compete(string $action, int $subjectId, string $lockedTable, int $lockedId): array
    {
        $files = [tempnam(sys_get_temp_dir(), 'lan-race-'), tempnam(sys_get_temp_dir(), 'lan-race-')];
        foreach ($files as $file) unlink($file);
        $processes = [];
        DB::beginTransaction();
        DB::table($lockedTable)->where('id', $lockedId)->lockForUpdate()->first();
        try {
            foreach ($files as $file) {
                $process = new Process([PHP_BINARY, base_path('tests/support/financial-worker.php'),
                    $action, (string) $subjectId, (string) auth()->id(), $file], base_path(), null, null, 15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while ((!is_file($files[0]) || !is_file($files[1])) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertTrue(is_file($files[0]) && is_file($files[1]), 'Both workers must reach the contested write/lock');
            DB::commit();
            return array_map(function (Process $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
                return trim($process->getOutput());
            }, $processes);
        } finally {
            if (DB::transactionLevel()) DB::rollBack();
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach ($files as $file) if (is_file($file)) unlink($file);
        }
    }

    public function test_concurrent_purchase_posting_adds_stock_once(): void
    {
        $invoice = $this->invoice();
        $stockId = $invoice->items->first()->stock_item_id;
        $this->compete('post', $invoice->id, 'stock_items', $stockId);
        $this->assertSame(12.0, (float) StockItem::findOrFail($stockId)->current_quantity);
        $this->assertSame(1, StockMovement::where('reference_type', 'purchase_invoice')->count());
    }

    public function test_concurrent_supplier_payments_cannot_exceed_balance(): void
    {
        $invoice = app(PurchasePostingService::class)->post($this->invoice());
        $results = $this->compete('payment', $invoice->id, 'purchase_invoices', $invoice->id);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(100.0, (float) $invoice->payments()->sum('amount'));
    }

    public function test_concurrent_full_returns_cannot_refund_twice(): void
    {
        $this->postJson(route('order.submit'), [
            'source' => 'pos', 'tableId' => Table::where('name', 'T1')->value('id'),
            'isPickUpOrder' => 'false', 'paymentMethod' => 'cash', 'billTable' => 'true',
            'order' => ['orderItems' => [['id' => Menu::where('shortcode', 'mc')->value('id'), 'quantity' => 1]]],
        ])->assertOk();
        $bill = Bill::whereNotNull('locked_at')->firstOrFail();
        $bill->fiscalSnapshot->cbmsSubmission->update(['status' => 'submitted']);
        $results = $this->compete('return', $bill->id, 'bills', $bill->id);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(1, $bill->fiscalSnapshot->creditNotes()->count());
    }
}
