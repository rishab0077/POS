<x-master-layout>
    @section('title', 'Stock Movements')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Stock Movements</h1>
            <a href="{{ route('inventory.stock-movements.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Record Movement</a>
        </div>

        <div class="mb-6 bg-white rounded shadow overflow-x-auto">
            <div class="p-4 border-b">
                <h2 class="font-semibold text-gray-900">Returned Items</h2>
                <p class="text-sm text-gray-600">Returns default to waste and do not increase stock. Restore only unopened or genuinely reusable stock.</p>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Return</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Potential stock</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Disposition</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($returnedItems as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm">
                                <div class="font-medium">{{ $item->creditNote->credit_note_no }}</div>
                                <div class="text-xs text-gray-500">Invoice {{ $item->creditNote->snapshot->invoice_no }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm">{{ number_format((float) $item->quantity, 3) }} × {{ $item->item_name }}</td>
                            <td class="px-4 py-3 text-sm">
                                @forelse ($item->inventory_restore_quantities ?? [] as $restore)
                                    <div>{{ number_format((float) $restore['quantity'], 3) }} {{ $restore['unit'] }} {{ $restore['stock_item_name'] }}</div>
                                @empty
                                    <span class="text-gray-500">No deduction snapshot</span>
                                @endforelse
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if ($item->inventory_restored_at)
                                    <span class="font-semibold text-green-700">Restored</span>
                                    <div class="text-xs text-gray-500">{{ $item->inventory_restored_at->format('Y-m-d H:i') }} by {{ $item->inventoryRestoredBy?->name ?? 'System' }}</div>
                                @else
                                    <span class="font-semibold text-amber-700">Waste (default)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if (!$item->inventory_restored_at && !empty($item->inventory_restore_quantities))
                                    <form method="POST" action="{{ route('inventory.returned-items.restore', $item) }}" onsubmit="return confirm('Restore this reusable return to stock? This cannot be undone here.')">
                                        @csrf
                                        <button class="px-3 py-2 text-white bg-green-700 rounded">Restore reusable stock</button>
                                    </form>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-4 text-center text-sm text-gray-500">No returned items recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($movements as $movement)
                        <tr>
                            <td class="px-4 py-3 text-sm">{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm">{{ $movement->stockItem->name }}</td>
                            <td class="px-4 py-3 text-sm">{{ str_replace('_', ' ', $movement->movement_type) }}</td>
                            <td class="px-4 py-3 text-sm text-right">{{ number_format($movement->quantity, 3) }}</td>
                            <td class="px-4 py-3 text-sm">{{ $movement->notes }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $movements->links() }}</div>
    </div>
</x-master-layout>
