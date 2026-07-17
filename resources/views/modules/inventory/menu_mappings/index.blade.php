<x-master-layout>
    @section('title', 'Menu Stock Mappings')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Menu Stock Mappings</h1>
            <a href="{{ route('inventory.menu-mappings.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Add Mapping</a>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Menu Item</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Stock Item</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty Per Sale</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($mappings as $mapping)
                        <tr>
                            <td class="px-4 py-3 text-sm">{{ $mapping->menu->name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $mapping->stockItem->name }}</td>
                            <td class="px-4 py-3 text-sm text-right">{{ number_format($mapping->quantity_per_sale, 3) }} {{ $mapping->stockItem->unit }}</td>
                            <td class="px-4 py-3 text-sm text-center">{{ $mapping->active ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3 text-sm text-right">
                                <a href="{{ route('inventory.menu-mappings.edit', $mapping) }}" class="text-blue-700 font-semibold">Edit</a>
                                <form method="POST" action="{{ route('inventory.menu-mappings.destroy', $mapping) }}" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-3 text-red-700 font-semibold" onclick="return confirm('Delete this mapping?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $mappings->links() }}</div>
    </div>
</x-master-layout>
