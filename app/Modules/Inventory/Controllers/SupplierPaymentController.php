<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Inventory\Requests\StoreSupplierPaymentRequest;
use App\Modules\Inventory\Services\PurchasePostingService;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class SupplierPaymentController extends Controller
{
    public function index()
    {
        $payments = SupplierPayment::with('supplier', 'purchaseInvoice', 'createdBy')
            ->latest('payment_date')
            ->latest()
            ->paginate(50);

        return view('modules.inventory.supplier_payments.index', compact('payments'));
    }

    public function create()
    {
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();
        $purchaseInvoices = PurchaseInvoice::with('supplier')
            ->where('status', 'posted')
            ->where('balance_amount', '>', 0)
            ->orderBy('bill_date')
            ->get();

        return view('modules.inventory.supplier_payments.create', [
            'suppliers' => $suppliers,
            'purchaseInvoices' => $purchaseInvoices,
            'paymentMethods' => config('pos.purchase_payments'),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request, PurchasePostingService $postingService, AuditLogger $audit)
    {
        $payload = array_merge($request->validated(), ['created_by' => auth()->id()]);
        $payment = DB::transaction(function () use ($payload, $postingService) {
            $invoice = null;

            if (!empty($payload['purchase_invoice_id'])) {
                $invoice = PurchaseInvoice::whereKey($payload['purchase_invoice_id'])->where('supplier_id', $payload['supplier_id'])
                    ->lockForUpdate()->first();

                if (!$invoice) {
                    throw ValidationException::withMessages(['purchase_invoice_id' => 'Selected invoice does not belong to the supplier.']);
                }

                if ($invoice->status !== 'posted') {
                    throw ValidationException::withMessages(['purchase_invoice_id' => 'Payments require a posted purchase invoice.']);
                }

                $postingService->refreshPaymentStatus($invoice);

                if ((float) $payload['amount'] > (float) $invoice->balance_amount) {
                    throw ValidationException::withMessages(['amount' => 'Payment cannot exceed invoice balance.']);
                }
            }

            $payment = SupplierPayment::create($payload);

            if ($invoice) {
                $postingService->refreshPaymentStatus($invoice);
            }
            return $payment;
        });

        $audit->record('supplier_payment_recorded', 'inventory', [
            'subject' => $payment,
            'after' => $payment->attributesToArray(),
            'metadata' => ['summary' => "Supplier payment #{$payment->id} recorded."],
        ]);

        return redirect()->route('inventory.supplier-payments.index')->with('success', 'Supplier payment recorded.');
    }
}
