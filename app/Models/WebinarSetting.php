<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebinarSetting extends Model
{
    protected $fillable = [
        'recording_enabled',
        'zoom_meeting_id',
        'zoom_password',
        'zoom_join_url',
        'zoom_start_url',
        'zoom_scheduled_at',
        'session_started_at',
        'calendar_blocked_months',
        'calendar_blocked_dates',
    ];

    protected $casts = [
        'recording_enabled' => 'boolean',
        'zoom_scheduled_at' => 'datetime',
        'session_started_at' => 'datetime',
        'calendar_blocked_months' => 'array',
        'calendar_blocked_dates' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'recording_enabled' => false,
        ]);
    }
}
