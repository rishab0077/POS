<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\PrintStation;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\AuditLogger;

class PrintStationController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only(['store', 'update', 'regenerateToken', 'retry']);
    }

    public function index(Request $request)
    {
        $stations = PrintStation::query()
            ->withCount([
                'printJobs as recent_failed_jobs_count' => fn ($query) => $query
                    ->where('status', PrintJob::STATUS_FAILED)
                    ->where('failed_at', '>=', now()->subDay()),
            ])
            ->latest()
            ->get();

        $mappedPrinterKeys = $stations
            ->where('enabled', true)
            ->flatMap(fn (PrintStation $station) => array_keys($station->usablePrinterMap()))
            ->unique()
            ->values();

        $queueCounts = [
            'pending' => PrintJob::where('status', PrintJob::STATUS_PENDING)->count(),
            'processing' => PrintJob::where('status', PrintJob::STATUS_PROCESSING)->count(),
            'failed' => PrintJob::where('status', PrintJob::STATUS_FAILED)->count(),
            'printed_today' => PrintJob::where('status', PrintJob::STATUS_PRINTED)
                ->whereDate('printed_at', today())
                ->count(),
            'exhausted' => PrintJob::where('status', PrintJob::STATUS_FAILED)
                ->whereRaw('attempts >= COALESCE(max_attempts, 3)')
                ->count(),
        ];

        $jobsByPrinterKey = PrintJob::query()
            ->selectRaw('printer_key, COUNT(*) as aggregate')
            ->groupBy('printer_key')
            ->orderBy('printer_key')
            ->pluck('aggregate', 'printer_key');

        $jobsByCopyType = PrintJob::query()
            ->where(function ($query) {
                $query->whereNotNull('copy_type')->orWhere('type', 'bill');
            })
            ->selectRaw("COALESCE(copy_type, 'customer') as effective_copy_type, COUNT(*) as aggregate")
            ->groupByRaw("COALESCE(copy_type, 'customer')")
            ->orderBy('effective_copy_type')
            ->pluck('aggregate', 'effective_copy_type');

        $jobCountsByStationId = PrintJob::query()
            ->selectRaw('print_station_id, COUNT(*) as aggregate')
            ->groupBy('print_station_id')
            ->pluck('aggregate', 'print_station_id');

        $jobsByStation = $stations
            ->mapWithKeys(fn (PrintStation $station) => [
                "#{$station->id} {$station->name}" => (int) ($jobCountsByStationId[$station->id] ?? 0),
            ]);
        $jobsByStation->put('Unassigned', (int) ($jobCountsByStationId[''] ?? 0));

        $lastErrors = PrintJob::query()
            ->whereNotNull('last_error')
            ->where('last_error', '!=', '')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'printer_key', 'last_error', 'updated_at']);

        $jobsQuery = PrintJob::query()
            ->with([
                'printStation',
                'retriedBy',
                'printAttempts' => fn ($query) => $query->orderByDesc('attempt_number'),
                'printAttempts.printStation',
                'source' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        Bill::class => ['lockedBy', 'orders.waiter'],
                        Order::class => ['waiter'],
                    ]);
                },
            ]);

        $this->applyJobFilters($jobsQuery, $request, $mappedPrinterKeys->all());

        $jobs = $jobsQuery
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.print-stations.index', compact(
            'stations',
            'jobs',
            'queueCounts',
            'jobsByPrinterKey',
            'jobsByCopyType',
            'jobsByStation',
            'lastErrors',
            'mappedPrinterKeys',
        ));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $data = $this->validatedStationData($request);
        $token = 'ps_' . Str::random(48);

        $printStation = PrintStation::create([
            'name' => $data['name'],
            'token_hash' => PrintStation::hashToken($token),
            'enabled' => $request->boolean('enabled', true),
            'printer_map' => $this->printerMap($data),
        ]);

        $audit->record('print_station_created', 'printing', [
            'subject' => $printStation,
            'after' => $printStation->only(['id', 'name', 'enabled', 'printer_map']),
            'metadata' => ['summary' => "Print station {$printStation->name} created."],
        ]);

        return redirect()
            ->route('admin.print-stations.index')
            ->with('success', 'Print station created. Copy the token now; it will not be shown again.')
            ->with('print_station_token', $token);
    }

    public function update(Request $request, PrintStation $printStation, AuditLogger $audit)
    {
        $data = $this->validatedStationData($request);
        $before = $printStation->only(['id', 'name', 'enabled', 'printer_map']);

        $printStation->update([
            'name' => $data['name'],
            'enabled' => $request->boolean('enabled'),
            'printer_map' => $this->printerMap($data),
        ]);

        $audit->record('print_station_updated', 'printing', [
            'subject' => $printStation,
            'before' => $before,
            'after' => $printStation->fresh()->only(['id', 'name', 'enabled', 'printer_map']),
            'metadata' => ['summary' => "Print station {$printStation->name} updated."],
        ]);

        return redirect()
            ->route('admin.print-stations.index')
            ->with('success', 'Print station updated.');
    }

    public function regenerateToken(PrintStation $printStation, AuditLogger $audit)
    {
        $token = 'ps_' . Str::random(48);
        $printStation->update(['token_hash' => PrintStation::hashToken($token)]);

        $audit->record('print_station_token_regenerated', 'printing', [
            'subject' => $printStation,
            'metadata' => ['summary' => "Print station {$printStation->name} token regenerated."],
        ]);

        return redirect()
            ->route('admin.print-stations.index')
            ->with('success', 'Print station token regenerated. Copy the token now; it will not be shown again.')
            ->with('print_station_token', $token);
    }

    public function retry(Request $request, PrintJob $printJob, AuditLogger $audit)
    {
        abort_unless($printJob->retry($request->user()), 409, 'Only failed print jobs can be retried.');

        $audit->record('print_job_manual_retry', 'printing', [
            'subject' => $printJob,
            'after' => $printJob->fresh()->only(['id', 'status', 'attempts', 'max_attempts', 'retried_by', 'retried_at']),
            'metadata' => [
                'summary' => "Print job #{$printJob->id} queued for manual retry.",
                'type' => $printJob->type,
                'printer_key' => $printJob->printer_key,
            ],
        ]);

        return redirect()
            ->route('admin.print-stations.index')
            ->with('success', 'Print job queued for retry.');
    }

    private function validatedStationData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'kitchen_printer' => ['nullable', 'string', 'max:255'],
            'bar_printer' => ['nullable', 'string', 'max:255'],
            'counter_printer' => ['nullable', 'string', 'max:255'],
            'biller_printer' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function printerMap(array $data): array
    {
        return collect([
            'kitchen' => $data['kitchen_printer'] ?? null,
            'bar' => $data['bar_printer'] ?? null,
            'counter' => $data['counter_printer'] ?? null,
            'biller' => $data['biller_printer'] ?? null,
        ])->filter()->all();
    }

    private function applyJobFilters($query, Request $request, array $mappedPrinterKeys): void
    {
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('printer_key')) {
            $query->where('printer_key', (string) $request->string('printer_key'));
        }

        if ($request->filled('copy_type')) {
            $copyType = (string) $request->string('copy_type');
            $query->where(function ($query) use ($copyType) {
                $query->where('copy_type', $copyType);
                if ($copyType === 'customer') {
                    $query->orWhereNull('copy_type');
                }
            });
        }

        if ($request->filled('station')) {
            $request->input('station') === 'unassigned'
                ? $query->whereNull('print_station_id')
                : $query->where('print_station_id', (int) $request->input('station'));
        }

        if ($request->filled('type')) {
            $type = match ((string) $request->string('type')) {
                'summary' => 'summary_bill',
                'duplicate' => 'duplicate_bill',
                default => (string) $request->string('type'),
            };
            $query->where('type', $type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->boolean('failed_only')) {
            $query->where('status', PrintJob::STATUS_FAILED);
        }

        if ($request->boolean('unclaimable_only')) {
            $query
                ->whereNotNull('printer_key')
                ->when(
                    $mappedPrinterKeys !== [],
                    fn ($query) => $query->whereNotIn('printer_key', $mappedPrinterKeys)
                )
                ->where(function ($query) {
                    $query
                        ->where('status', PrintJob::STATUS_PENDING)
                        ->orWhere(function ($query) {
                            $query
                                ->where('status', PrintJob::STATUS_PROCESSING)
                                ->whereNotNull('locked_until')
                                ->where('locked_until', '<=', now());
                        });
                });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $billIds = Bill::query()
                ->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('bill_id', 'like', "%{$search}%")
                ->pluck('id');
            $orderIds = Order::query()
                ->where('KOT', 'like', "%{$search}%")
                ->pluck('id');

            $query->where(function ($query) use ($search, $billIds, $orderIds) {
                $query->where('idempotency_key', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }

                if ($billIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($billIds) {
                        $query->where('source_type', Bill::class)->whereIn('source_id', $billIds);
                    });
                }

                if ($orderIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($orderIds) {
                        $query->where('source_type', Order::class)->whereIn('source_id', $orderIds);
                    });
                }
            });
        }
    }
}
