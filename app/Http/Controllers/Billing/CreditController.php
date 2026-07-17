<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CreditPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class CreditController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only('settle');
    }

    public function index(Request $request)
    {
        $status = $request->input('status', 'outstanding');
        $search = trim((string) $request->input('search'));

        $bills = Bill::with(['table', 'sourceTable', 'creditPayments.recordedBy'])
            ->where('payment_method', 'credit')
            ->whereNotNull('locked_at')
            ->when($status === 'outstanding', function ($query) {
                $query->where(function ($query) {
                    $query->whereIn('credit_status', ['open', 'partial'])
                        ->orWhereNull('credit_status');
                });
            })
            ->when($status === 'settled', function ($query) {
                $query->where('credit_status', 'settled');
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('credit_customer_name', 'like', "%{$search}%")
                        ->orWhere('credit_customer_contact', 'like', "%{$search}%")
                        ->orWhere('invoice_no', 'like', "%{$search}%")
                        ->orWhere('bill_id', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("CASE WHEN credit_status = 'settled' THEN 1 ELSE 0 END")
            ->orderBy('locked_at')
            ->paginate(25)
            ->withQueryString();

        $paymentMethods = collect(config('pos.payments'))
            ->merge(config('pos.legacy_payments'))
            ->except('credit')
            ->all();

        return view('admin.analytics.credits', compact('bills', 'status', 'search', 'paymentMethods'));
    }

    public function settle(Request $request, Bill $bill, AuditLogger $audit)
    {
        if (!auth()->user()?->canSettleCredit()) {
            abort(403, 'Only Admin, Owner, or Manager can record credit payments.');
        }

        $paymentMethods = array_keys(
            collect(config('pos.payments'))
                ->merge(config('pos.legacy_payments'))
                ->except('credit')
                ->all()
        );
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in($paymentMethods)],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = DB::transaction(function () use ($bill, $data) {
            $bill = Bill::lockForUpdate()->findOrFail($bill->id);

            if ($bill->payment_method !== 'credit' || !$bill->isLocked()) {
                throw ValidationException::withMessages([
                    'bill' => 'Only finalized credit bills can receive credit payments.',
                ]);
            }

            $invoiceDate = ($bill->locked_at ?: $bill->created_at)->startOfDay();
            $paidAt = Carbon::parse($data['paid_at'])->setTimeFrom(now());

            if ($paidAt->lt($invoiceDate)) {
                throw ValidationException::withMessages([
                    'paid_at' => 'Payment date cannot be before the invoice date.',
                ]);
            }

            $balance = $bill->creditBalance();
            $amount = round((float) $data['amount'], 2);

            if ($balance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'This credit bill is already settled.',
                ]);
            }

            if ($amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot be greater than the outstanding balance.',
                ]);
            }

            $payment = CreditPayment::create([
                'bill_id' => $bill->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'paid_at' => $paidAt,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            $paidAmount = round((float) $bill->credit_paid_amount + $amount, 2);
            $settled = $paidAmount >= round(
                (float) $bill->grand_total - (float) $bill->credit_returned_amount,
                2
            );

            $bill->update([
                'credit_paid_amount' => $paidAmount,
                'credit_status' => $settled ? 'settled' : 'partial',
                'credit_settled_at' => $settled ? $paidAt : null,
            ]);

            return $payment;
        });

        $audit->record('credit_payment_recorded', 'billing', [
            'subject' => $payment,
            'after' => $payment->only(['id', 'bill_id', 'amount', 'payment_method', 'paid_at', 'recorded_by']),
            'metadata' => ['summary' => "Credit payment recorded for bill #{$bill->id}."],
        ]);

        return redirect()
            ->route('reporting.credits', $request->only(['status', 'search']))
            ->with('success', 'Credit payment recorded.');
    }
}
