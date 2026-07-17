<x-master-layout>
    @section('title', 'Edit Purchase Invoice')

    <div class="max-w-7xl mx-auto">
        @include('modules.inventory.partials.nav')

        <div class="bg-white rounded shadow p-6">
            <h1 class="text-xl font-semibold mb-4">Edit Purchase Invoice</h1>
            <form method="POST" action="{{ route('inventory.purchase-invoices.update', $purchaseInvoice) }}" enctype="multipart/form-data">
                @method('PUT')
                @include('modules.inventory.purchase_invoices.form')
            </form>
        </div>
    </div>
</x-master-layout>
