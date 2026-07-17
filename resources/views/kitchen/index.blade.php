<x-kitchen-layout>
    @section('title', 'Kitchen')

    <div class="flex flex-col h-screen bg-gray-200 font-sans">
        <header class="flex justify-between items-center bg-white shadow-md p-2">
            <h1 class="text-2xl font-bold text-gray-800 px-4">Kitchen Display</h1>
            <x-user-dropdown />
        </header>

        <div class="flex flex-1 min-h-0 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">

            <section class="w-full min-w-0 bg-gray-100 border-r border-gray-300 flex flex-col lg:w-1/4">
                <div class="p-4 border-b border-gray-300 bg-white">
                    <h2 class="text-xl font-bold text-center text-gray-800">Pending KOTs</h2>
                </div>
                <div class="pending-orders flex flex-1 min-h-0 flex-col gap-3 overflow-y-auto p-3">
                    @foreach ($newOrders as $order)
                        <x-new-order-component-for-kitchen :order="$order" />
                    @endforeach
                </div>
            </section>

            <section class="w-full min-w-0 bg-white flex flex-col lg:w-1/2">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-xl font-bold text-center text-gray-800">In Progress</h2>
                </div>
                <div class="processing-orders flex-1 overflow-y-auto p-3">
                    <div class="processing-orders-grid grid grid-cols-1 gap-3 content-start xl:grid-cols-2">
                        @foreach ($processingOrders as $order)
                            <x-order-processing-component-for-kitchen :order="$order" />
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="w-full min-w-0 bg-gray-100 border-l border-gray-300 flex flex-col lg:w-1/4">
                <div class="p-4 border-b border-gray-300 bg-white">
                    <h2 class="text-xl font-bold text-center text-gray-800">Live Item Counts</h2>
                </div>
                <div class="items-counts flex-1 overflow-y-auto p-3 space-y-4">
                    <div class="dine-in bg-white border border-gray-200 rounded-lg shadow-sm">
                        <h3 class="text-lg font-semibold p-3 bg-gray-50 rounded-t-lg border-b">Dine-In Items</h3>
                        <div class="p-3 items space-y-1">
                            {{-- JS will populate this --}}
                        </div>
                    </div>
                    <div class="take-away bg-white border border-gray-200 rounded-lg shadow-sm">
                        <h3 class="text-lg font-semibold p-3 bg-gray-50 rounded-t-lg border-b">Take Away Items</h3>
                        <div class="p-3 items space-y-1">
                            {{-- JS will populate this --}}
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // WebSockets are primary. Polling is a reliability fallback for
            // proxy, network, queue, or Soketi interruptions.
            window.setInterval(pollPendingOrders, {{ $pollIntervalSeconds * 1000 }});

            if (window.Echo) {
                try {
                    window.Echo.private('order-submitted-to-kitchen')
                        .listen('.OrderSubmittedToKitchen', (event) => {
                            console.log('KOT submitted to kitchen:', event.kot);
                            fetchPendingOrderByKot(event.kot);
                        });
                } catch (error) {
                    console.error('Kitchen WebSocket subscription failed; polling remains active', error);
                }
            }

            calculateMenuSumByType();
        });

        function appendPendingOrder(order) {
            if (!order || !order.id || document.getElementById('order' + order.id)) {
                return;
            }

            $('.pending-orders').append(order.html);
            console.log('Order added:', order.kot);
        }

        function reconcileOrderColumn(containerSelector, orders) {
            const container = $(containerSelector);
            const serverIds = new Set(
                (orders || []).map(order => String(order.id))
            );

            container.children('.order-item').each(function() {
                const orderId = this.id.replace('order', '');

                if (!serverIds.has(orderId)) {
                    $(this).remove();
                }
            });

            (orders || []).forEach(function(order) {
                if (!document.getElementById('order' + order.id)) {
                    container.append(order.html);
                }
            });
        }

        function fetchPendingOrderByKot(kot) {
            const url = '{{ route('kitchen.get.new.order.component', [], false) }}';
            const csrfToken = "{{ csrf_token() }}";

            $.ajax({
                type: "POST",
                url: url,
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                data: {
                    kot: kot
                },
                contentType: 'application/x-www-form-urlencoded',
                success: appendPendingOrder,
                error: function(error) {
                    console.error('Unable to fetch WebSocket order component', error);
                }
            });
        }

        function pollPendingOrders() {
            $.ajax({
                type: "GET",
                url: '{{ route('kitchen.pending.orders', [], false) }}',
                success: function(response) {
                    reconcileOrderColumn('.pending-orders', response.orders);
                    reconcileOrderColumn('.processing-orders-grid', response.processingOrders);
                    calculateMenuSumByType();
                },
                error: function(error) {
                    console.error('Kitchen fallback polling failed', error);
                }
            });
        }

        function acceptOrder(orderId) {
            const url = '{{ route('kitchen.accept.order', [], false) }}';
            var csrf_token = "{{ csrf_token() }}";
            showLoader();
            $.ajax({
                type: "POST",
                url: url,
                headers: {
                    'X-CSRF-TOKEN': csrf_token
                },
                data: {
                    orderId: orderId
                },
                contentType: 'application/x-www-form-urlencoded',
                success: function(response) {
                    var order = $('.pending-orders > #order' + orderId).clone();
                    order.find('.options').children().last().remove();
                    var firstChilderen = order.find('.options').children().first();
                    firstChilderen.attr("id", "completeOrder");
                    firstChilderen.text("Completed");
                    firstChilderen.attr("class",
                        "py-3 px-6 font-bold text-white bg-green-600 hover:bg-green-700 transition-colors rounded-br-lg"
                    );
                    firstChilderen.attr("onclick", "completeOrder(" + orderId + ")");
                    $('.processing-orders-grid').append(order);
                    $('.pending-orders > #order' + orderId).remove();
                    console.log('Order accepted');
                    calculateMenuSumByType();
                },
                error: function(error) {
                    console.error('Error ', error);
                },
                complete: function() {
                    hideLoader();
                }
            });
        }

        function discardOrder(orderId) {
            const url = '{{ route('kitchen.discard.order', [], false) }}';
            var csrf_token = "{{ csrf_token() }}";
            const allowedReasons = @json(config('pos.cancellation_reasons')).join("\n");
            const reason = prompt("Cancellation reason required:\n" + allowedReasons);

            if (!reason) {
                return;
            }

            const notes = reason.toLowerCase() === "other" ? (prompt("Cancellation notes") || "") : "";
            showLoader();
            $.ajax({
                type: "POST",
                url: url,
                headers: {
                    'X-CSRF-TOKEN': csrf_token
                },
                data: {
                    orderId: orderId,
                    reason: reason,
                    notes: notes
                },
                contentType: 'application/x-www-form-urlencoded',
                success: function(response) {
                    $('#order' + orderId).remove();
                    calculateMenuSumByType();
                    console.log('Order cancelled');
                },
                error: function(error) {
                    console.error('Error ', error);
                },
                complete: function() {
                    hideLoader();
                }
            });
        }

        function completeOrder(orderId) {
            const url = '{{ route('kitchen.complete.order', [], false) }}';
            var csrf_token = "{{ csrf_token() }}";
            showLoader();
            $.ajax({
                type: "POST",
                url: url,
                headers: {
                    'X-CSRF-TOKEN': csrf_token
                },
                data: {
                    orderId: orderId
                },
                contentType: 'application/x-www-form-urlencoded',
                success: function(response) {
                    $('#order' + orderId).remove();
                    console.log('Order No ' + orderId + ' marked as completed');
                    calculateMenuSumByType();
                },
                error: function(error) {
                    console.error('Error ', error);
                },
                complete: function() {
                    hideLoader();
                }
            });
        }

        function calculateMenuSumByType() {
            const dineInSum = {};
            const takeawaySum = {};

            // Iterate over each order card currently in the "In Progress" column
            $('.processing-orders .order-item').each(function() {
                const orderElement = $(this);

                // Check the data attribute to determine if it's dine-in or takeaway
                const isDineIn = orderElement.data('order-type') === 'dine_in';

                // Find each item row within the order card
                orderElement.find('.order-details-list > div').each(function() {
                    const itemRow = $(this);
                    const quantitySpan = itemRow.find('span:first-child');
                    const nameSpan = itemRow.find('span:last-child');

                    console.log('Processing item:', quantitySpan.text(), nameSpan.text());


                    if (quantitySpan.length && nameSpan.length) {
                        // Extract quantity by removing 'x' and parsing as an integer
                        const quantity = parseInt(quantitySpan.text().replace('x', ''), 10);
                        const menuItem = nameSpan.text().trim();

                        if (!isNaN(quantity) && menuItem) {
                            if (isDineIn) {
                                dineInSum[menuItem] = (dineInSum[menuItem] || 0) + quantity;
                            } else {
                                takeawaySum[menuItem] = (takeawaySum[menuItem] || 0) + quantity;
                            }
                        }
                    }
                });
            });

            // The existing updateMenuSumView function will now work correctly
            updateMenuSumView('.dine-in > .items', dineInSum);
            updateMenuSumView('.take-away > .items', takeawaySum);
        }

        function updateMenuSumView(containerSelector, sumObject) {
            const container = $(containerSelector).empty();
            if (Object.keys(sumObject).length === 0) {
                container.append('<p class="text-sm text-gray-500">No items.</p>');
                return;
            }
            Object.entries(sumObject).forEach(([menuItem, sum]) => {
                container.append(
                    `<p class="text-sm text-gray-800"><span class="font-bold">${sum}</span> x ${menuItem}</p>`);
            });
        }
    </script>
</x-kitchen-layout>
