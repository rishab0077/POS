<x-master-layout>
    @section('title', 'Add Supplier')

    <div class="max-w-5xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Add Supplier</h1>
            <form method="POST" action="{{ route('inventory.suppliers.store') }}">
                @include('modules.inventory.suppliers.form')
            </form>
        </div>
    </div>
</x-master-layout>
