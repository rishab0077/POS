<x-pos-layout>

    @section('title', 'POS')

    <div id="pos" class="flex min-h-[calc(100vh-5rem)] min-w-0 flex-col bg-gray-100 font-sans lg:h-[90vh]">

        <header id="order-main-nav"
            class="flex w-full shrink-0 flex-col gap-2 bg-gray-800 px-2 py-2 text-white shadow-md z-10 sm:flex-row sm:items-center sm:justify-between sm:px-4">
            <div id="items-search-options" class="flex min-w-0 w-full items-center gap-x-2 sm:w-2/3 sm:flex-grow sm:gap-x-4">
                <input id="search-input"
                    class="w-1/2 p-2 rounded-lg bg-gray-700 border border-gray-600 focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                    type="text" placeholder="Search by Name..">
                <input id="shortcode-input"
                    class="w-1/2 p-2 rounded-lg bg-gray-700 border border-gray-600 focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                    type="text" placeholder="By ShortCode..">
            </div>
            <div id="order-type-options" class="flex w-full items-center justify-end sm:ml-4 sm:w-1/3">
                @php $orderType = $orderType->value; @endphp
                @if ($table)
                    <div id="dine_in"
                        class="h-full px-4 py-2 text-center cursor-pointer rounded-l-lg {{ $orderType == 'dine_in' ? 'bg-green-700 font-bold active' : 'bg-gray-600 hover:bg-gray-700' }}">
                        <p>Dine In</p>
                    </div>
                @endif
                <div id="takeaway"
                    class="h-full px-4 py-2 text-center cursor-pointer rounded-r-lg {{ $orderType == 'takeaway' ? 'bg-green-700 font-bold active' : 'bg-gray-600 hover:bg-gray-700' }}">
                    <p>Take Away</p>
                </div>
            </div>
        </header>

        <div class="flex min-w-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">

            <div class="flex h-[50vh] min-h-[24rem] min-w-0 w-full shrink-0 lg:h-auto lg:min-h-0 lg:w-3/5">
                <aside id="category" class="category w-1/3 shrink-0 bg-gray-200 overflow-y-auto border-r border-gray-300 p-2 sm:w-1/4">
                    <div class="flex flex-col gap-y-2">
                        @foreach ($categoriesWithMenus as $category)
                            <button
                                class="btn w-full text-left font-semibold p-3 rounded-lg shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 bg-white text-gray-700 hover:bg-green-50 hover:text-green-800"
                                id="c{{ $category->id }}-btn" onclick="showMenu('c{{ $category->id }}')">
                                {{ $category->name }}
                            </button>
                        @endforeach
                    </div>
                </aside>

                <main class="min-w-0 flex-1 bg-white overflow-y-auto p-2 sm:p-4">
                    @foreach ($categoriesWithMenus as $category)
                        <div id="c{{ $category->id }}" class="menu-items hidden">
                            <div
                                class="grid grid-cols-1 min-[420px]:grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-4 xl:grid-cols-5 gap-2 sm:gap-4">
                                @foreach ($category->menus as $menu)
                                    <button
                                        class="relative flex flex-col justify-center items-center text-center p-2 h-24 rounded-lg shadow-md bg-green-600 text-white font-semibold transition-transform transform hover:-translate-y-1 hover:shadow-lg"
                                        id="{{ $menu->id }}" onclick="addItemToOrder({{ $menu->id }})"
                                        data-name="{{ $menu->name }}" data-price="{{ $menu->price }}" data-shortcode="{{ $menu->shortcode }}"
                                        data-loyalty-eligible="{{ $menu->category->contains('loyalty_eligible', true) ? 1 : 0 }}">
                                        {{-- JS will auto-format this with <br> tags --}}
                                        <span data-menu-name>{{ $menu->name }}</span>
                                        @if ($menu->category->contains('loyalty_eligible', true))
                                            <span class="absolute right-1 top-1 rounded bg-amber-300 px-1 text-[10px] font-bold text-amber-950">LOYALTY</span>
                                        @endif
                                    </button>
                                    @php $menuShortCuts[$menu->shortcode] = $menu->id; @endphp
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </main>
            </div>

            <aside id="order-panel" class="flex min-h-[36rem] min-w-0 w-full flex-col border-l border-gray-300 bg-gray-100 lg:min-h-0 lg:w-2/5">
                <div id="order-options-parent"
                    class="flex items-center justify-around p-2 border-b border-gray-200 bg-white shrink-0">
                    <button
                        class="btn order-options relative flex flex-col items-center justify-center w-16 h-16 rounded-full bg-blue-100 text-blue-800"
                        id="count" title="Number of Items">
                        <i class="fa fa-list text-xl"></i>
                        <span id="item-count" class="text-sm font-bold">0</span>
                    </button>
                    <button
                        class="btn order-options flex items-center justify-center w-16 h-16 rounded-full bg-yellow-100 text-yellow-800"
                        id="add-notes-btn" title="Add Notes">
                        <i class="fa fa-sticky-note text-xl"></i>
                    </button>


                    @if ($table)
                        <button data-tableid="{{ $table->id }}"
                            class="btn order-options flex flex-col items-center justify-center w-16 h-16 rounded-full bg-purple-100 text-purple-800"
                            id="table" title="Assign to Table">
                            <i class="fa fa-table text-xl"></i>
                            <span class="text-xs font-bold">{{ $table->name }}</span>
                        </button>
                    @endif
                </div>

                <div class="flex-grow overflow-hidden flex flex-col bg-white">
                    <table class="w-full shrink-0">
                        <thead class="bg-gray-700 text-white">
                            <tr id="order-items-heading">
                                <th class="p-2 text-left font-semibold w-3/6">Item</th>
                                <th class="p-2 font-semibold w-2/6">Qty</th>
                                <th class="p-2 font-semibold w-1/6">Amount</th>
                            </tr>
                        </thead>
                    </table>
                    <div class="flex-grow overflow-y-auto">
                        <table class="w-full">
                            <tbody id="order-items-body" class="divide-y divide-gray-200">
                                <tr id="noitems">
                                    <td colspan="3" class="text-center text-gray-400 p-8">
                                        <i class="fa fa-shopping-cart text-4xl mb-2"></i>
                                        <p>No Items Selected</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <table class="w-full shrink-0">
                        <tfoot class="bg-gray-200 font-bold">
                            @if ($existingOrderTotal > 0)
                                <tr class="text-sm text-gray-600">
                                    <td class="px-3 pt-2 text-left" colspan="2">Existing orders</td>
                                    <td class="px-3 pt-2 text-right">NPR <span id="existing-order-total">{{ number_format($existingOrderTotal, 2) }}</span></td>
                                </tr>
                            @endif
                            <tr class="text-sm text-gray-600 {{ $existingOrderTotal > 0 ? '' : 'hidden' }}">
                                <td class="px-3 py-1 text-left" colspan="2">New items</td>
                                <td class="px-3 py-1 text-right">NPR <span id="total">0.00</span></td>
                            </tr>
                            <tr class="text-lg text-gray-800">
                                <td class="p-3 text-left" colspan="2">{{ $existingOrderTotal > 0 ? 'Bill total' : 'Total' }}</td>
                                <td class="p-3 text-right">NPR <span id="billing-total">{{ number_format($existingOrderTotal, 2) }}</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="p-2 bg-gray-100 border-t border-gray-200 shrink-0 max-h-[46vh] overflow-y-auto overscroll-contain">
                    @if ($existingLoyaltyItems->isNotEmpty())
                        <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                            <p class="font-semibold text-amber-950">Existing order loyalty rewards</p>
                            <p class="mb-2 text-xs text-amber-800">One completed card redeems one item. Collect each physical card before adding a reward.</p>
                            <div class="space-y-2">
                                @foreach ($existingLoyaltyItems as $detail)
                                    <div class="flex items-center justify-between gap-2 text-sm" data-existing-loyalty="{{ $detail->id }}">
                                        <span class="min-w-0 truncate">{{ $detail->menu?->name ?? 'Deleted menu item' }} × {{ $detail->quantity }}</span>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <button type="button" class="rounded bg-gray-200 px-2 py-1" aria-label="Remove one loyalty reward from {{ $detail->menu?->name }}"
                                                onclick="changeExistingLoyalty({{ $detail->id }}, -1, {{ $detail->quantity }})">−</button>
                                            <span class="w-5 text-center font-bold" data-loyalty-count>{{ $detail->loyalty_reward_quantity }}</span>
                                            <button type="button" class="rounded bg-amber-500 px-2 py-1 text-white" aria-label="Redeem one {{ $detail->menu?->name }} loyalty reward"
                                                onclick="changeExistingLoyalty({{ $detail->id }}, 1, {{ $detail->quantity }})">+</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div id="payment-types" class="flex flex-wrap items-center justify-around gap-2 mb-2">
                        @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                            <label
                                class="flex-1 flex items-center justify-center p-2 rounded-lg border bg-white cursor-pointer has-[:checked]:bg-green-50 has-[:checked]:border-green-400 has-[:checked]:ring-2 has-[:checked]:ring-green-200">
                                <input id="{{ $paymentValue }}" type="radio" value="{{ $paymentValue }}"
                                    name="payment-type" class="h-4 w-4 text-green-600 focus:ring-green-500"
                                    {{ $loop->first ? 'checked' : '' }}>
                                <span class="ml-2 font-medium text-gray-700">{{ $paymentLabel }}</span>
                            </label>
                        @endforeach
                        <label
                            class="flex-1 flex items-center justify-center p-2 rounded-lg border bg-white cursor-pointer has-[:checked]:bg-green-50 has-[:checked]:border-green-400 has-[:checked]:ring-2 has-[:checked]:ring-green-200">
                            <input id="split" type="radio" value="split" name="payment-type"
                                class="h-4 w-4 text-green-600 focus:ring-green-500">
                            <span class="ml-2 font-medium text-gray-700">Split</span>
                        </label>
                    </div>
                    <div id="split-payment-fields" class="hidden mb-2 p-2 bg-white border rounded-lg space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="split-method-1" class="block text-xs font-semibold text-gray-600">First method</label>
                                <select id="split-method-1" class="w-full border rounded p-2 text-sm">
                                    @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                                        @if ($paymentValue !== 'credit')
                                            <option value="{{ $paymentValue }}">{{ $paymentLabel }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="split-amount-1" class="block text-xs font-semibold text-gray-600">First amount</label>
                                <input id="split-amount-1" type="number" min="0.01" step="0.01" inputmode="decimal" autocomplete="off" class="w-full border rounded p-2 text-sm text-right">
                            </div>
                            <div>
                                <label for="split-method-2" class="block text-xs font-semibold text-gray-600">Second method</label>
                                <select id="split-method-2" class="w-full border rounded p-2 text-sm">
                                    @foreach ($paymentTypes as $paymentValue => $paymentLabel)
                                        @if ($paymentValue !== 'credit')
                                            <option value="{{ $paymentValue }}" @selected($paymentValue === 'fonepay')>{{ $paymentLabel }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="split-amount-2" class="block text-xs font-semibold text-gray-600">Remainder</label>
                                <input id="split-amount-2" readonly class="w-full border rounded p-2 text-sm text-right bg-gray-100">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input id="split-reference-1" maxlength="100" class="w-full border rounded p-2 text-sm" placeholder="First reference (optional)">
                            <input id="split-reference-2" maxlength="100" class="w-full border rounded p-2 text-sm" placeholder="Second reference (optional)">
                        </div>
                        <p id="split-payment-total" class="text-xs text-gray-600" aria-live="polite"></p>
                    </div>
                    <div class="mb-2">
                        <label for="print-copies" class="sr-only">Receipt Copies</label>
                        <select id="print-copies" class="w-full border rounded-lg bg-white p-2 text-sm">
                            <option value="customer" @selected(config('pos.printing.receipt.default_copies', 'customer') === 'customer')>
                                Customer Copy
                            </option>
                            @if (config('pos.printing.receipt.restaurant_copy_enabled', false))
                                <option value="both" @selected(config('pos.printing.receipt.default_copies') === 'both')>
                                    Customer + Restaurant Copies
                                </option>
                            @endif
                        </select>
                    </div>
                    <div id="save-and-bill-options" class="grid grid-cols-3 gap-2">
                        <button
                            class="btn flex items-center justify-center gap-2 col-span-1 py-3 rounded-lg bg-green-600 text-white font-bold hover:bg-green-700 transition-colors"
                            id="bill-order"><i class="fa fa-print"></i> Bill</button>
                        <button
                            class="btn flex items-center justify-center gap-2 col-span-1 py-3 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 transition-colors"
                            id="kot-order"><i class="fa fa-receipt"></i> KOT</button>
                        <button
                            class="btn flex items-center justify-center gap-2 col-span-1 py-3 rounded-lg bg-red-600 text-white font-bold hover:bg-red-700 transition-colors"
                            id="cancel-order"><i class="fa fa-times"></i> Cancel</button>
                    </div>
                </div>
            </aside>
        </div>

        <div id="addNotesModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black bg-opacity-60 p-2 sm:items-center"
            style="display: none;">
            <div class="modal-overlay absolute inset-0" tabindex="-1" data-close="addNotesModal"></div>
            <div class="modal-container relative m-2 max-h-[calc(100vh-1rem)] w-full max-w-md overflow-y-auto rounded-xl bg-white shadow-2xl">
                <div class="modal-header flex justify-between items-center p-4 border-b">
                    <h3 class="text-xl font-semibold text-gray-800">Add Special Instructions</h3>
                    <button class="modal-close text-gray-400 hover:text-gray-600 font-bold py-1 px-3"
                        data-close="addNotesModal">&times;</button>
                </div>
                <div class="modal-body p-6">
                    <div class="form-group mb-6">
                        <label class="block text-md font-medium text-gray-700 mb-3">Select from predefined
                            notes:</label>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                            @foreach ($predefinedNotes as $note)
                                <label for="{{ $note }}"
                                    class="flex items-center text-gray-600 cursor-pointer">
                                    <input type="checkbox" name="notes" id="{{ $note }}"
                                        class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500">
                                    <span class="ml-3 capitalize">{{ $note }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="customNotes" class="block text-md font-medium text-gray-700 mb-2">Or type a custom
                            note:</label>
                        <textarea name="extra-notes" id="customNotes"
                            class="w-full p-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer flex justify-end p-4 bg-gray-50 border-t rounded-b-xl">
                    <button type="button"
                        class="px-5 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold mr-2 transition-colors"
                        data-close="addNotesModal">Close</button>
                    <button type="button"
                        class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold transition-colors"
                        id="saveNotesBtn">Save Notes</button>
                </div>
            </div>
        </div>
    </div>

    {{-- The original script block is preserved to ensure functionality remains unchanged --}}
    <script>
        var orderItems = [];
        const menuShortCuts = @json($menuShortCuts);
        const selectedNotes = [];
        const SOURCE = 'pos';
        let customerData = null;
        let audioUrl = "{{ asset('audio/select.wav') }}";
        let hasPrevOrders = false;
        let hasNewOrders = false;
        const orderSubmitUrl = "{{ route('order.submit', [], false) }}";
        const billTableUrl = "{{ route('pos.table.bill', [], false) }}";
        const indexUrl = "{{ route('pos.tables', [], false) }}";
        const settleTableUrl = "{{ route('pos.table.settle', [], false) }}";
        const loyaltyUpdateUrl = "{{ route('pos.loyalty.update', ['orderDetail' => '__DETAIL__'], false) }}";
        const buyerPanThreshold = {{ (float) config('pos.invoice.buyer_pan_required_above', 10000) }};
        const defaultPrintCopies = @json(config('pos.printing.receipt.default_copies', 'customer'));
        const vatRate = {{ (float) config('pos.tax.vat_rate', 13) }};
        const vatInclusive = @json((bool) config('pos.tax.vat_inclusive', true));
        const serviceChargeEnabled = @json((bool) config('pos.tax.service_charge_enabled', false));
        const serviceChargeRate = {{ (float) config('pos.tax.service_charge_rate', 0) }};
        let existingTableOrderTotal = {{ (float) $existingOrderTotal }};
    </script>
    <script src="{{ asset('js/pos.js') }}"></script>
    <style>
        /* Custom styles for dynamically generated elements from pos.js */
        #order-items-body tr {
            display: flex;
            width: 100%;
            align-items: center;
            padding: 0.5rem 0.25rem;
        }

        #order-items-body tr td {
            padding: 0.25rem;
        }

        #order-items-body tr td:nth-child(1) {
            width: 50%;
            display: flex;
            align-items: center;
        }

        /* 3/6 */
        #order-items-body tr td:nth-child(2) {
            width: 33.33%;
            text-align: center;
        }

        /* 2/6 */
        #order-items-body tr td:nth-child(3) {
            width: 16.66%;
            text-align: right;
            padding-right: 0.5rem;
        }

        /* 1/6 */

        .del-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #ef4444;
            /* red-500 */
            color: white;
            border-radius: 50%;
            width: 24px !important;
            height: 24px !important;
            margin-right: 10px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .del-item:hover {
            background-color: #dc2626;
        }

        /* red-600 */

        .qty-options {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 1.2rem;
            line-height: 1;
            transition: background-color 0.2s;
        }

        .addQty {
            background-color: #22c55e;
            margin-left: 1rem;
        }

        /* green-500 */
        .addQty:hover {
            background-color: #16a34a;
        }

        /* green-600 */
        .remQty {
            background-color: #f97316;
            margin-right: 1rem;
        }

        /* orange-500 */
        .remQty:hover {
            background-color: #ea580c;
        }

        /* orange-600 */

        /* Custom scrollbar for a better look */
        #category::-webkit-scrollbar,
        main::-webkit-scrollbar,
        #order-items-table+div::-webkit-scrollbar {
            width: 8px;
        }

        #category::-webkit-scrollbar-thumb,
        main::-webkit-scrollbar-thumb,
        #order-items-table+div::-webkit-scrollbar-thumb {
            background-color: #a7a7a7;
            border-radius: 10px;
        }
    </style>
</x-pos-layout>
