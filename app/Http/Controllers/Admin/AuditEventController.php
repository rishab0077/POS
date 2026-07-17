<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditEventController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditEvent::query()->with('user');
        $this->applyFilters($query, $request);

        $events = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit-events.index', [
            'events' => $events,
            'users' => User::orderBy('name')->get(['id', 'name', 'username']),
            'categories' => AuditEvent::query()->select('category')->distinct()->orderBy('category')->pluck('category'),
            'eventTypes' => AuditEvent::query()->select('event_type')->distinct()->orderBy('event_type')->pluck('event_type'),
            'severities' => AuditEvent::query()->select('severity')->distinct()->orderBy('severity')->pluck('severity'),
            'subjectTypes' => AuditEvent::query()->select('subject_type')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'filters' => $request->query(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = AuditEvent::query()->with('user');
        $this->applyFilters($query, $request);

        $filename = 'audit-events-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'timestamp',
                'category',
                'event_type',
                'severity',
                'user',
                'subject_type',
                'subject_id',
                'route',
                'ip',
                'summary',
                'before_values',
                'after_values',
                'metadata',
            ]);

            $query->latest('created_at')->latest('id')->chunk(200, function ($events) use ($handle) {
                foreach ($events as $event) {
                    fputcsv($handle, [
                        optional($event->created_at)->toDateTimeString(),
                        $event->category,
                        $event->event_type,
                        $event->severity,
                        $event->user?->name ?: ($event->user_id ? "User #{$event->user_id}" : 'System'),
                        $event->subject_type,
                        $event->subject_id,
                        $event->route_name,
                        $event->ip_address,
                        $this->summary($event),
                        json_encode($event->before_values, JSON_UNESCAPED_SLASHES),
                        json_encode($event->after_values, JSON_UNESCAPED_SLASHES),
                        json_encode($event->metadata, JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->input('category')))
            ->when($request->filled('event_type'), fn (Builder $query) => $query->where('event_type', $request->input('event_type')))
            ->when($request->filled('severity'), fn (Builder $query) => $query->where('severity', $request->input('severity')))
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('user_id', $request->input('user_id')))
            ->when($request->filled('subject_type'), fn (Builder $query) => $query->where('subject_type', $request->input('subject_type')))
            ->when($request->filled('subject_id'), fn (Builder $query) => $query->where('subject_id', $request->input('subject_id')));

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where('event_type', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('severity', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%")
                    ->orWhere('metadata', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search)
                        ->orWhere('subject_id', (int) $search)
                        ->orWhere('user_id', (int) $search);
                }
            });
        }
    }

    private function summary(AuditEvent $event): string
    {
        return data_get($event->metadata, 'summary')
            ?: trim(class_basename((string) $event->subject_type) . ($event->subject_id ? " #{$event->subject_id}" : ''))
            ?: $event->event_type;
    }
}
