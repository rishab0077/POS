@if (auth()->user()?->canViewOperations() && !in_array($order->status, [App\Enums\OrderStatus::Closed, App\Enums\OrderStatus::Cancelled], true))
    <details class="mt-3 rounded border border-red-400 p-2">
        <summary class="cursor-pointer font-semibold text-red-300">Cancel order</summary>
        <form class="mt-3 space-y-2" data-order-cancellation data-kot="{{ $order->KOT }}"
            action="{{ route('order.cancel', [], false) }}" method="POST">
            @csrf
            <input type="hidden" name="orderId" value="{{ $order->id }}">
            <p class="text-sm">Cancels every item on this ticket. Final invoices must use returns.</p>
            <label class="block" for="cancel-reason-{{ $order->id }}">Reason</label>
            <select id="cancel-reason-{{ $order->id }}" name="reason" required class="w-full rounded text-gray-900">
                <option value="">Choose a reason</option>
                @foreach (config('pos.cancellation_reasons') as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                @endforeach
            </select>
            <label class="block" for="cancel-notes-{{ $order->id }}">Notes (optional)</label>
            <textarea id="cancel-notes-{{ $order->id }}" name="notes" maxlength="500"
                class="w-full rounded text-gray-900" rows="2"></textarea>
            <p data-cancellation-error role="alert" class="text-sm text-red-300"></p>
            <button type="submit" class="w-full rounded bg-red-700 p-2 font-semibold text-white">Cancel whole ticket</button>
        </form>
    </details>
@endif
