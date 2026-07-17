<x-master-layout>
    @section('title', 'Add Purchase Invoice')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Add Purchase Invoice</h1>
            <form method="POST" action="{{ route('inventory.purchase-invoices.store') }}" enctype="multipart/form-data">
                @include('modules.inventory.purchase_invoices.form')
            </form>
        </div>
    </div>
</x-master-layout>
