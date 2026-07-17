<?php

namespace App\Http\Service;

use App\Helpers\DateHelper;
use App\Models\Bill;
use App\Models\Category;
use App\Models\CreditPayment;
use App\Models\OrderDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportingService extends Service
{
    public function salesByItemReport($startDate, $endDate)
    {
        [$startDate, $endDate] = DateHelper::formatDatesForReport($startDate, $endDate);


        $data = DB::table('order_details')
            ->join('menus', 'order_details.menu_id', '=', 'menus.id')
            ->join('bill_orders', 'bill_orders.order_id', '=', 'order_details.order_id')
            ->join('bills', 'bills.id', '=', 'bill_orders.bill_id')
            ->whereNull('bill_orders.deleted_at')
            ->whereNull('bills.deleted_at')
            ->whereNotNull('bills.locked_at')
            ->whereBetween('bills.locked_at', [$startDate, $endDate])
            ->groupBy('order_details.menu_id', 'menus.name', 'order_details.unit_price', 'menus.price')
            ->selectRaw('menus.name as menu')
            ->selectRaw('COALESCE(order_details.unit_price, menus.price) as price')
            ->selectRaw('SUM(order_details.quantity) as no_of_sales')
            ->selectRaw('SUM(order_details.quantity * COALESCE(order_details.unit_price, menus.price)) as total_amount')
            ->orderBy('no_of_sales', 'desc')
            ->get();

        return $this->successResponse("success", "Sales by item report generated successfully", $data);
    }

    public function salesByCategoryReport($startDate, $endDate)
    {
        [$startDate, $endDate] = DateHelper::formatDatesForReport($startDate, $endDate);

        $catergories = Category::all();

        $data = [];

        foreach ($catergories as $category) {
            $categoryData = DB::table('order_details')
                ->join('menus', 'order_details.menu_id', '=', 'menus.id')
                ->join('category_menu', 'menus.id', '=', 'category_menu.menu_id')
                ->join('bill_orders', 'bill_orders.order_id', '=', 'order_details.order_id')
                ->join('bills', 'bills.id', '=', 'bill_orders.bill_id')
                ->where('category_menu.category_id', $category->id)
                ->whereNull('bill_orders.deleted_at')
                ->whereNull('bills.deleted_at')
                ->whereNotNull('bills.locked_at')
                ->whereBetween('bills.locked_at', [$startDate, $endDate])
                ->groupBy('order_details.menu_id', 'order_details.unit_price', 'menus.price')
                ->selectRaw('SUM(order_details.quantity) as no_of_sales')
                ->selectRaw('SUM(order_details.quantity * COALESCE(order_details.unit_price, menus.price)) as total_amount')
                ->orderBy('no_of_sales', 'desc')
                ->get();


            // add the category name
            $data[$category->id]['category'] = $category->name;

            // add the total number of sales for the category
            $data[$category->id]['no_of_sales'] = $categoryData->sum('no_of_sales');

            // add the total sales for the category
            $data[$category->id]['total_amount'] = $categoryData->sum('total_amount');
        }

        // convert the data to an array
        $data = array_values($data);
        return $this->successResponse("success", "Sales by category report generated successfully", $data);
    }

    public function dailyPaymentSummary(string $date): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $end = Carbon::parse($date)->endOfDay();

        $directBills = $this->finalizedBillsBetween($start, $end)
            ->where(function ($query) {
                $query->whereNull('payment_method')
                    ->orWhere('payment_method', '!=', 'credit');
            })
            ->get();

        $creditPayments = CreditPayment::whereBetween('paid_at', [$start, $end])->get();

        $directByMethod = $directBills
            ->groupBy(fn (Bill $bill) => $bill->payment_method ?: 'cash')
            ->map(fn ($bills) => round((float) $bills->sum('grand_total'), 2));

        $creditByMethod = $creditPayments
            ->groupBy('payment_method')
            ->map(fn ($payments) => round((float) $payments->sum('amount'), 2));

        $preferredOrder = collect(['cash', 'card', 'esewa', 'khalti', 'fonepay']);
        $observedMethods = $directByMethod->keys()->merge($creditByMethod->keys());
        $methods = $preferredOrder
            ->merge($observedMethods)
            ->unique()
            ->reject(fn ($method) => $method === 'credit')
            ->values();

        $labels = array_merge(config('pos.payments'), config('pos.legacy_payments'));
        $breakdown = $methods->map(function ($method) use ($directByMethod, $creditByMethod, $labels) {
            $direct = (float) $directByMethod->get($method, 0);
            $creditCollection = (float) $creditByMethod->get($method, 0);

            return [
                'method' => $method,
                'label' => $labels[$method] ?? ucfirst(str_replace('_', ' ', $method)),
                'direct_sales' => round($direct, 2),
                'credit_collections' => round($creditCollection, 2),
                'total' => round($direct + $creditCollection, 2),
            ];
        })->all();

        $walletMethods = ['esewa', 'khalti', 'fonepay'];
        $wallet = collect($breakdown)->whereIn('method', $walletMethods);

        $allInvoices = $this->finalizedBillsBetween($start, $end)->get();
        $creditIssued = $allInvoices->where('payment_method', 'credit');

        $creditBillsThroughDate = Bill::withSum([
            'creditPayments as paid_through_date' => function ($query) use ($end) {
                $query->where('paid_at', '<=', $end);
            },
        ], 'amount')
            ->where('payment_method', 'credit')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $end)
            ->get();

        return [
            'date' => $start->toDateString(),
            'breakdown' => $breakdown,
            'totals' => [
                'collected_sales' => round((float) collect($breakdown)->sum('total'), 2),
                'direct_sales' => round((float) $directBills->sum('grand_total'), 2),
                'credit_collections' => round((float) $creditPayments->sum('amount'), 2),
                'invoice_total' => round((float) $allInvoices->sum('grand_total'), 2),
                'invoice_count' => $allInvoices->count(),
                'credit_issued' => round((float) $creditIssued->sum('grand_total'), 2),
                'discount' => round((float) $allInvoices->sum('discount'), 2),
                'vat' => round((float) $allInvoices->sum('vat_amount'), 2),
                'outstanding_credit' => round((float) $creditBillsThroughDate->sum(function (Bill $bill) {
                    return max((float) $bill->grand_total - (float) ($bill->paid_through_date ?? 0), 0);
                }), 2),
            ],
            'wallet' => [
                'direct_sales' => round((float) $wallet->sum('direct_sales'), 2),
                'credit_collections' => round((float) $wallet->sum('credit_collections'), 2),
                'total' => round((float) $wallet->sum('total'), 2),
            ],
        ];
    }

    public function discountReport(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $bills = $this->finalizedBillsBetween($start, $end)
            ->with(['table', 'sourceTable', 'discountApprover'])
            ->where('discount', '>', 0)
            ->orderBy('locked_at')
            ->get();

        $reasonLabels = config('pos.discount_reasons');
        $byReason = $bills
            ->groupBy(fn (Bill $bill) => $bill->discount_reason ?: 'unrecorded')
            ->map(function ($reasonBills, $reason) use ($reasonLabels) {
                return [
                    'reason' => $reason,
                    'label' => $reasonLabels[$reason] ?? ucfirst(str_replace('_', ' ', $reason)),
                    'count' => $reasonBills->count(),
                    'amount' => round((float) $reasonBills->sum('discount'), 2),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'bills' => $bills,
            'by_reason' => $byReason,
            'totals' => [
                'bill_count' => $bills->count(),
                'discount_amount' => round((float) $bills->sum('discount'), 2),
                'sales_before_discount' => round((float) $bills->sum('bill_amount'), 2),
                'sales_after_discount' => round((float) $bills->sum('grand_total'), 2),
            ],
        ];
    }

    private function finalizedBillsBetween(Carbon $start, Carbon $end)
    {
        return Bill::query()
            ->whereNotNull('locked_at')
            ->whereBetween('locked_at', [$start, $end]);
    }
}
