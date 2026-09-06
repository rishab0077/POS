<?php

namespace App\Http\Controllers\POS;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Helpers\BillHelper;
use App\Helpers\TableHelper;
use App\Http\Controllers\Controller;
use App\Http\Service\MenuService;
use App\Http\Service\RestaurantService;
use App\Http\Service\TableService;
use App\Models\Bill;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\Services\PrintJobService;
use App\Models\BillOrder;
use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Support\Facades\DB;
use App\Services\RecentAuthenticationService;
use App\Services\AuditLogger;

class PosController extends Controller
{
    private $menuService;
    private $tableService;
    private $restaurantService;
    private $printJobService;

    public function __construct(MenuService $menuService, TableService $tableService, RestaurantService $restaurantService, PrintJobService $printJobService)
    {
        $this->menuService = $menuService;
        $this->tableService = $tableService;
        $this->restaurantService = $restaurantService;
        $this->printJobService = $printJobService;
        $this->middleware('stepup')->only('transferTable');
    }
    public function index(Request $request)
    {
        $tableId = $request->tableId;

        $orderType = $tableId === 'takeaway' ? OrderType::Takeaway : OrderType::DineIn;

        // Get categories with menus, predefined notes, and payment types
        $categoriesWithMenus = $this->menuService->getCatergoriesWithMenus();
        $predefinedNotes = config('predefined_options.notes');
        $paymentTypes = config('pos.payments');

        // Initialize variables
        $table = null;
        $existingOrderTotal = 0;
        $existingLoyaltyCandidates = collect();

        if ($orderType === OrderType::DineIn) {
            $table = Table::find($tableId);

            // Redirect if the table is not found
            if (!$table) {
                return redirect()->route('pos.tables');
            }

            if (!in_array($table->status, [TableStatus::Available, TableStatus::Running, TableStatus::RunningKOT], true)) {
                return redirect()
                    ->route('pos.tables')
                    ->with('warning', 'This table is finalized or unavailable and cannot accept more orders.');
            }

            $existingOrderTotal = (float) data_get(
                $this->billingGroupsForTables([$table->id]),
                $table->id . '.total',
                0
            );

            $finalizedOrderIds = BillOrder::whereHas('bill', function ($query) {
                $query->where('status', 'closed')
                    ->orWhereNotNull('locked_at')
                    ->orWhereNotNull('printed_at');
            })->pluck('order_id');

            $existingLoyaltyCandidates = OrderDetail::with(['menu.category', 'order'])
                ->whereHas('order', function ($query) use ($table, $finalizedOrderIds) {
                    $query->where('table_id', $table->id)
                        ->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])
                        ->whereNotIn('id', $finalizedOrderIds);
                })
                ->get()
                ->filter(fn (OrderDetail $detail) => $detail->menu?->category->contains('loyalty_eligible', true))
                ->groupBy('menu_id')
                ->map(fn ($items) => [
                    'menu_id' => $items->first()->menu_id,
                    'name' => $items->first()->menu?->name ?? 'Deleted menu item',
                    'quantity' => (int) $items->sum('quantity'),
                    'unit_price' => (float) $items->first()->unit_price,
                ])->values();
        }

        return view('pos.pos-index', compact(
            'categoriesWithMenus',
            'predefinedNotes',
            'paymentTypes',
            'table',
            'orderType',
            'existingOrderTotal',
            'existingLoyaltyCandidates'
        ));
    }

    public function selectTable()
    {
        $tables = $this->tableService->getTablesWithOrderSums();
        $tablesWithLocations = $tables->groupBy('location.name');

        $table_colors =  config('predefined_options.table_colors');

        $paymentTypes = config('pos.payments');

        $tableBillingGroups = $this->billingGroupsForTables($tables->pluck('id')->all());
        $latestFinalizedBillIds = Bill::whereIn('table_id', $tables->pluck('id'))
            ->where(function ($query) {
                $query->whereNotNull('locked_at')
                    ->orWhereNotNull('printed_at')
                    ->orWhere('status', 'closed');
            })
            ->latest('id')
            ->get()
            ->unique('table_id')
            ->pluck('id', 'table_id');

        return view('pos.tables', compact(
            'tablesWithLocations',
            'table_colors',
            'paymentTypes',
            'tableBillingGroups',
            'latestFinalizedBillIds'
        ));
    }

    public function billTable(Request $request, AuditLogger $audit)
    {
        $tableId = $request->tableId;
        $paymentMethods = array_keys(config('pos.payments'));
        $directMethods = array_values(array_diff($paymentMethods, ['credit']));
        $request->validate([
            'billAction' => ['nullable', Rule::in(['summary', 'final'])],
            'billingSource' => ['nullable', 'string'],
            'paymentType' => ['nullable', Rule::in([...$paymentMethods, 'split'])],
            'payments' => ['nullable', 'required_if:paymentType,split', 'array', 'size:2'],
            'payments.*.method' => ['required', Rule::in($directMethods)],
            'payments.*.amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'print_copies' => ['nullable', Rule::in(['customer', 'both'])],
            'discount_type' => ['nullable', Rule::in(['amount', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'loyalty_rewards' => ['nullable', 'array'],
            'loyalty_rewards.*.menu_id' => ['required', 'integer', 'distinct', 'exists:menus,id'],
            'loyalty_rewards.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $notes = $request->notes ? $request->notes : '';
        $sourceTableId = $this->billingSourceTableId($request->input('billingSource'));
        $buyerData = $request->only([
            'buyer_name',
            'buyer_pan',
            'buyer_address',
            'discount_reason',
            'credit_customer_name',
            'credit_customer_contact',
        ]);
        $billAction = $request->input('billAction', 'final');

        if (
            (float) $request->input('discount_value', 0) > 0
            && !app(RecentAuthenticationService::class)->isRecent($request)
        ) {
            $request->session()->put('auth.step_up_return_to', route('pos.tables'));

            return response()->json([
                'message' => 'Confirm your password and MFA before applying a discount.',
                'step_up_url' => route('password.confirm'),
            ], 423);
        }

        if ($request->has('printDuplicateBill')) {
            if (!$this->restaurantService->hasEnabledPrintStationFor('counter')) {
                throw ValidationException::withMessages([
                    'printer' => 'Configure an enabled counter print station before requesting a duplicate bill.',
                ]);
            }

            $billId = BillHelper::getLatestBillId($tableId);
            $job = $this->printJobService->queueBill($billId, true);

            $audit->record('duplicate_bill_requested', 'billing', [
                'subject' => $job,
                'metadata' => [
                    'summary' => "Duplicate bill print requested for bill #{$billId}.",
                    'bill_id' => $billId,
                    'print_job_id' => $job->id,
                ],
            ]);

            return response()->json(['status' => 'success', 'message' => 'Duplicate bill queued for printing']);
        }

        if (
            $billAction === 'summary'
            && !$this->restaurantService->hasEnabledPrintStationFor('counter')
        ) {
            throw ValidationException::withMessages([
                'printer' => 'Configure an enabled counter print station before printing an order summary.',
            ]);
        }

        $billId = BillHelper::createTableBill($tableId, $notes, null, 0, [], $sourceTableId);

        if ($billAction === 'summary') {
            $job = $this->printJobService->queueSummaryBill($billId);

            $audit->record('summary_bill_requested', 'billing', [
                'subject' => $job,
                'metadata' => [
                    'summary' => "Summary bill print requested for bill #{$billId}.",
                    'bill_id' => $billId,
                    'print_job_id' => $job->id,
                ],
            ]);

            return response()->json(['status' => 'success', 'billId' => $billId, 'message' => 'Summary bill queued for printing']);
        }

        $paymentType = $request->paymentType ? $request->paymentType : 'cash';
        $discountData = $request->only(['discount_type', 'discount_value']);
        $bill = BillHelper::finalizeBill(
            $billId,
            $paymentType,
            $buyerData,
            $discountData,
            false,
            $request->input('payments', []),
            $request->input('loyalty_rewards', [])
        );
        $bill->load('orders.orderDetails.menu');

        $audit->record('bill_finalized', 'billing', [
            'subject' => $bill,
            'after' => $bill->only([
                'id',
                'bill_id',
                'invoice_no',
                'table_id',
                'source_table_id',
                'bill_amount',
                'discount',
                'discount_type',
                'discount_value',
                'taxable_amount',
                'vat_amount',
                'grand_total',
                'payment_method',
                'status',
                'locked_at',
                'locked_by',
            ]),
            'metadata' => [
                'summary' => "Bill {$bill->invoice_no} finalized.",
                'order_ids' => $bill->orders->pluck('id')->all(),
                'payments' => $bill->payments->map->only(['payment_method', 'amount', 'reference_no'])->all(),
            ],
        ]);

        if ((float) $bill->discount > 0) {
            $audit->record('discount_applied', 'billing', [
                'subject' => $bill,
                'after' => $bill->only(['id', 'discount', 'discount_type', 'discount_value', 'discount_reason', 'discount_approved_by']),
                'metadata' => ['summary' => "Discount applied to bill {$bill->invoice_no}."],
            ]);
        }

        $loyaltyItems = $bill->orders->flatMap->orderDetails->where('loyalty_reward_quantity', '>', 0);
        if ($loyaltyItems->isNotEmpty()) {
            $audit->record('loyalty_reward_applied', 'billing', [
                'subject' => $bill,
                'metadata' => [
                    'summary' => 'Physical stamp-card rewards applied during final payment.',
                    'items' => $loyaltyItems->map(fn (OrderDetail $item) => [
                        'menu_id' => $item->menu_id,
                        'item_name' => $item->menu?->name,
                        'quantity' => (int) $item->loyalty_reward_quantity,
                        'original_unit_price' => (float) $item->loyalty_original_unit_price,
                    ])->values()->all(),
                ],
            ]);
        }

        $tableStatus = 'running';
        if (BillHelper::activeTableOrdersFinalized($tableId)) {
            TableHelper::markTableAsFinalized($tableId);
            $tableStatus = 'printed';
        }

        $counterPrinterConfigured = $this->restaurantService->hasEnabledPrintStationFor('counter');
        $counterPrinterOnline = $this->restaurantService->hasOnlinePrintStationFor('counter');

        if ($counterPrinterConfigured) {
            $this->printJobService->queueBillCopies($bill->id, $request->input('print_copies'));
        }

        $message = match (true) {
            $counterPrinterOnline => 'Bill finalized and queued for printing.',
            $counterPrinterConfigured => 'Bill finalized. The counter print station is offline, so the print job will remain pending.',
            default => 'Bill finalized without a print job because no enabled counter print station is configured.',
        };

        return response()->json([
            'status' => 'success',
            'billId' => $bill->id,
            'tableStatus' => $tableStatus,
            'previewUrl' => route('pos.bill.preview', $bill->id, false),
            'printerConfigured' => $counterPrinterConfigured,
            'printerOnline' => $counterPrinterOnline,
            'message' => $message,
        ]);
    }


    public function settleTable(Request $request)
    {
        $tableId = $request->tableId;

        $table = Table::find($tableId);

        if (!$table) {
            return response()->json(['status' => 'error', 'message' => 'Table not found']);
        }

        $finalBills = Bill::where('table_id', $request->tableId)
            ->where('status', 'open')
            ->where(function ($query) {
                $query->whereNotNull('locked_at')
                    ->orWhereNotNull('printed_at');
            })
            ->get();

        if ($finalBills->isEmpty()) {
            throw ValidationException::withMessages(['bill' => 'No bill found for this table.']);
        }

        if (!BillHelper::activeTableOrdersFinalized($tableId)) {
            throw ValidationException::withMessages(['bill' => 'Print final bills for all active orders before settling this table.']);
        }

        TableHelper::markTableAsPaid($request->tableId);

        $orders = $table->orders()->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])->get();

        foreach ($orders as $order) {
            $order->status = OrderStatus::Closed;
            $order->save();
        }

        foreach ($finalBills as $bill) {
            $bill->status = 'closed';
            $bill->save();
        }

        return response()->json(['status' => 'success', 'message' => 'Table settled']);
    }

    public function transferTable(Request $request, AuditLogger $audit)
    {
        if (!auth()->user()?->canTransferTables()) {
            abort(403, 'Only Admin, Owner, or Manager can transfer tables.');
        }

        $request->validate([
            'source_table_id' => ['required', 'integer', 'exists:tables,id'],
            'target_table_id' => ['required', 'integer', 'exists:tables,id', 'different:source_table_id'],
        ]);

        return DB::transaction(function () use ($request, $audit) {
            $source = Table::lockForUpdate()->findOrFail($request->source_table_id);
            $target = Table::lockForUpdate()->findOrFail($request->target_table_id);

            if (!in_array($source->status, [TableStatus::Running, TableStatus::RunningKOT], true)) {
                throw ValidationException::withMessages(['source_table_id' => 'Only running tables can be transferred.']);
            }

            if (!in_array($target->status, [TableStatus::Available, TableStatus::Running, TableStatus::RunningKOT], true)) {
                throw ValidationException::withMessages(['target_table_id' => 'Transfer target must be an empty or running table.']);
            }

            $sourceOrders = Order::where('table_id', $source->id)
                ->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])
                ->lockForUpdate()
                ->get();

            if ($sourceOrders->isEmpty()) {
                throw ValidationException::withMessages(['source_table_id' => 'There are no active orders to transfer.']);
            }

            $this->ensureOrdersAreEditableForTransfer($sourceOrders->pluck('id')->all());

            $targetOrderIds = Order::where('table_id', $target->id)
                ->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])
                ->pluck('id')
                ->all();

            $this->ensureOrdersAreEditableForTransfer($targetOrderIds);

            $sourceTakenAt = $source->taken_at;

            foreach ($sourceOrders as $order) {
                $order->source_table_id = $order->source_table_id ?: $source->id;
                $order->table_id = $target->id;
                $order->save();
            }

            $editableBills = Bill::where('table_id', $source->id)
                ->where('status', 'open')
                ->whereNull('locked_at')
                ->whereNull('printed_at')
                ->get();

            foreach ($editableBills as $bill) {
                $bill->source_table_id = $bill->source_table_id ?: $source->id;
                $bill->table_id = $target->id;
                $bill->save();
            }

            $source->status = TableStatus::Available;
            $source->taken_at = null;
            $source->save();

            if ($target->status === TableStatus::Available) {
                $target->taken_at = $sourceTakenAt ?: now();
            }

            $target->status = TableStatus::Running;
            $target->save();

            $audit->record($targetOrderIds ? 'table_merge' : 'table_transfer', 'billing', [
                'subject' => $target,
                'before' => [
                    'source_table_id' => $source->id,
                    'target_table_id' => $target->id,
                    'target_existing_order_ids' => $targetOrderIds,
                ],
                'after' => [
                    'moved_order_ids' => $sourceOrders->pluck('id')->all(),
                    'moved_bill_ids' => $editableBills->pluck('id')->all(),
                    'source_status' => $source->fresh()->status?->value,
                    'target_status' => $target->fresh()->status?->value,
                ],
                'metadata' => [
                    'summary' => $targetOrderIds ? 'Tables merged.' : 'Table transferred.',
                ],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => $targetOrderIds ? 'Table merged successfully.' : 'Table transferred successfully.',
            ]);
        });
    }

    //tableOrders
    public function tableOrders($tableId)
    {
        $table = Table::findOrFail($tableId);

        $orders = Order::with('orderDetails')->with('orderDetails.menu')->with('sourceTable')
            ->where('table_id', $tableId)
            ->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])
            ->get();


        $billedOrders = Bill::where('table_id', $tableId)->where('created_at', '>=', $table->taken_at)->with('orders')->get();

        return view('pos.table-orders', compact('orders', 'table', 'billedOrders'));
    }

    private function billingSourceTableId(?string $billingSource): ?int
    {
        if (!$billingSource || $billingSource === 'all') {
            return null;
        }

        return (int) $billingSource;
    }

    private function ensureOrdersAreEditableForTransfer(array $orderIds): void
    {
        if (empty($orderIds)) {
            return;
        }

        $hasFinalBill = BillOrder::whereIn('order_id', $orderIds)
            ->whereHas('bill', function ($query) {
                $query->where('status', 'closed')
                    ->orWhereNotNull('locked_at')
                    ->orWhereNotNull('printed_at');
            })
            ->exists();

        if ($hasFinalBill) {
            throw ValidationException::withMessages(['table' => 'Tables cannot be transferred after a bill has been finalized.']);
        }
    }

    private function billingGroupsForTables(array $tableIds): array
    {
        $finalizedOrderIds = BillOrder::whereHas('bill', function ($query) {
            $query->where('status', 'closed')
                ->orWhereNotNull('locked_at')
                ->orWhereNotNull('printed_at');
        })->pluck('order_id');

        $orders = Order::with(['table', 'sourceTable', 'orderDetails.menu.category'])
            ->whereIn('table_id', $tableIds)
            ->whereNotIn('status', [OrderStatus::Closed->value, OrderStatus::Cancelled->value])
            ->whereNotIn('id', $finalizedOrderIds)
            ->get();

        $groups = [];

        foreach ($orders->groupBy('table_id') as $tableId => $tableOrders) {
            $sourceGroups = [];

            foreach ($tableOrders as $order) {
                $sourceTableId = $order->source_table_id ?: $order->table_id;
                $sourceName = $order->sourceTable?->name ?? $order->table?->name ?? ('Table ' . $sourceTableId);

                if (!isset($sourceGroups[$sourceTableId])) {
                    $sourceGroups[$sourceTableId] = [
                        'id' => $sourceTableId,
                        'name' => $sourceName,
                        'total' => 0,
                        'orders' => 0,
                        'loyalty_items' => [],
                    ];
                }

                $sourceGroups[$sourceTableId]['total'] += (float) $order->total;
                $sourceGroups[$sourceTableId]['orders']++;

                foreach ($order->orderDetails as $detail) {
                    if (!$detail->menu?->category->contains('loyalty_eligible', true)) {
                        continue;
                    }

                    $menuId = $detail->menu_id;
                    if (!isset($sourceGroups[$sourceTableId]['loyalty_items'][$menuId])) {
                        $sourceGroups[$sourceTableId]['loyalty_items'][$menuId] = [
                            'menu_id' => $menuId,
                            'name' => $detail->menu->name,
                            'quantity' => 0,
                            'unit_price' => (float) $detail->unit_price,
                        ];
                    }

                    $sourceGroups[$sourceTableId]['loyalty_items'][$menuId]['quantity'] += (int) $detail->quantity;
                }
            }

            foreach ($sourceGroups as &$sourceGroup) {
                $sourceGroup['loyalty_items'] = array_values($sourceGroup['loyalty_items']);
            }
            unset($sourceGroup);

            $groups[$tableId] = [
                'total' => array_sum(array_column($sourceGroups, 'total')),
                'sources' => array_values($sourceGroups),
                'loyalty_items' => collect($sourceGroups)
                    ->flatMap(fn (array $source) => $source['loyalty_items'])
                    ->groupBy('menu_id')
                    ->map(fn ($items) => [
                        'menu_id' => $items->first()['menu_id'],
                        'name' => $items->first()['name'],
                        'quantity' => $items->sum('quantity'),
                        'unit_price' => $items->first()['unit_price'],
                    ])->values()->all(),
            ];
        }

        return $groups;
    }

}
