<x-master-layout>
    @section('title', 'Suppliers')

    <div class="max-w-6xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="flex justify-between items-center mb-4">
            <h1 class="text-xl font-semibold text-gray-900">Suppliers</h1>
            <a href="{{ route('inventory.suppliers.create') }}"
                class="px-4 py-2 bg-green-600 text-white rounded font-semibold">Add Supplier</a>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">VAT/PAN</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ $supplier->name }}
                                <div class="text-xs text-gray-500">{{ $supplier->purchase_invoices_count }} invoices</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                {{ $supplier->contact_person ?: '-' }}
                                <div class="text-xs text-gray-500">{{ $supplier->phone ?: '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $supplier->vat_pan_no ?: '-' }}</td>
                            <td class="px-4 py-3 text-sm text-right font-semibold">Rs {{ number_format($supplier->balance(), 2) }}</td>
                            <td class="px-4 py-3 text-sm text-center">{{ $supplier->active ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3 text-sm text-right">
                                <a href="{{ route('inventory.suppliers.edit', $supplier) }}" class="text-blue-700 font-semibold">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No suppliers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $suppliers->links() }}</div>
    </div>
</x-master-layout>
