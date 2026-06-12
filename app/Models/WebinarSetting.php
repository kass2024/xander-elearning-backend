<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebinarSetting extends Model
{
    protected $fillable = [
        'recording_enabled',
        'zoom_meeting_id',
        'zoom_join_url',
        'zoom_start_url',
        'session_started_at',
    ];

    protected $casts = [
        'recording_enabled' => 'boolean',
        'session_started_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'recording_enabled' => false,
        ]);
    }
}
