<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintAttempt;
use App\Models\PrintJob;
use App\Models\PrintStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AuditLogger;

class PrintStationController extends Controller
{
    public function heartbeat(Request $request)
    {
        $station = $this->station($request);

        $updates = [
            'last_seen_at' => now(),
            'version' => $request->input('version'),
            'last_ip' => $request->ip(),
        ];

        if ($request->has('last_error')) {
            $updates['last_error'] = $request->input('last_error');
        }

        $station->update($updates);

        return response()->json([
            'status' => 'success',
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'printer_map' => $station->printer_map ?: [],
            ],
        ]);
    }

    public function jobs(Request $request, AuditLogger $audit)
    {
        $station = $this->station($request);
        $limit = min(max((int) $request->input('limit', 5), 1), 10);
        $lockUntil = now()->addMinutes(config('pos.printing.lock_minutes', 2));

        $jobs = DB::transaction(function () use ($station, $limit, $lockUntil, $audit, $request) {
            $printerKeys = collect($station->printer_map ?: [])
                ->filter()
                ->keys()
                ->values();

            $exhaustedJobs = PrintJob::query()
                ->whereIn('printer_key', $printerKeys)
                ->where('status', PrintJob::STATUS_PROCESSING)
                ->where('locked_until', '<=', now())
                ->whereRaw('attempts >= COALESCE(max_attempts, 3)')
                ->lockForUpdate()
                ->get();

            foreach ($exhaustedJobs as $job) {
                $completedAt = now();
                $error = sprintf(
                    'Print job exhausted its maximum of %d attempts.',
                    $job->max_attempts ?? 3
                );

                $job->printAttempts()
                    ->where('status', PrintAttempt::STATUS_PROCESSING)
                    ->latest('attempt_number')
                    ->first()
                    ?->update([
                        'status' => PrintAttempt::STATUS_EXPIRED,
                        'completed_at' => $completedAt,
                        'error' => $error,
                    ]);

                $job->update([
                    'status' => PrintJob::STATUS_FAILED,
                    'failed_at' => $completedAt,
                    'locked_until' => null,
                    'last_error' => $error,
                ]);

                $audit->record('print_job_exhausted', 'printing', [
                    'severity' => 'error',
                    'subject' => $job,
                    'request' => $request,
                    'after' => $job->fresh()->only(['id', 'status', 'attempts', 'max_attempts', 'print_station_id', 'failed_at', 'last_error']),
                    'metadata' => [
                        'summary' => "Print job #{$job->id} exhausted.",
                        'station_id' => $station->id,
                        'printer_key' => $job->printer_key,
                    ],
                ]);
            }

            $jobs = PrintJob::availableForStation($station)
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $claimedAt = now();
                $attemptNumber = $job->attempts + 1;

                if ($job->status === PrintJob::STATUS_PROCESSING) {
                    $job->printAttempts()
                        ->where('status', PrintAttempt::STATUS_PROCESSING)
                        ->latest('attempt_number')
                        ->first()
                        ?->update([
                            'status' => PrintAttempt::STATUS_EXPIRED,
                            'completed_at' => $claimedAt,
                            'error' => 'Print station lock expired before acknowledgement.',
                        ]);
                }

                $job->update([
                    'status' => PrintJob::STATUS_PROCESSING,
                    'attempts' => $attemptNumber,
                    'last_attempted_at' => $claimedAt,
                    'locked_until' => $lockUntil,
                    'print_station_id' => $station->id,
                ]);

                $job->printAttempts()->create([
                    'attempt_number' => $attemptNumber,
                    'print_station_id' => $station->id,
                    'printer_name' => data_get($station->printer_map, $job->printer_key),
                    'status' => PrintAttempt::STATUS_PROCESSING,
                    'claimed_at' => $claimedAt,
                ]);
            }

            return $jobs;
        });

        return response()->json([
            'status' => 'success',
            'jobs' => $jobs->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'type' => $job->type,
                'printer_key' => $job->printer_key,
                'payload_format' => $job->payload_format,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
            ])->values(),
        ]);
    }

    public function printed(Request $request, PrintJob $printJob)
    {
        $station = $this->station($request);

        $canAcknowledge = $this->stationCanComplete($printJob, $station)
            || ($printJob->status === PrintJob::STATUS_PRINTED
                && (int) $printJob->print_station_id === (int) $station->id);

        if (!$canAcknowledge) {
            return response()->json(['message' => 'This print job is not locked by this station.'], 409);
        }

        $printJob->markPrinted($station);

        return response()->json(['status' => 'success']);
    }

    public function failed(Request $request, PrintJob $printJob, AuditLogger $audit)
    {
        $station = $this->station($request);

        if (!$this->stationCanComplete($printJob, $station)) {
            return response()->json(['message' => 'This print job is not locked by this station.'], 409);
        }

        $data = $request->validate([
            'error' => ['required', 'string'],
        ]);

        $printJob->markFailed($station, $data['error']);

        $audit->record('print_job_failed_acknowledgement', 'printing', [
            'severity' => $printJob->fresh()->isExhausted() ? 'error' : 'warning',
            'subject' => $printJob,
            'request' => $request,
            'after' => $printJob->fresh()->only(['id', 'status', 'attempts', 'max_attempts', 'print_station_id', 'failed_at', 'last_error']),
            'metadata' => [
                'summary' => "Print job #{$printJob->id} failed acknowledgement received.",
                'station_id' => $station->id,
                'printer_key' => $printJob->printer_key,
            ],
        ]);

        return response()->json(['status' => 'success']);
    }

    private function station(Request $request): PrintStation
    {
        return $request->attributes->get('print_station');
    }

    private function stationCanComplete(PrintJob $printJob, PrintStation $station): bool
    {
        return $printJob->status === PrintJob::STATUS_PROCESSING
            && (int) $printJob->print_station_id === (int) $station->id;
    }
}
