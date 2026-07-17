<x-master-layout>
    @section('title', 'Edit Supplier')

    <div class="max-w-5xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Edit Supplier</h1>
            <form method="POST" action="{{ route('inventory.suppliers.update', $supplier) }}">
                @method('PUT')
                @include('modules.inventory.suppliers.form')
            </form>
        </div>
    </div>
</x-master-layout>
