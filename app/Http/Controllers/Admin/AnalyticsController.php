<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Service\ReportingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    private $reportingService;

    public function __construct()
    {
        $this->reportingService = new ReportingService();
    }

    public function index()
    {
        return view('admin.analytics.index');
    }

    public function view(Request $request)
    {
        return match ($request->route('report')) {
            'sales-by-item' => view('admin.analytics.salesByItem'),
            'sales-by-category' => view('admin.analytics.salesByCategory'),
            default => abort(404),
        };
    }

    public function salesByItemData(Request $request)
    {
        $response = $this->reportingService->salesByItemReport($request->startDate, $request->endDate);

        return response()->json($response);
    }

    public function salesByCategoryData(Request $request)
    {
        $response = $this->reportingService->salesByCategoryReport($request->startDate, $request->endDate);

        return response()->json($response);
    }

    public function dailySummary(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $summary = $this->reportingService->dailyPaymentSummary($date);

        return view('admin.analytics.daily-summary', compact('summary'));
    }

    public function discounts(Request $request)
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $startDate = $data['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $data['end_date'] ?? Carbon::parse($startDate)->endOfMonth()->toDateString();

        if (Carbon::parse($endDate)->lt(Carbon::parse($startDate))) {
            throw ValidationException::withMessages([
                'end_date' => 'End date must be on or after the start date.',
            ]);
        }

        $report = $this->reportingService->discountReport($startDate, $endDate);

        return view('admin.analytics.discounts', compact('report'));
    }
}
