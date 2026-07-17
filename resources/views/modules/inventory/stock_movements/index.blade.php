<x-master-layout>
    @section('title', 'Stock Movements')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Stock Movements</h1>
            <a href="{{ route('inventory.stock-movements.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Record Movement</a>
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
