<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'available_schedule_id',
        'schedule_label',
        'full_name',
        'email',
        'phone',
        'country',
        'notes',
        'status',
        'rejected_reason',
        'zoom_meeting_id',
        'zoom_join_url',
        'zoom_start_time',
        'reminder_sent_at',
        'final_reminder_sent_at',
    ];

    protected $casts = [
        'zoom_start_time' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'final_reminder_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availableSchedule(): BelongsTo
    {
        return $this->belongsTo(AvailableSchedule::class);
    }
}
