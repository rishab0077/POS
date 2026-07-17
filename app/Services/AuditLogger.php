<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogger
{
    private const SENSITIVE_EXACT_KEYS = [
        'app_key',
        'db_password',
        'pusher_app_secret',
        'print_service_client_secret',
        'password',
        'password_confirmation',
        'remember_token',
        '_token',
        'csrf_token',
        'xsrf_token',
        'authorization',
        'php_auth_pw',
        'session',
        'session_payload',
    ];

    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'authorization',
    ];

    public function record(
        string $eventType,
        string $category,
        array $attributes = []
    ): ?AuditEvent {
        if (!config('audit.enabled', true)) {
            return null;
        }

        try {
            $request = $attributes['request'] ?? request();
            $user = $attributes['user'] ?? ($request instanceof Request ? $request->user() : auth()->user());
            $subject = $attributes['subject'] ?? null;

            return AuditEvent::create([
                'event_type' => $eventType,
                'category' => $category,
                'severity' => $attributes['severity'] ?? 'info',
                'user_id' => $user instanceof User ? $user->id : ($attributes['user_id'] ?? null),
                'user_role' => $this->userRole($user),
                'ip_address' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $request instanceof Request ? $request->userAgent() : null,
                'route_name' => $request instanceof Request && $request->route() ? $request->route()->getName() : null,
                'request_id' => $this->requestId($request),
                'subject_type' => $this->subjectType($subject, $attributes),
                'subject_id' => $this->subjectId($subject, $attributes),
                'before_values' => $this->redact($attributes['before'] ?? null),
                'after_values' => $this->redact($attributes['after'] ?? null),
                'metadata' => $this->redact($attributes['metadata'] ?? null),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Audit event write failed.', [
                'event_type' => $eventType,
                'category' => $category,
                'exception' => $exception->getMessage(),
            ]);

            if (config('audit.fail_closed', false)) {
                throw $exception;
            }

            return null;
        }
    }

    public function redact(mixed $value): mixed
    {
        if ($value instanceof Model) {
            $value = $value->attributesToArray();
        }

        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
        }

        if (!is_array($value)) {
            return $this->redactScalar($value);
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = $this->isSensitiveKey((string) $key)
                ? config('audit.redacted_value', '[REDACTED]')
                : $this->redact($item);
        }

        return $redacted;
    }

    private function redactScalar(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('/^\s*bearer\s+[a-z0-9._~+\/=-]+/i', $value)) {
            return config('audit.redacted_value', '[REDACTED]');
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.', ' '], '_', $key));

        if (in_array($normalized, self::SENSITIVE_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        if (str_contains($normalized, 'key')) {
            return !in_array($normalized, [
                'id',
                'user_id',
                'subject_id',
                'category_id',
                'table_id',
                'source_table_id',
                'stock_item_id',
                'supplier_id',
                'purchase_invoice_id',
                'menu_item_id',
                'bill_id',
                'order_id',
                'print_job_id',
                'print_station_id',
            ], true) && !str_ends_with($normalized, '_id');
        }

        return false;
    }

    private function userRole(mixed $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        return $user->category_id?->name ?? (string) $user->category_id?->value;
    }

    private function requestId(mixed $request): ?string
    {
        if (!$request instanceof Request) {
            return null;
        }

        return $request->headers->get('X-Request-Id') ?: $request->headers->get('X-Correlation-Id');
    }

    private function subjectType(mixed $subject, array $attributes): ?string
    {
        if ($subject instanceof Model) {
            return get_class($subject);
        }

        return Arr::get($attributes, 'subject_type');
    }

    private function subjectId(mixed $subject, array $attributes): ?int
    {
        if ($subject instanceof Model) {
            return (int) $subject->getKey();
        }

        $subjectId = Arr::get($attributes, 'subject_id');

        return is_numeric($subjectId) ? (int) $subjectId : null;
    }
}
