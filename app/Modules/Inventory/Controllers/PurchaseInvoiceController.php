<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Inventory\Services\PurchasePostingService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only('void');
    }

    public function index(Request $request)
    {
        $query = PurchaseInvoice::with('supplier', 'createdBy')
            ->latest('bill_date')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $purchaseInvoices = $query->paginate(25)->withQueryString();

        return view('modules.inventory.purchase_invoices.index', compact('purchaseInvoices'));
    }

    public function create()
    {
        return view('modules.inventory.purchase_invoices.create', $this->formData(new PurchaseInvoice([
            'bill_date' => now(),
            'due_date' => null,
            'paid_amount' => 0,
        ])));
    }

    public function store(StorePurchaseInvoiceRequest $request, PurchasePostingService $postingService, AuditLogger $audit)
    {
        $invoice = DB::transaction(function () use ($request) {
            return $this->persistInvoice(new PurchaseInvoice(), $request);
        });

        $audit->record('purchase_invoice_created', 'inventory', [
            'subject' => $invoice,
            'after' => $invoice->fresh('items')->toArray(),
            'metadata' => ['summary' => "Purchase invoice {$invoice->invoice_no} created."],
        ]);

        if ($request->input('action') === 'post') {
            $postingService->post($invoice);
            $audit->record('purchase_invoice_posted', 'inventory', [
                'subject' => $invoice->fresh(),
                'after' => $invoice->fresh()->attributesToArray(),
                'metadata' => ['summary' => "Purchase invoice {$invoice->invoice_no} posted."],
            ]);
        }

        return redirect()->route('inventory.purchase-invoices.show', $invoice)->with('success', 'Purchase invoice saved.');
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load('supplier', 'items.stockItem', 'payments.createdBy', 'createdBy', 'postedBy', 'voidedBy');

        return view('modules.inventory.purchase_invoices.show', compact('purchaseInvoice'));
    }

    public function edit(PurchaseInvoice $purchaseInvoice)
    {
        if (!$purchaseInvoice->isDraft()) {
            return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)
                ->with('danger', 'Only draft purchase invoices can be edited.');
        }

        return view('modules.inventory.purchase_invoices.edit', $this->formData($purchaseInvoice->load('items')));
    }

    public function update(StorePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice, PurchasePostingService $postingService, AuditLogger $audit)
    {
        if (!$purchaseInvoice->isDraft()) {
            return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)
                ->with('danger', 'Only draft purchase invoices can be edited.');
        }

        $before = $purchaseInvoice->load('items')->toArray();

        DB::transaction(function () use ($purchaseInvoice, $request) {
            $this->persistInvoice($purchaseInvoice, $request);
        });

        $audit->record('purchase_invoice_updated', 'inventory', [
            'subject' => $purchaseInvoice,
            'before' => $before,
            'after' => $purchaseInvoice->fresh('items')->toArray(),
            'metadata' => ['summary' => "Purchase invoice {$purchaseInvoice->invoice_no} updated."],
        ]);

        if ($request->input('action') === 'post') {
            $postingService->post($purchaseInvoice->fresh());
            $audit->record('purchase_invoice_posted', 'inventory', [
                'subject' => $purchaseInvoice->fresh(),
                'after' => $purchaseInvoice->fresh()->attributesToArray(),
                'metadata' => ['summary' => "Purchase invoice {$purchaseInvoice->invoice_no} posted."],
            ]);
        }

        return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)->with('success', 'Purchase invoice updated.');
    }

    public function destroy(PurchaseInvoice $purchaseInvoice)
    {
        if (!$purchaseInvoice->isDraft()) {
            return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)
                ->with('danger', 'Only draft purchase invoices can be deleted.');
        }

        $purchaseInvoice->delete();

        return redirect()->route('inventory.purchase-invoices.index')->with('danger', 'Draft purchase invoice deleted.');
    }

    public function post(PurchaseInvoice $purchaseInvoice, PurchasePostingService $postingService, AuditLogger $audit)
    {
        $postingService->post($purchaseInvoice);

        $audit->record('purchase_invoice_posted', 'inventory', [
            'subject' => $purchaseInvoice->fresh(),
            'after' => $purchaseInvoice->fresh()->attributesToArray(),
            'metadata' => ['summary' => "Purchase invoice {$purchaseInvoice->invoice_no} posted."],
        ]);

        return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)->with('success', 'Purchase invoice posted and stock updated.');
    }

    public function void(Request $request, PurchaseInvoice $purchaseInvoice, PurchasePostingService $postingService, AuditLogger $audit)
    {
        $request->validate([
            'void_reason' => ['required', 'string', 'min:3'],
        ]);

        $postingService->void($purchaseInvoice, $request->void_reason);

        $audit->record('purchase_invoice_voided', 'inventory', [
            'severity' => 'warning',
            'subject' => $purchaseInvoice->fresh(),
            'after' => $purchaseInvoice->fresh()->attributesToArray(),
            'metadata' => [
                'summary' => "Purchase invoice {$purchaseInvoice->invoice_no} voided.",
                'void_reason' => $request->void_reason,
            ],
        ]);

        return redirect()->route('inventory.purchase-invoices.show', $purchaseInvoice)->with('danger', 'Purchase invoice voided and stock reversed.');
    }

    private function formData(PurchaseInvoice $purchaseInvoice): array
    {
        return [
            'purchaseInvoice' => $purchaseInvoice,
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'stockItems' => StockItem::where('active', true)->orderBy('name')->get(),
            'paymentMethods' => config('pos.purchase_payments'),
        ];
    }

    private function persistInvoice(PurchaseInvoice $invoice, StorePurchaseInvoiceRequest $request): PurchaseInvoice
    {
        $validated = $request->validated();
        $items = collect($validated['items'] ?? [])
            ->filter(fn ($item) => !empty($item['stock_item_id']));

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one purchase item.']);
        }

        $totals = $this->calculateTotals($items->all());
        $paidAmount = min((float) $request->input('paid_amount', 0), $totals['total_amount']);

        if ($paidAmount > 0 && !$request->filled('payment_method')) {
            throw ValidationException::withMessages(['payment_method' => 'Payment method is required when initial payment is entered.']);
        }

        unset($validated['items'], $validated['attachment']);

        $payload = array_merge($validated, $totals, [
            'paid_amount' => $paidAmount,
            'balance_amount' => max($totals['total_amount'] - $paidAmount, 0),
            'payment_status' => $paidAmount >= $totals['total_amount'] ? 'paid' : ($paidAmount > 0 ? 'partial' : 'pending'),
            'created_by' => $invoice->exists ? $invoice->created_by : auth()->id(),
        ]);

        if ($request->hasFile('attachment')) {
            $payload['attachment_path'] = $request->file('attachment')->store('purchase-invoices', 'public');
        }

        $invoice->fill($payload);
        $invoice->save();

        $invoice->items()->delete();
        foreach ($items as $item) {
            $stockItem = StockItem::findOrFail($item['stock_item_id']);
            $lineTotals = $this->calculateLine($item);

            $invoice->items()->create(array_merge($lineTotals, [
                'stock_item_id' => $stockItem->id,
                'item_name' => $stockItem->name,
                'unit' => $stockItem->unit,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $item['discount_amount'] ?? 0,
                'vat_amount' => $item['vat_amount'] ?? 0,
            ]));
        }

        return $invoice;
    }

    private function calculateTotals(array $items): array
    {
        return collect($items)->reduce(function ($carry, $item) {
            $line = $this->calculateLine($item);

            return [
                'subtotal' => $carry['subtotal'] + $line['subtotal'],
                'discount_amount' => $carry['discount_amount'] + $line['discount_amount'],
                'taxable_amount' => $carry['taxable_amount'] + $line['taxable_amount'],
                'vat_amount' => $carry['vat_amount'] + $line['vat_amount'],
                'total_amount' => $carry['total_amount'] + $line['total_amount'],
            ];
        }, [
            'subtotal' => 0,
            'discount_amount' => 0,
            'taxable_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 0,
        ]);
    }

    private function calculateLine(array $item): array
    {
        $subtotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
        $discount = round((float) ($item['discount_amount'] ?? 0), 2);
        $taxable = max($subtotal - $discount, 0);
        $vat = round((float) ($item['vat_amount'] ?? 0), 2);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'taxable_amount' => $taxable,
            'vat_amount' => $vat,
            'total_amount' => $taxable + $vat,
        ];
    }
}
