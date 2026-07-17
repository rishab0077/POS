<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_type',
        'category',
        'severity',
        'user_id',
        'user_role',
        'ip_address',
        'user_agent',
        'route_name',
        'request_id',
        'subject_type',
        'subject_id',
        'before_values',
        'after_values',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
