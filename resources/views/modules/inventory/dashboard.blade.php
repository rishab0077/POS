<x-master-layout>
    @section('title', 'Inventory Dashboard')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow">
            <div class="p-4 border-b">
                <h1 class="text-xl font-semibold text-gray-900">Low Stock Alerts</h1>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Current</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Threshold</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($lowStockItems as $item)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $item->name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $item->category->name }}</td>
                                <td class="px-4 py-3 text-sm text-right text-red-700 font-semibold">
                                    {{ number_format($item->current_quantity, 3) }} {{ $item->unit }}
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">
                                    {{ number_format($item->low_stock_threshold, 3) }} {{ $item->unit }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No low stock items.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-master-layout>
