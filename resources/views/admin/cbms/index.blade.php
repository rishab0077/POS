<x-master-layout>
    @section('title', 'CBMS Status')

    <div class="space-y-6">
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">IRD CBMS Status</h1>
                <p class="text-sm text-gray-600">Sales invoices and partial or full credit notes. Issued fiscal records are never rewritten.</p>
            </div>
            <span class="px-3 py-1 text-sm font-semibold rounded {{ $ready ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-900' }}">
                {{ $ready ? 'Enabled and configured' : ($enabled ? 'Credentials missing' : 'Disabled') }}
            </span>
        </div>

        @if ($errors->any())
            <div class="p-4 text-sm text-red-800 bg-red-100 border border-red-200 rounded">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($counts as $status => $count)
                <a href="{{ route('admin.cbms.index', ['status' => $status]) }}" class="p-4 bg-white border border-gray-200 rounded">
                    <div class="text-sm text-gray-500">{{ ucfirst($status) }}</div>
                    <div class="text-2xl font-semibold text-gray-900">{{ $count }}</div>
                </a>
            @endforeach
        </section>

        <form method="GET" action="{{ route('admin.cbms.index') }}" class="grid gap-3 p-4 bg-white border border-gray-200 rounded md:grid-cols-4">
            <select name="status" class="p-2 border rounded">
                <option value="">All statuses</option>
                @foreach (['pending', 'submitting', 'failed', 'submitted'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <input name="search" value="{{ request('search') }}" class="p-2 border rounded md:col-span-2" placeholder="Invoice, buyer, or PAN">
            <div class="flex gap-2">
                <button class="px-4 py-2 text-white bg-blue-700 rounded">Filter</button>
                <a href="{{ route('admin.cbms.index') }}" class="px-4 py-2 bg-gray-100 rounded">Clear</a>
            </div>
        </form>

        <section class="overflow-x-auto bg-white border border-gray-200 rounded">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 text-left">Invoice</th>
                        <th class="p-3 text-left">Buyer</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-left">Status</th>
                        <th class="p-3 text-left">Attempts / response</th>
                        <th class="p-3 text-left">Last activity</th>
                        <th class="p-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($submissions as $submission)
                        @php
                            $snapshot = $submission->snapshot;
                            $statusClass = match ($submission->status) {
                                'submitted' => 'bg-green-100 text-green-800',
                                'failed' => 'bg-red-100 text-red-800',
                                'submitting' => 'bg-blue-100 text-blue-800',
                                default => 'bg-yellow-100 text-yellow-900',
                            };
                            $returnedByItem = $snapshot->creditNotes->flatMap->items
                                ->groupBy('fiscal_invoice_item_id')
                                ->map(fn ($items) => (float) $items->sum('quantity'));
                            $returnableItems = $snapshot->items->filter(fn ($item) =>
                                (float) $item->quantity - (float) $returnedByItem->get($item->id, 0) > 0.0005
                            );
                        @endphp
                        <tr class="align-top border-t">
                            <td class="p-3 whitespace-nowrap">
                                <div class="font-semibold">{{ $snapshot->invoice_no }}</div>
                                <div class="text-xs text-gray-500">{{ $snapshot->invoice_at->format('Y-m-d H:i:s') }}</div>
                            </td>
                            <td class="p-3">
                                <div>{{ $snapshot->buyer_name ?: 'Walk-in customer' }}</div>
                                <div class="text-xs text-gray-500">{{ $snapshot->buyer_pan ?: 'No buyer PAN' }}</div>
                            </td>
                            <td class="p-3 font-semibold text-right whitespace-nowrap">NPR {{ number_format((float) $snapshot->total_sales, 2) }}</td>
                            <td class="p-3"><span class="px-2 py-1 text-xs font-semibold rounded {{ $statusClass }}">{{ ucfirst($submission->status) }}</span></td>
                            <td class="p-3">
                                <div>{{ $submission->attempts }} attempt(s)</div>
                                <div class="text-xs text-gray-500">Response: {{ $submission->response_code ?: '-' }}</div>
                                @if ($submission->last_error)
                                    <div class="mt-1 text-xs text-red-700 break-words max-w-xs">{{ $submission->last_error }}</div>
                                @endif
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                <div>{{ $submission->submitted_at?->format('Y-m-d H:i:s') ?: $submission->last_attempt_at?->format('Y-m-d H:i:s') ?: 'Not attempted' }}</div>
                            </td>
                            <td class="p-3">
                                @if ($submission->status === 'failed' && $ready)
                                    <form method="POST" action="{{ route('admin.cbms.retry', $submission) }}">
                                        @csrf
                                        <button class="px-3 py-2 text-white bg-red-700 rounded">Retry</button>
                                    </form>
                                @elseif ($submission->status === 'submitted' && $returnableItems->isNotEmpty())
                                    <details class="w-64">
                                        <summary class="text-red-700 cursor-pointer">Issue credit note</summary>
                                         <form method="POST" action="{{ route('admin.cbms.credit-note.issue', $submission) }}" class="mt-2 space-y-2">
                                             @csrf
                                             @if ($snapshot->bill->payments->isNotEmpty())
                                                 <div class="p-2 text-xs bg-gray-50 border rounded">
                                                     <strong>Original payment:</strong>
                                                     {{ $snapshot->bill->payments->map(fn ($payment) => config('pos.payments.' . $payment->payment_method, ucfirst($payment->payment_method)) . ' NPR ' . number_format($payment->amount, 2))->join(' + ') }}
                                                 </div>
                                             @endif
                                            @foreach ($returnableItems as $item)
                                                @php $remaining = round((float) $item->quantity - (float) $returnedByItem->get($item->id, 0), 3); @endphp
                                                <label class="block text-xs">
                                                    <span>{{ $item->item_name }} (max {{ $remaining }})</span>
                                                    <input name="items[{{ $item->id }}]" type="number" min="0" max="{{ $remaining }}" step="0.001" value="0" class="w-full p-2 border rounded">
                                                </label>
                                            @endforeach
                                            <textarea name="reason" required maxlength="500" class="w-full p-2 border rounded" placeholder="Required return reason"></textarea>
                                            <select name="refund_method" required class="w-full p-2 border rounded">
                                                @if ($snapshot->payment_method === 'credit')
                                                    <option value="credit">Reduce receivable only</option>
                                                @endif
                                                @foreach (['cash', 'card', 'esewa', 'fonepay', 'khalti'] as $method)
                                                    <option value="{{ $method }}">{{ ucfirst($method) }}</option>
                                                @endforeach
                                            </select>
                                            <input name="refund_reference" maxlength="100" class="w-full p-2 border rounded" placeholder="Refund reference (optional)">
                                            <button class="px-3 py-2 text-white bg-red-700 rounded">Confirm return</button>
                                            <p class="text-xs text-gray-500">Enter returned quantities. This cannot be undone and food stock is not restored.</p>
                                        </form>
                                    </details>
                                    @if ($snapshot->creditNotes->isNotEmpty())
                                        <div class="mt-2 text-xs text-gray-500">Existing: {{ $snapshot->creditNotes->pluck('credit_note_no')->join(', ') }}</div>
                                    @endif
                                @elseif ($submission->status === 'submitted' && $returnableItems->isEmpty())
                                    <span class="text-xs font-semibold text-gray-600">Fully returned</span>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-4 text-center text-gray-500">No CBMS submissions match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{ $submissions->links() }}

        <section class="space-y-3">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">Credit notes</h2>
                <p class="text-sm text-gray-600">Latest full-invoice returns submitted to the IRD bill-return endpoint.</p>
            </div>
            <div class="overflow-x-auto bg-white border border-gray-200 rounded">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3 text-left">Credit note / invoice</th>
                            <th class="p-3 text-left">Reason / refund</th>
                            <th class="p-3 text-right">Total</th>
                            <th class="p-3 text-left">Status</th>
                            <th class="p-3 text-left">Attempts / response</th>
                            <th class="p-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($creditNotes as $creditNote)
                            @php
                                $creditStatusClass = match ($creditNote->status) {
                                    'submitted' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'submitting' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-yellow-100 text-yellow-900',
                                };
                            @endphp
                            <tr class="align-top border-t">
                                <td class="p-3 whitespace-nowrap">
                                    <div class="font-semibold">{{ $creditNote->credit_note_no }}</div>
                                    <div class="text-xs text-gray-500">Ref: {{ $creditNote->snapshot->invoice_no }}</div>
                                </td>
                                <td class="p-3">
                                    <div>{{ $creditNote->reason }}</div>
                                    @foreach ($creditNote->transactions as $transaction)
                                        <div class="text-xs text-gray-500">
                                            {{ $transaction->type === 'receivable_reversal' ? 'Receivable reversed' : 'Payment refunded via ' . ucfirst($transaction->payment_method) }}:
                                            NPR {{ number_format((float) $transaction->amount, 2) }}
                                        </div>
                                    @endforeach
                                </td>
                                <td class="p-3 font-semibold text-right whitespace-nowrap">NPR {{ number_format((float) $creditNote->total_sales, 2) }}</td>
                                <td class="p-3"><span class="px-2 py-1 text-xs font-semibold rounded {{ $creditStatusClass }}">{{ ucfirst($creditNote->status) }}</span></td>
                                <td class="p-3">
                                    <div>{{ $creditNote->attempts }} attempt(s)</div>
                                    <div class="text-xs text-gray-500">Response: {{ $creditNote->response_code ?: '-' }}</div>
                                    @if ($creditNote->last_error)
                                        <div class="mt-1 text-xs text-red-700 break-words max-w-xs">{{ $creditNote->last_error }}</div>
                                    @endif
                                </td>
                                <td class="p-3">
                                    <a href="{{ route('admin.cbms.credit-note.print', $creditNote) }}" target="_blank" class="mr-2 text-blue-700">Print</a>
                                    @if ($creditNote->status === 'failed' && $ready)
                                        <form method="POST" action="{{ route('admin.cbms.credit-note.retry', $creditNote) }}">
                                            @csrf
                                            <button class="px-3 py-2 text-white bg-red-700 rounded">Retry</button>
                                        </form>
                                    @else
                                        <span class="text-gray-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-4 text-center text-gray-500">No credit notes have been issued.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-master-layout>
