@php
    $itemOrder = $orderDetail->order;
    $canAdd = $itemOrder->table_id && (auth()->user()?->isWaiter() || auth()->user()?->canUsePos());
@endphp
<div class="inline-flex items-center gap-2" data-sent-item data-menu-id="{{ $orderDetail->menu_id }}">
    <button type="button" data-cancel-one aria-label="Cancel one {{ $orderDetail->menu?->name }}"
        class="h-9 w-9 rounded bg-red-600 font-bold text-white">−</button>
    <span>{{ $orderDetail->quantity }}</span>
    @if ($canAdd && $orderDetail->menu)
        @if (($cart ?? null) === 'waiter')
            <button type="button" aria-label="Add one {{ $orderDetail->menu->name }}"
                @click="updateQuantity({{ $orderDetail->menu_id }}, 1, {{ Js::from($orderDetail->menu->name) }}, {{ (float) $orderDetail->menu->price }})"
                class="h-9 w-9 rounded bg-green-600 font-bold text-white">+</button>
        @elseif (($cart ?? null) === 'pos')
            <button type="button" aria-label="Add one {{ $orderDetail->menu->name }}"
                onclick="addItemToOrder({{ $orderDetail->menu_id }})"
                class="h-9 w-9 rounded bg-green-600 font-bold text-white">+</button>
        @else
            <button type="button" data-add-one data-menu-id="{{ $orderDetail->menu_id }}"
                data-table-id="{{ $itemOrder->table_id }}" data-name="{{ $orderDetail->menu->name }}"
                data-source="{{ auth()->user()->isWaiter() ? 'waiter' : 'pos' }}"
                data-url="{{ route('order.submit', [], false) }}"
                aria-label="Add one {{ $orderDetail->menu->name }} as a new KOT"
                class="h-9 w-9 rounded bg-green-600 font-bold text-white">+</button>
        @endif
    @endif
    <dialog class="w-11/12 max-w-md rounded-lg bg-white p-4 text-gray-900 shadow-xl" aria-label="Cancel one item">
        <form data-order-cancellation data-item="{{ $orderDetail->menu?->name }}" data-kot="{{ $itemOrder->KOT }}"
            action="{{ route('order.cancel', [], false) }}" method="POST" class="space-y-3">
            @csrf
            <input type="hidden" name="orderId" value="{{ $itemOrder->id }}">
            <input type="hidden" name="orderDetailId" value="{{ $orderDetail->id }}">
            <input type="hidden" name="expectedQuantity" value="{{ $orderDetail->quantity }}">
            <h3 class="font-bold">Cancel one {{ $orderDetail->menu?->name }}?</h3>
            <p class="text-sm">{{ $itemOrder->KOT }}. Notify the kitchen if already printed or prepared.</p>
            <label class="block">Reason
                <select name="reason" required class="block w-full rounded text-gray-900">
                    <option value="">Choose a reason</option>
                    @foreach (config('pos.cancellation_reasons') as $reason)
                        <option value="{{ $reason }}">{{ $reason }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">Notes (optional)
                <textarea name="notes" maxlength="500" class="block w-full rounded text-gray-900"></textarea>
            </label>
            <p data-cancellation-error role="alert" class="text-sm text-red-700"></p>
            <button type="button" data-close-cancellation class="rounded bg-gray-200 p-2">Keep item</button>
            <button type="submit" class="rounded bg-red-600 p-2 text-white">Confirm cancellation</button>
        </form>
    </dialog>
</div>
