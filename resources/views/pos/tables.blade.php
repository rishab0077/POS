<x-pos-layout>

    @section('title', 'Choose Table')

    <div class="p-4 sm:p-6 lg:p-8 bg-gray-50 min-h-screen font-sans">

        <header class="flex flex-col sm:flex-row justify-between items-center mb-4 gap-4">
            <h1 class="text-3xl font-bold text-gray-900">Table View</h1>
            <div class="flex items-center gap-3">
                <button onclick="location.reload()"
                    class="flex items-center justify-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-700 font-semibold hover:bg-gray-100 transition-colors shadow-sm">
                    <i class="fa fa-refresh mr-2"></i>Refresh
                </button>
                <button id="Takeaway"
                    class="flex items-center justify-center px-4 py-2 bg-green-600 rounded-lg text-white font-semibold hover:bg-green-700 transition-colors shadow-sm">
                    Pick Up
                </button>
            </div>
        </header>

        <div class="flex flex-wrap justify-end items-center mb-8 gap-x-6 gap-y-2">
            @foreach ($table_colors as $status => $color)
                <div class="flex items-center gap-2 text-sm text-gray-600 font-medium">
                    <span class="w-4 h-4 rounded-full" style="background-color: {{ $color }};"></span>
                    <span>{{ $status === 'printed' ? 'Finalized' : ucfirst($status) }}</span>
                </div>
            @endforeach
        </div>

        <div class="space-y-10">
            @foreach ($tablesWithLocations as $location => $tables)
                <div>
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4 border-b border-gray-200 pb-2">
                        {{ ucfirst($location) }} Tables</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-5">
                        @foreach ($tables as $table)
                            <div id="{{ $table->id }}" onclick="selectTable({{ $table->id }}, '{{ $table->status->value }}')"
                                class="table-item relative flex flex-col rounded-xl shadow-lg p-3 text-white cursor-pointer transition-transform transform hover:-translate-y-1"
                                data-table-status="{{ $table->status->value }}"
                                data-table-name="{{ $table->name }}"
                                data-preview-url="{{ isset($latestFinalizedBillIds[$table->id]) ? route('pos.bill.preview', $latestFinalizedBillIds[$table->id], false) : '' }}"
                                data-order-sum="{{ $table->order_sum ?? 0 }}">

                                <p class="elapsed-time absolute top-2 right-2 text-xs font-bold bg-black bg-opacity-20 px-1.5 py-0.5 rounded-full"
                                    data-taken-at="{{ $table->taken_at }}">
                                </p>

                                <div class="text-center">
                                    <h2 class="text-3xl font-bold tracking-wider">{{ $table->name }}</h2>
                                    @if ($table->order_sum)
                                        <p id="tableTotal" class="text-lg font-semibold mt-1">NPR {{ $table->order_sum }}
                                        </p>
                                    @else
                                        <p id="tableTotal" class="text-lg font-semibold mt-1"></p> {{-- Ensure element exists for clearing --}}
                                    @endif
                                </div>


                                <div class="table-options bottom-3 left-0 right-0 flex justify-center gap-2">
                                    <div id="showOrdersBtn">
                                        <button onclick="event.stopPropagation(); handleTablePreview({{ $table->id }})"
                                            class="action-btn" title="View orders" aria-label="View orders">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    @if (auth()->user()->canTransferTables())
                                        <div id="transferTableBtn">
                                            <button onclick="event.stopPropagation(); triggerTransferModal({{ $table->id }})"
                                                class="action-btn" title="Transfer or merge table" aria-label="Transfer or merge table">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                        </div>
                                    @endif
                                    <div id="printTableBtn">
                                        <button onclick="event.stopPropagation(); triggerSummaryBill({{ $table->id }})"
                                            class="action-btn" title="Print summary" aria-label="Print summary">
                                            <i class="fas fa-receipt"></i>
                                        </button>
                                    </div>
                                    <div id="finalBillBtn">
                                        <button onclick="event.stopPropagation(); triggerFinalBillModal({{ $table->id }})"
                                            class="action-btn" title="Print final bill" aria-label="Print final bill">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </div>
                                    <div id="settleTableBtn">
                                        <button
                                            onclick="event.stopPropagation(); triggerPaymentModal({{ $table->id }})"
                                            class="action-btn" title="Close table" aria-label="Close table">
                                            <i class="fas fa-dollar-sign"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div id="paymentModal" class="modal fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black bg-opacity-50 p-2 sm:items-center"
            style="display: none;">
            <div class="modal-overlay fixed inset-0" data-close="paymentModal"></div>
            <div class="modal-container relative m-2 max-h-[calc(100vh-1rem)] w-full max-w-sm overflow-y-auto rounded-xl bg-white p-4 shadow-2xl sm:p-6">
                <div class="modal-header flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Close Table</h3>
                    <button data-close="paymentModal" class="text-gray-400 hover:text-gray-600" aria-label="Close dialog">&times;</button>
                </div>
                <div class="modal-body space-y-3 text-sm text-gray-700">
                    <input type="hidden" id="paymentTableId" value="0">
                    <p>The final bill has been finalized. Close this table only after payment or credit approval is confirmed.</p>
                </div>
                <div class="modal-footer mt-6 flex justify-end gap-3">
                    <button type="button"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold"
                        data-close="paymentModal">Cancel</button>
                    <button type="button" id="savePaymentDataBtn"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Close Table</button>
                </div>
            </div>
        </div>

        <div id="summaryBillModal" class="modal fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black bg-opacity-50 p-2 sm:items-center"
            style="display: none;">
            <div class="modal-overlay fixed inset-0" data-close="summaryBillModal"></div>
            <div class="modal-container relative m-2 max-h-[calc(100vh-1rem)] w-full max-w-sm overflow-y-auto rounded-xl bg-white p-4 shadow-2xl sm:p-6">
                <div class="modal-header flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Summary Bill</h3>
                    <button data-close="summaryBillModal" class="text-gray-400 hover:text-gray-600" aria-label="Close dialog">&times;</button>
                </div>
                <div class="modal-body space-y-3">
                    <input type="hidden" id="summaryBillTableId" value="0">
                    <div>
                        <label for="summaryBillingSource" class="block text-sm font-semibold text-gray-700 mb-2">Orders</label>
                        <select id="summaryBillingSource" class="w-full border rounded p-2"></select>
                    </div>
                </div>
                <div class="modal-footer mt-6 flex justify-end gap-3">
                    <button type="button"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold"
                        data-close="summaryBillModal">Cancel</button>
                    <button type="button" id="saveSummaryBillBtn"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Print Summary</button>
                </div>
            </div>
        </div>

        @if (auth()->user()->canTransferTables())
            <div id="transferTableModal" class="modal fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black bg-opacity-50 p-2 sm:items-center"
                style="display: none;">
                <div class="modal-overlay fixed inset-0" data-close="transferTableModal"></div>
                <div class="modal-container relative m-2 max-h-[calc(100vh-1rem)] w-full max-w-sm overflow-y-auto rounded-xl bg-white p-4 shadow-2xl sm:p-6">
                    <div class="modal-header flex justify-between items-center mb-4">
                        <h3 class="text-xl font-semibold text-gray-800">Transfer Table</h3>
                        <button data-close="transferTableModal" class="text-gray-400 hover:text-gray-600" aria-label="Close dialog">&times;</button>
                    </div>
                    <div class="modal-body space-y-3">
                        <input type="hidden" id="transferSourceTableId" value="0">
                        <div>
                            <label for="transferTargetTableId" class="block text-sm font-semibold text-gray-700 mb-2">Move To</label>
                            <select id="transferTargetTableId" class="w-full border rounded p-2"></select>
                        </div>
                    </div>
                    <div class="modal-footer mt-6 flex justify-end gap-3">
                        <button type="button"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold"
                            data-close="transferTableModal">Cancel</button>
                        <button type="button" id="saveTransferTableBtn"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Transfer</button>
                    </div>
                </div>
            </div>
        @endif

        <div id="finalBillModal" class="modal fixed inset-0 z-50 flex items-start sm:items-center justify-center overflow-y-auto p-2 bg-black bg-opacity-50"
            style="display: none;">
            <div class="modal-overlay fixed inset-0" data-close="finalBillModal"></div>
            <div class="modal-container bg-white rounded-xl shadow-2xl w-full max-w-lg max-h-[calc(100vh-1rem)] overflow-y-auto p-4 m-2 relative">
                <div class="modal-header flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Finalize Bill</h3>
                    <button data-close="finalBillModal" class="text-gray-400 hover:text-gray-600" aria-label="Close dialog">&times;</button>
                </div>
                <div class="modal-body space-y-4">
                    <input type="hidden" id="finalBillTableId" value="0">

                    <div id="finalBillingSourceWrapper" style="display: none;">
                        <label for="finalBillingSource" class="block text-sm font-semibold text-gray-700 mb-2">Orders</label>
                        <select id="finalBillingSource" class="w-full border rounded p-2"></select>
                    </div>

                    <div id="finalLoyaltySection" class="hidden rounded-lg border border-amber-300 bg-amber-50 p-3">
                        <button type="button" id="showFinalLoyalty" class="w-full rounded bg-amber-500 px-3 py-2 font-semibold text-white">Redeem stamp card</button>
                        <div id="finalLoyaltyItems" class="hidden mt-3 space-y-2"></div>
                        <p id="finalLoyaltySummary" class="mt-2 text-xs font-semibold text-amber-900"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Payment Method</label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                                <label
                                    class="flex items-center p-2 rounded-lg hover:bg-gray-50 border has-[:checked]:bg-green-50 has-[:checked]:border-green-400">
                                    <input type="radio" name="final-payment-type" value="{{ $paymentValue }}"
                                        id="final-payment-{{ $paymentValue }}"
                                        class="h-4 w-4 text-green-600 border-gray-300 focus:ring-green-500">
                                    <span class="ml-3 text-gray-700 font-medium">{{ $paymentLabel }}</span>
                                </label>
                            @endforeach
                            <label
                                class="flex items-center p-2 rounded-lg hover:bg-gray-50 border has-[:checked]:bg-green-50 has-[:checked]:border-green-400">
                                <input type="radio" name="final-payment-type" value="split" id="final-payment-split"
                                    class="h-4 w-4 text-green-600 border-gray-300 focus:ring-green-500">
                                <span class="ml-3 text-gray-700 font-medium">Split Payment</span>
                            </label>
                        </div>
                    </div>

                    <div id="finalSplitPaymentFields" class="hidden p-2 bg-gray-50 border rounded-lg space-y-2">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="finalSplitMethod1" class="block text-sm font-semibold text-gray-700">First Method</label>
                                <select id="finalSplitMethod1" class="w-full border rounded p-2">
                                    @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                                        @if ($paymentValue !== 'credit')
                                            <option value="{{ $paymentValue }}">{{ $paymentLabel }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="finalSplitAmount1" class="block text-sm font-semibold text-gray-700">First Amount</label>
                                <input id="finalSplitAmount1" type="number" min="0.01" step="0.01" inputmode="decimal" autocomplete="off" class="w-full border rounded p-2 text-right">
                            </div>
                            <div>
                                <label for="finalSplitMethod2" class="block text-sm font-semibold text-gray-700">Second Method</label>
                                <select id="finalSplitMethod2" class="w-full border rounded p-2">
                                    @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                                        @if ($paymentValue !== 'credit')
                                            <option value="{{ $paymentValue }}" @selected($paymentValue === 'fonepay')>{{ $paymentLabel }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="finalSplitAmount2" class="block text-sm font-semibold text-gray-700">Remainder</label>
                                <input id="finalSplitAmount2" readonly class="w-full border rounded p-2 text-right bg-gray-100">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <input id="finalSplitReference1" maxlength="100" class="w-full border rounded p-2" placeholder="First reference (optional)">
                            <input id="finalSplitReference2" maxlength="100" class="w-full border rounded p-2" placeholder="Second reference (optional)">
                        </div>
                        <p id="finalSplitTotal" class="text-sm text-gray-600" aria-live="polite"></p>
                    </div>

                    <div>
                        <label for="finalPrintCopies" class="block text-sm font-semibold text-gray-700 mb-2">Receipt Copies</label>
                        <select id="finalPrintCopies" class="w-full border rounded p-2">
                            <option value="customer">Customer Copy</option>
                            @if (config('pos.printing.receipt.restaurant_copy_enabled', false))
                                <option value="both">Customer + Restaurant Copies</option>
                            @endif
                        </select>
                    </div>

                    @if (auth()->user()->canApplyDiscount())
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="finalDiscountType" class="block text-sm font-semibold text-gray-700">Discount Type</label>
                                <select id="finalDiscountType" class="w-full border rounded p-2">
                                    <option value="">No discount</option>
                                    <option value="amount">Amount</option>
                                    <option value="percentage">Percentage</option>
                                </select>
                            </div>
                            <div>
                                <label for="finalDiscountValue" class="block text-sm font-semibold text-gray-700">Discount Value</label>
                                <input id="finalDiscountValue" type="number" min="0" step="0.01" value="0"
                                    class="w-full border rounded p-2 text-right">
                            </div>
                        </div>

                        <div>
                            <label for="finalDiscountReason" class="block text-sm font-semibold text-gray-700">Discount Reason</label>
                            <select id="finalDiscountReason" class="w-full border rounded p-2">
                                <option value="">Select reason when discount is used</option>
                                @foreach (config('pos.discount_reasons') as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input id="finalDiscountType" type="hidden" value="">
                        <input id="finalDiscountValue" type="hidden" value="0">
                        <input id="finalDiscountReason" type="hidden" value="">
                    @endif

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="buyerName" class="block text-sm font-semibold text-gray-700">Buyer Name</label>
                            <input id="buyerName" class="w-full border rounded p-2">
                        </div>
                        <div>
                            <label for="buyerPan" class="block text-sm font-semibold text-gray-700">Buyer PAN</label>
                            <input id="buyerPan" class="w-full border rounded p-2">
                        </div>
                    </div>
                    <div>
                        <label for="buyerAddress" class="block text-sm font-semibold text-gray-700">Buyer Address</label>
                        <input id="buyerAddress" class="w-full border rounded p-2">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="creditCustomerName" class="block text-sm font-semibold text-gray-700">Credit Customer Name</label>
                            <input id="creditCustomerName" class="w-full border rounded p-2">
                        </div>
                        <div>
                            <label for="creditCustomerContact" class="block text-sm font-semibold text-gray-700">Credit Contact</label>
                            <input id="creditCustomerContact" class="w-full border rounded p-2">
                        </div>
                    </div>
                </div>
                <div class="modal-footer mt-6 flex justify-end gap-3">
                    <button type="button"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold"
                        data-close="finalBillModal">Cancel</button>
                    <button type="button" id="saveFinalBillBtn"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Finalize & Queue Print</button>
                </div>
            </div>
        </div>

        <div id="billPreviewModal"
            class="modal fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black bg-opacity-60 p-2 sm:items-center"
            style="display: none;">
            <div class="modal-overlay fixed inset-0" data-close="billPreviewModal"></div>
            <div class="modal-container relative m-0 flex h-[calc(100vh-1rem)] max-h-[calc(100vh-1rem)] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white p-3 shadow-2xl sm:m-2 sm:p-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-800">Bill Preview</h3>
                        <p id="billPreviewStatus" class="text-sm text-gray-600">
                            Preview of the 80 mm tax invoice.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" id="printBillPreviewBtn"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold">
                            <i class="fas fa-print mr-2"></i>Print from Browser
                        </button>
                        <button type="button"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold"
                            data-close="billPreviewModal">Close</button>
                    </div>
                </div>
                <iframe id="billPreviewFrame" title="Bill preview"
                    class="w-full flex-1 border rounded-lg bg-gray-100"></iframe>
            </div>
        </div>
    </div>

    <style>
        .action-btn {
            background-color: rgba(255, 255, 255, 0.9);
            color: #333;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease-in-out;
        }

        .action-btn:hover {
            background-color: white;
            transform: scale(1.1);
        }

        .table-item {
            min-height: 100px !important;
        }
    </style>

    <script>
        const billTableUrl = "{{ route('pos.table.bill', [], false) }}";
        const indexUrl = "{{ route('pos.tables', [], false) }}";
        const settleTableUrl = "{{ route('pos.table.settle', [], false) }}";
        const transferTableUrl = "{{ route('pos.table.transfer', [], false) }}";
        const selectTableURL = "{{ route('pos.main', [], false) }}";
        const tableColors = @json($table_colors);
        const tableBillingGroups = @json($tableBillingGroups);
        const buyerPanThreshold = {{ (float) config('pos.invoice.buyer_pan_required_above', 10000) }};
        const vatRate = {{ (float) config('pos.tax.vat_rate', 13) }};
        const vatInclusive = @json((bool) config('pos.tax.vat_inclusive', true));
        const serviceChargeEnabled = @json((bool) config('pos.tax.service_charge_enabled', false));
        const serviceChargeRate = {{ (float) config('pos.tax.service_charge_rate', 0) }};

        let runningTables = [];

        $(document).ready(function() {
            // Initialize the table styles and event listeners
            initTables();
        });

        function initTables() {
            updateTableStyles();
            updateRunningTables();
            updateElapsedTimes();

            // Update elapsed times every minute
            setInterval(updateElapsedTimes, 60000);

            // Event listeners
            $("#Takeaway").on("click", () => selectTable("takeaway"));
            $('[data-close="paymentModal"]').on("click", () => $("#paymentModal").hide());
            $('[data-close="summaryBillModal"]').on("click", () => $("#summaryBillModal").hide());
            $('[data-close="transferTableModal"]').on("click", () => $("#transferTableModal").hide());
            $('[data-close="finalBillModal"]').on("click", () => $("#finalBillModal").hide());
            $('[data-close="billPreviewModal"]').on("click", closeBillPreview);
            $("#savePaymentDataBtn").on("click", handlePayment);
            $("#saveSummaryBillBtn").on("click", handleSummaryBill);
            $("#saveTransferTableBtn").on("click", handleTransferTable);
            $("#saveFinalBillBtn").on("click", handleFinalBill);
            $("#printBillPreviewBtn").on("click", printBillPreview);
            $("input[name='final-payment-type']").on("change", function() {
                const isSplit = this.value === "split";
                $("#finalSplitPaymentFields").toggleClass("hidden", !isSplit);
                updateFinalSplitRemainder(isSplit);
            });
            $("#finalSplitAmount1, #finalDiscountValue").on("input", () => updateFinalSplitRemainder(false));
            $("#finalDiscountType").on("change", () => updateFinalSplitRemainder(false));
            $("#finalBillingSource").on("change", () => {
                finalLoyaltySelections = {};
                renderFinalLoyalty();
                updateFinalSplitRemainder(false);
            });
            $("#showFinalLoyalty").on("click", () => $("#finalLoyaltyItems").toggleClass("hidden"));
            $("#finalSplitMethod1, #finalSplitMethod2").on("change", function() {
                syncFinalSplitMethodOptions(this.id);
            });
            syncFinalSplitMethodOptions();
        }

        function handlePayment() {
            const tableId = $("#paymentTableId").val();
            settleTable(tableId);
            $("#paymentModal").hide();
        }

        function selectTable(tableId, status = "available") {
            if (!["available", "running", "runningKOT"].includes(status)) {
                return;
            }

            window.location.href = `${selectTableURL}?tableId=${tableId}`;
        }

        // Update elapsed time for running tables
        function updateElapsedTimes() {
            runningTables.forEach(table => {
                const elapsedString = getElapsedMinutes(table.takenAt);
                // Correctly target the elapsed-time <p> inside the specific table div
                $(`#${table.tableId}`).find(".elapsed-time").text(elapsedString);
            });
        }

        function getElapsedMinutes(originalTime) {
            if (!originalTime) return '';
            const takenTime = new Date(originalTime);
            const elapsedTime = Date.now() - takenTime.getTime();
            const hours = Math.floor(elapsedTime / (1000 * 60 * 60));
            const minutes = Math.floor((elapsedTime % (1000 * 60 * 60)) / (1000 * 60));
            return hours > 0 ? `${hours}H:${minutes}M` : `${minutes}M`;
        }

        // Show orders for a specific table
        function showOrders(tableId) {
            const url = "{{ route('pos.table.orders', ['tableId' => ':id'], false) }}".replace(':id', tableId);

            const target = '{{ env('LINK_TARGET', '_blank') }}';
            if (target === '_self') {
                window.location.href = url;
            } else {
                window.open(url, target);
            }
        }

        // Trigger payment modal
        function triggerPaymentModal(tableId) {
            $("#paymentModal").css("display", "flex");
            $("#paymentTableId").val(tableId);
        }

        function billingGroupForTable(tableId) {
            return tableBillingGroups[tableId] || tableBillingGroups[String(tableId)] || {
                total: Number($(`#${tableId}`).data("order-sum") || 0),
                sources: []
            };
        }

        function formatMoney(amount) {
            return Number(amount || 0).toFixed(2);
        }

        function populateBillingSourceSelect(tableId, selector, showWrapper = null) {
            const group = billingGroupForTable(tableId);
            const sources = group.sources || [];
            const $select = $(selector);

            $select.empty();
            $select.append(new Option(`All orders together - NPR ${formatMoney(group.total || $(`#${tableId}`).data("order-sum"))}`, "all"));

            sources.forEach(source => {
                $select.append(new Option(`${source.name} - NPR ${formatMoney(source.total)}`, String(source.id)));
            });

            if (showWrapper) {
                $(showWrapper).toggle(sources.length > 1);
            }

            $select.val("all");
        }

        function selectedBillingSubtotal(tableId, billingSource) {
            const group = billingGroupForTable(tableId);

            if (billingSource && billingSource !== "all") {
                const source = (group.sources || []).find(item => String(item.id) === String(billingSource));
                return Number(source ? source.total : 0);
            }

            return Number(group.total || $(`#${tableId}`).data("order-sum") || 0);
        }

        function triggerSummaryBill(tableId) {
            const group = billingGroupForTable(tableId);

            if ((group.sources || []).length <= 1) {
                printSummary(tableId, "all");
                return;
            }

            $("#summaryBillTableId").val(tableId);
            populateBillingSourceSelect(tableId, "#summaryBillingSource");
            $("#summaryBillModal").css("display", "flex");
        }

        function handleSummaryBill() {
            const tableId = $("#summaryBillTableId").val();
            const billingSource = $("#summaryBillingSource").val() || "all";

            $("#summaryBillModal").hide();
            printSummary(tableId, billingSource);
        }

        function triggerTransferModal(tableId) {
            $("#transferSourceTableId").val(tableId);
            const $target = $("#transferTargetTableId");
            $target.empty();

            $(".table-item").each(function() {
                const $table = $(this);
                const targetId = String($table.attr("id"));
                const status = $table.attr("data-table-status");

                if (targetId !== String(tableId) && ["available", "running", "runningKOT"].includes(status)) {
                    const action = status === "available" ? "empty" : "merge";
                    const name = $table.attr("data-table-name") || `Table ${targetId}`;
                    $target.append(new Option(`${name} (${action})`, targetId));
                }
            });

            if (!$target.children().length) {
                alert("No empty or running table is available for transfer.");
                return;
            }

            $("#transferTableModal").css("display", "flex");
        }

        function handleTablePreview(tableId) {
            const $table = $(`#${tableId}`);

            if ($table.attr("data-table-status") === "printed") {
                const previewUrl = $table.attr("data-preview-url");

                if (!previewUrl) {
                    alert("No finalized bill preview is available for this table.");
                    return;
                }

                openBillPreview(previewUrl, "Finalized bill preview. Use Print from Browser for a local copy.");
                return;
            }

            showOrders(tableId);
        }

        function openBillPreview(previewUrl, statusMessage) {
            if (!previewUrl) {
                alert("Bill preview is unavailable.");
                return;
            }

            $("#billPreviewStatus").text(statusMessage || "Preview of the 80 mm tax invoice.");
            $("#billPreviewFrame").attr("src", previewUrl);
            $("#billPreviewModal").css("display", "flex");
        }

        function closeBillPreview() {
            $("#billPreviewModal").hide();
            $("#billPreviewFrame").attr("src", "about:blank");
        }

        function printBillPreview() {
            const frame = document.getElementById("billPreviewFrame");

            if (!frame || !frame.src || frame.src.endsWith("about:blank")) {
                alert("Open a bill preview before printing.");
                return;
            }

            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (error) {
                window.open(frame.src, "_blank", "noopener");
            }
        }

        function handleTransferTable() {
            const sourceTableId = $("#transferSourceTableId").val();
            const targetTableId = $("#transferTargetTableId").val();

            if (!sourceTableId || !targetTableId) {
                alert("Select a target table.");
                return;
            }

            transferTable(sourceTableId, targetTableId);
        }

        function triggerFinalBillModal(tableId) {
            $("#finalBillTableId").val(tableId);
            populateBillingSourceSelect(tableId, "#finalBillingSource", "#finalBillingSourceWrapper");
            $("input[name='final-payment-type'][value='cash']").prop("checked", true);
            $("#finalPrintCopies").val(@json(config('pos.printing.receipt.default_copies', 'customer')));
            $("#finalDiscountType").val("");
            $("#finalDiscountValue").val("0");
            $("#finalDiscountReason").val("");
            $("#buyerName, #buyerPan, #buyerAddress, #creditCustomerName, #creditCustomerContact").val("");
            $("#finalSplitAmount1, #finalSplitAmount2, #finalSplitReference1, #finalSplitReference2").val("");
            $("#finalSplitPaymentFields").addClass("hidden");
            finalLoyaltySelections = {};
            $("#finalLoyaltyItems").addClass("hidden");
            renderFinalLoyalty();
            $("#finalBillModal").css("display", "flex");
        }

        function collectBuyerDataIfNeeded(amount) {
            if (Number(amount) <= Number(buyerPanThreshold || 10000)) {
                return {};
            }

            const buyerName = prompt("Buyer name is required for invoices above NPR 10,000.");
            if (!buyerName) {
                return null;
            }

            const buyerPan = prompt("Buyer PAN is required for invoices above NPR 10,000.");
            if (!buyerPan) {
                return null;
            }

            const buyerAddress = prompt("Buyer address (optional).") || "";

            return {
                buyer_name: buyerName,
                buyer_pan: buyerPan,
                buyer_address: buyerAddress,
            };
        }

        function collectCreditDataIfNeeded(paymentType) {
            if (paymentType !== "credit") {
                return {};
            }

            const creditCustomerName = prompt("Credit customer name is required.");
            if (!creditCustomerName) {
                return null;
            }

            const creditCustomerContact = prompt("Credit customer contact (optional).") || "";

            return {
                credit_customer_name: creditCustomerName,
                credit_customer_contact: creditCustomerContact,
            };
        }

        let finalLoyaltySelections = {};

        function finalLoyaltyCandidates() {
            const tableId = $("#finalBillTableId").val();
            const billingSource = $("#finalBillingSource").val() || "all";
            const group = billingGroupForTable(tableId);

            if (billingSource !== "all") {
                return (group.sources || []).find(item => String(item.id) === String(billingSource))?.loyalty_items || [];
            }

            return group.loyalty_items || [];
        }

        function finalLoyaltyValue() {
            return finalLoyaltyCandidates().reduce((total, item) =>
                total + Number(finalLoyaltySelections[item.menu_id] || 0) * Number(item.unit_price), 0);
        }

        function renderFinalLoyalty() {
            const candidates = finalLoyaltyCandidates();
            const container = $("#finalLoyaltyItems").empty();
            $("#finalLoyaltySection").toggleClass("hidden", candidates.length === 0);

            candidates.forEach(item => {
                const selected = Math.min(Number(finalLoyaltySelections[item.menu_id] || 0), Number(item.quantity));
                finalLoyaltySelections[item.menu_id] = selected;
                container.append(`
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0">${item.name} × ${item.quantity}<br><small>Regular price NPR ${Number(item.unit_price).toFixed(2)}</small></span>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" class="rounded bg-gray-200 px-3 py-1" onclick="changeFinalLoyalty(${item.menu_id}, -1)">−</button>
                            <span class="w-5 text-center font-bold">${selected}</span>
                            <button type="button" class="rounded bg-amber-500 px-3 py-1 text-white" onclick="changeFinalLoyalty(${item.menu_id}, 1)">+</button>
                        </div>
                    </div>`);
            });

            const cards = Object.values(finalLoyaltySelections).reduce((sum, quantity) => sum + Number(quantity), 0);
            $("#finalLoyaltySummary").text(cards > 0
                ? `${cards} completed card${cards === 1 ? "" : "s"} collected · Reward NPR ${finalLoyaltyValue().toFixed(2)}`
                : "No stamp card applied.");
        }

        function changeFinalLoyalty(menuId, delta) {
            const candidate = finalLoyaltyCandidates().find(item => Number(item.menu_id) === Number(menuId));
            if (!candidate) return;
            const current = Number(finalLoyaltySelections[menuId] || 0);
            const next = Math.max(0, Math.min(current + delta, Number(candidate.quantity)));
            if (next === current) return;
            if (delta > 0 && !confirm("Confirm that one completed physical stamp card has been collected.")) return;
            finalLoyaltySelections[menuId] = next;
            renderFinalLoyalty();
            updateFinalSplitRemainder(false);
        }

        function collectFinalLoyaltyRewards() {
            return Object.entries(finalLoyaltySelections)
                .filter(([, quantity]) => Number(quantity) > 0)
                .map(([menuId, quantity]) => ({ menu_id: Number(menuId), quantity: Number(quantity) }));
        }

        function finalSubtotal(tableId, billingSource) {
            return Math.max(selectedBillingSubtotal(tableId, billingSource) - finalLoyaltyValue(), 0);
        }

        function discountAmountForTable(tableId, billingSource) {
            const subtotal = finalSubtotal(tableId, billingSource);
            const discountType = $("#finalDiscountType").val();
            const discountValue = Number($("#finalDiscountValue").val() || 0);

            if (!discountType || discountValue <= 0) {
                return 0;
            }

            return discountType === "percentage" ? subtotal * discountValue / 100 : discountValue;
        }

        function finalPayableCents() {
            const tableId = $("#finalBillTableId").val();
            const billingSource = $("#finalBillingSource").val() || "all";
            const subtotalCents = Math.max(Math.round(finalSubtotal(tableId, billingSource) * 100), 0);
            const discountCents = Math.max(Math.min(Math.round(discountAmountForTable(tableId, billingSource) * 100), subtotalCents), 0);
            const afterDiscountCents = subtotalCents - discountCents;
            const serviceCents = serviceChargeEnabled
                ? Math.round(afterDiscountCents * serviceChargeRate / 100)
                : 0;
            const grossCents = afterDiscountCents + serviceCents;

            return vatInclusive ? grossCents : grossCents + Math.round(grossCents * vatRate / 100);
        }

        function updateFinalSplitRemainder(autoFillFirst = false) {
            const totalCents = finalPayableCents();
            let firstCents = Math.max(Math.round(Number($("#finalSplitAmount1").val() || 0) * 100), 0);

            if (autoFillFirst === true && totalCents >= 2 && firstCents <= 0) {
                firstCents = Math.floor(totalCents / 2);
                $("#finalSplitAmount1").val((firstCents / 100).toFixed(2));
            }

            const remainderCents = Math.max(totalCents - firstCents, 0);
            const invalidFirstAmount = totalCents >= 2 && firstCents >= totalCents;
            $("#finalSplitAmount2").val((remainderCents / 100).toFixed(2));
            $("#finalSplitTotal")
                .toggleClass("text-red-700", invalidFirstAmount)
                .toggleClass("text-gray-600", !invalidFirstAmount)
                .text(invalidFirstAmount
                    ? `First amount must be less than the bill total of NPR ${(totalCents / 100).toFixed(2)}.`
                    : `Bill total: NPR ${(totalCents / 100).toFixed(2)} · Remaining: NPR ${(remainderCents / 100).toFixed(2)}`);
        }

        function syncFinalSplitMethodOptions(changedId = null) {
            const $first = $("#finalSplitMethod1");
            const $second = $("#finalSplitMethod2");

            if ($first.val() === $second.val()) {
                const $target = changedId === "finalSplitMethod2" ? $first : $second;
                const otherValue = $target.is($first) ? $second.val() : $first.val();
                $target.val($target.find("option").filter((_, option) => option.value !== otherValue).first().val());
            }

            $first.find("option").prop("disabled", false).filter(`[value="${$second.val()}"]`).prop("disabled", true);
            $second.find("option").prop("disabled", false).filter(`[value="${$first.val()}"]`).prop("disabled", true);
        }

        function collectFinalSplitPayments(paymentType) {
            if (paymentType !== "split") return [];

            updateFinalSplitRemainder();
            const methods = [$("#finalSplitMethod1").val(), $("#finalSplitMethod2").val()];
            const amounts = [$("#finalSplitAmount1").val(), $("#finalSplitAmount2").val()];
            const totalCents = finalPayableCents();
            const firstCents = Math.round(Number(amounts[0] || 0) * 100);

            if (methods[0] === methods[1]) {
                alert("Split payment methods must be different.");
                return null;
            }

            if (firstCents >= totalCents) {
                alert(`First split amount must be less than the bill total of NPR ${(totalCents / 100).toFixed(2)}.`);
                return null;
            }

            if (amounts.some(amount => Math.round(Number(amount || 0) * 100) <= 0)) {
                alert("Each split payment amount must be greater than zero.");
                return null;
            }

            return methods.map((method, index) => ({
                method,
                amount: Number(amounts[index]).toFixed(2),
                reference_no: $(`#finalSplitReference${index + 1}`).val().trim(),
            }));
        }

        function collectFinalBillData() {
            const tableId = $("#finalBillTableId").val();
            const billingSource = $("#finalBillingSource").val() || "all";
            const paymentType = $("input[name='final-payment-type']:checked").val();
            const discountType = $("#finalDiscountType").val();
            const discountValue = Number($("#finalDiscountValue").val() || 0);
            const discountAmount = discountAmountForTable(tableId, billingSource);
            const subtotal = finalSubtotal(tableId, billingSource);
            const totalAfterDiscount = Math.max(subtotal - discountAmount, 0);
            const buyerName = $("#buyerName").val().trim();
            const buyerPan = $("#buyerPan").val().trim();
            const creditCustomerName = $("#creditCustomerName").val().trim();

            if (!paymentType) {
                alert("Select a payment method.");
                return null;
            }

            if (discountValue > 0 && !discountType) {
                alert("Select a discount type.");
                return null;
            }

            if (discountType === "percentage" && discountValue > 100) {
                alert("Percentage discount cannot be greater than 100.");
                return null;
            }

            if (discountAmount > subtotal) {
                alert("Discount cannot be greater than the bill subtotal.");
                return null;
            }

            if (discountValue > 0 && !$("#finalDiscountReason").val()) {
                alert("Select a discount reason.");
                return null;
            }

            if (totalAfterDiscount > Number(buyerPanThreshold || 10000) && (!buyerName || !buyerPan)) {
                alert("Buyer name and PAN are required for invoices above NPR 10,000.");
                return null;
            }

            if (paymentType === "credit" && !creditCustomerName) {
                alert("Credit customer name is required.");
                return null;
            }

            const payments = collectFinalSplitPayments(paymentType);
            if (payments === null) {
                return null;
            }

            return {
                tableId,
                billingSource,
                billAction: "final",
                paymentType,
                payments,
                loyalty_rewards: collectFinalLoyaltyRewards(),
                print_copies: $("#finalPrintCopies").val() || "customer",
                discount_type: discountType,
                discount_value: discountValue,
                discount_reason: $("#finalDiscountReason").val(),
                buyer_name: buyerName,
                buyer_pan: buyerPan,
                buyer_address: $("#buyerAddress").val().trim(),
                credit_customer_name: creditCustomerName,
                credit_customer_contact: $("#creditCustomerContact").val().trim(),
            };
        }

        function billingErrorMessage(error, fallback) {
            if (error.responseJSON && error.responseJSON.message) {
                return error.responseJSON.message;
            }

            if (error.responseJSON && error.responseJSON.errors) {
                return Object.values(error.responseJSON.errors).flat().join("\n");
            }

            return fallback;
        }

        function handleStepUpRequired(error) {
            if (error.status === 423 && error.responseJSON && error.responseJSON.step_up_url) {
                window.location.href = error.responseJSON.step_up_url;
                return true;
            }

            return false;
        }

        // Settle the table
        function settleTable(tableId) {
            if (!tableId) {
                alert("Table Settlement Failed, missing data. Reload the page and try again.");
                return;
            }

            showLoader();
            const csrf_token = $('meta[name="csrf-token"]').attr("content");

            $.ajax({
                url: settleTableUrl,
                type: "POST",
                data: {
                    tableId
                },
                headers: {
                    "X-CSRF-TOKEN": csrf_token
                },
                contentType: "application/x-www-form-urlencoded",
                success: response => {
                    if (response.status === "success") {
                        updateTableStatus(tableId, "available");
                    } else {
                        alert("Table Settlement Failed");
                    }
                },
                error: error => {
                    alert(billingErrorMessage(error, "Table Settlement Failed"));
                },
                complete: hideLoader
            });
        }

        // Print table bill
        function transferTable(sourceTableId, targetTableId) {
            showLoader();
            const csrf_token = $('meta[name="csrf-token"]').attr("content");

            $.ajax({
                url: transferTableUrl,
                type: "POST",
                data: {
                    source_table_id: sourceTableId,
                    target_table_id: targetTableId
                },
                headers: {
                    "X-CSRF-TOKEN": csrf_token
                },
                contentType: "application/x-www-form-urlencoded",
                success: response => {
                    if (response.status === "success") {
                        $("#transferTableModal").hide();
                        alert(response.message || "Table transferred successfully.");
                        window.location.reload();
                    } else {
                        alert("Table Transfer Failed");
                    }
                },
                error: error => {
                    if (handleStepUpRequired(error)) return;
                    alert(billingErrorMessage(error, "Table Transfer Failed"));
                },
                complete: hideLoader
            });
        }

        function printSummary(tableId, billingSource = "all") {
            showLoader();
            const csrf_token = $('meta[name="csrf-token"]').attr("content");

            $.ajax({
                url: billTableUrl,
                type: "POST",
                data: {
                    tableId,
                    billAction: "summary",
                    billingSource
                },
                headers: {
                    "X-CSRF-TOKEN": csrf_token
                },
                contentType: "application/x-www-form-urlencoded",
                success: response => {
                    if (response.status === "success") {
                        alert("Summary bill queued for printing.");
                    } else {
                        alert("Summary Print Failed");
                    }
                },
                error: error => {
                    alert(billingErrorMessage(error, "Summary Print Failed"));
                },
                complete: hideLoader
            });
        }

        function handleFinalBill() {
            const payload = collectFinalBillData();

            if (payload === null) {
                return;
            }

            printFinalBill(payload);
        }

        function printFinalBill(payload) {
            showLoader();
            const csrf_token = $('meta[name="csrf-token"]').attr("content");

            $.ajax({
                url: billTableUrl,
                type: "POST",
                data: payload,
                headers: {
                    "X-CSRF-TOKEN": csrf_token
                },
                contentType: "application/x-www-form-urlencoded",
                success: response => {
                    if (response.status === "success") {
                        $("#finalBillModal").hide();
                        updateTableStatus(payload.tableId, response.tableStatus || "printed");
                        $(`#${payload.tableId}`).attr("data-preview-url", response.previewUrl || "");
                        const previewStatus = response.printerOnline
                            ? "Bill finalized and queued to the online counter printer."
                            : "Bill finalized, but no counter printer is online. The queued job will remain pending; you can print this preview from the browser.";
                        openBillPreview(response.previewUrl, previewStatus);
                    } else {
                        alert("Bill Finalization Failed");
                    }
                },
                error: error => {
                    if (handleStepUpRequired(error)) return;
                    alert(billingErrorMessage(error, "Bill Finalization Failed"));
                },
                complete: hideLoader
            });
        }

        // Update table styles and buttons visibility
        function updateTableStyles() {
            $(".table-item").each(function() {
                const $table = $(this);
                const tableStatus = $table.attr("data-table-status");

                // Update table background color based on status
                $table.css("background-color", tableColors[tableStatus]);

                // Show/Hide buttons based on table status
                const showOrdersBtn = $table.find("#showOrdersBtn");
                const showOrdersButton = showOrdersBtn.find("button");
                const printTableBtn = $table.find("#printTableBtn");
                const finalBillBtn = $table.find("#finalBillBtn");
                const settleTableBtn = $table.find("#settleTableBtn");
                const transferTableBtn = $table.find("#transferTableBtn");

                switch (tableStatus) {
                    case 'available':
                        showOrdersBtn.hide();
                        printTableBtn.hide();
                        finalBillBtn.hide();
                        settleTableBtn.hide();
                        transferTableBtn.hide();
                        showOrdersButton.attr("title", "View orders");
                        break;
                    case 'running':
                        showOrdersBtn.show();
                        printTableBtn.show();
                        finalBillBtn.show();
                        settleTableBtn.hide();
                        transferTableBtn.show();
                        showOrdersButton.attr("title", "View orders");
                        break;
                    case 'printed':
                        showOrdersBtn.show();
                        printTableBtn.hide();
                        finalBillBtn.hide();
                        settleTableBtn.show();
                        transferTableBtn.hide();
                        showOrdersButton.attr("title", "Preview finalized bill");
                        break;
                }
            });
        }

        // Update table status and related UI elements
        function updateTableStatus(tableId, status) {
            const $table = $(`#${tableId}`);
            $table.attr("data-table-status", status);

            if (status === 'available') {
                clearElapsedTime(tableId);
                clearTableTotal(tableId);
            }

            updateRunningTables();
            updateTableStyles();
            updateElapsedTimes();
        }

        // Clear elapsed time and total when table is available
        function clearElapsedTime(tableId) {
            $(`#${tableId}`).find(".elapsed-time").attr("data-taken-at", "").text("");
        }

        function clearTableTotal(tableId) {
            $(`#${tableId}`).find("#tableTotal").text("");
        }

        // Update the list of running tables
        function updateRunningTables() {
            runningTables = [];

            $(".table-item").each(function() {
                const $table = $(this);
                const tableStatus = $table.attr("data-table-status");

                if (tableStatus !== 'available') {
                    runningTables.push({
                        tableId: $table.attr("id"),
                        takenAt: $table.find(".elapsed-time").attr("data-taken-at")
                    });
                }
            });
        }
    </script>
</x-pos-layout>
