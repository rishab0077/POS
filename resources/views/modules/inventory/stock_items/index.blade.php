<x-master-layout>
    @section('title', 'Stock Items')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Stock Items</h1>
            <a href="{{ route('inventory.stock-items.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Add Stock Item</a>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Avg Cost</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Auto</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($stockItems as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $item->name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $item->category->name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $item->defaultSupplier->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-right">{{ number_format($item->current_quantity, 3) }} {{ $item->unit }}</td>
                            <td class="px-4 py-3 text-sm text-right">NPR {{ number_format($item->average_unit_cost, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-center">{{ $item->auto_deduct ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3 text-sm text-right">
                                <a href="{{ route('inventory.stock-items.edit', $item) }}"
                                    class="text-blue-700 font-semibold">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $stockItems->links() }}</div>
    </div>
</x-master-layout>
