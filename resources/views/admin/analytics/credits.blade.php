<x-master-layout>
    @section('title', 'Credit Ledger')

    <div class="max-w-7xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Credit Ledger</h1>
            <p class="text-sm text-gray-600">Record partial or full collections. Each payment appears in the daily summary on its payment date.</p>
        </div>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-300 text-red-800 rounded p-4">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="GET" action="{{ route('reporting.credits') }}" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                <select id="status" name="status" class="border rounded p-2">
                    <option value="outstanding" @selected($status === 'outstanding')>Outstanding</option>
                    <option value="settled" @selected($status === 'settled')>Settled</option>
                    <option value="all" @selected($status === 'all')>All</option>
                </select>
            </div>
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700">Customer or Invoice</label>
                <input id="search" name="search" value="{{ $search }}" class="border rounded p-2" placeholder="Search">
            </div>
            <button class="bg-green-700 text-white rounded px-4 py-2">Filter</button>
        </form>

        <section class="bg-white border rounded overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Invoice</th>
                        <th class="p-3 text-left">Customer</th>
                        <th class="p-3 text-left">Invoice Date</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-right">Paid</th>
                        <th class="p-3 text-right">Returned</th>
                        <th class="p-3 text-right">Balance</th>
                        <th class="p-3 text-left">Status</th>
                        <th class="p-3 text-left">Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bills as $bill)
                        <tr class="border-t align-top">
                            <td class="p-3">{{ $bill->invoice_no ?: $bill->bill_id }}</td>
                            <td class="p-3">
                                <div class="font-medium">{{ $bill->credit_customer_name }}</div>
                                <div class="text-xs text-gray-500">{{ $bill->credit_customer_contact }}</div>
                            </td>
                            <td class="p-3">{{ ($bill->locked_at ?: $bill->created_at)->format('Y-m-d') }}</td>
                            <td class="p-3 text-right">Rs {{ number_format($bill->grand_total, 2) }}</td>
                            <td class="p-3 text-right">Rs {{ number_format($bill->credit_paid_amount, 2) }}</td>
                            <td class="p-3 text-right">Rs {{ number_format($bill->credit_returned_amount, 2) }}</td>
                            <td class="p-3 text-right font-semibold">Rs {{ number_format($bill->creditBalance(), 2) }}</td>
                            <td class="p-3">{{ ucfirst($bill->credit_status ?: 'open') }}</td>
                            <td class="p-3 min-w-72">
                                @if ($bill->creditBalance() > 0 && auth()->user()->canSettleCredit())
                                    <form method="POST" action="{{ route('reporting.credits.settle', $bill) }}" class="grid grid-cols-2 gap-2">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ $status }}">
                                        <input type="hidden" name="search" value="{{ $search }}">
                                        <input name="amount" type="number" min="0.01" max="{{ $bill->creditBalance() }}" step="0.01" value="{{ $bill->creditBalance() }}" class="border rounded p-2" required>
                                        <select name="payment_method" class="border rounded p-2" required>
                                            @foreach ($paymentMethods as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input name="paid_at" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="border rounded p-2" required>
                                        <input name="reference_no" class="border rounded p-2" placeholder="Reference">
                                        <input name="notes" class="border rounded p-2 col-span-2" placeholder="Notes">
                                        <button class="bg-green-700 text-white rounded px-3 py-2 col-span-2">Record Payment</button>
                                    </form>
                                @endif

                                @if ($bill->creditPayments->isNotEmpty())
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-blue-700">Payment history</summary>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($bill->creditPayments->sortByDesc('paid_at') as $payment)
                                                <div class="border-t pt-2 text-xs">
                                                    <strong>Rs {{ number_format($payment->amount, 2) }}</strong>
                                                    via {{ config('pos.payments.' . $payment->payment_method) ?? config('pos.legacy_payments.' . $payment->payment_method) ?? $payment->payment_method }}
                                                    on {{ $payment->paid_at->format('Y-m-d') }}
                                                    <div class="text-gray-500">{{ $payment->reference_no }} {{ $payment->recordedBy?->name ? '- ' . $payment->recordedBy->name : '' }}</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="p-4 text-center text-gray-500">No credit bills found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{ $bills->links() }}
    </div>
</x-master-layout>
