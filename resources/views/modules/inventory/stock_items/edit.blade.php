<x-master-layout>
    @section('title', 'Edit Stock Item')

    <div class="max-w-4xl mx-auto">
        @include('modules.inventory.partials.nav')
        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Edit Stock Item</h1>
            <form method="POST" action="{{ route('inventory.stock-items.update', $stockItem) }}">
                @method('PUT')
                @include('modules.inventory.stock_items.form')
            </form>
        </div>
    </div>
</x-master-layout>
