<x-master-layout>
    @section('title', 'Edit Menu Stock Mapping')

    <div class="max-w-4xl mx-auto">
        @include('modules.inventory.partials.nav')
        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Edit Menu Stock Mapping</h1>
            <form method="POST" action="{{ route('inventory.menu-mappings.update', $mapping) }}">
                @method('PUT')
                @include('modules.inventory.menu_mappings.form')
            </form>
        </div>
    </div>
</x-master-layout>
