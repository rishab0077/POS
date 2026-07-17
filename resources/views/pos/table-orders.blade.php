<x-pos-layout>
    @section('title', 'View Table Orders')
    <style>
        .waiter-orders {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;

        }
    </style>

    @php
        $currentOrderComponent = 'order-running-component-for-admin';
    @endphp
    <div class="container">

        <div class="table-box">
            <h2 class="text-2xl font-semibold w-full align-top text-center sticky">Active Orders Table
                {{ $table->name }}</h2>
            <input type="text" name="tableId" value="{{ $table->id }}" hidden>
            @foreach ($orders->groupBy(fn ($order) => $order->source_table_id ?: $order->table_id) as $sourceTableId => $sourceOrders)
                @php
                    $sourceTableName = $sourceOrders->first()->sourceTable?->name ?? $table->name;
                    $ordersByWaiter = $sourceOrders->groupBy('waiter_id');
                @endphp
                <div class="mt-4">
                    <h3 class="text-lg font-semibold text-gray-700">Original Table: {{ $sourceTableName }}</h3>
                    <div class="table-orders flex gap-4">
                        @foreach ($ordersByWaiter as $waiterId => $waiterOrders)
                            <div class="waiter-orders">
                                @foreach ($waiterOrders as $order)
                                    <x-dynamic-component :component="$currentOrderComponent" :order="$order" />
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>


    </div>

</x-pos-layout>
