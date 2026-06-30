<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveZoomCohort extends Model
{
    use HasFactory;

    // Explicit table name because it is not the default plural form
    protected $table = 'livezoom_cohort';

    protected $fillable = [
        'platform_institution_id',
        'day_of_week',
        'available_on_date',
        'start_time',
        'end_time',
        'timezone',
        'is_active',
        'created_by',
        'notes',
        'zoom_link',
        'zoom_meeting_id',
        'zoom_start_url',
        'zoom_password',
        'zoom_description',
        'session_status',
        'session_started_at',
        'session_ended_at',
        'current_queue_entry_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'available_on_date' => 'date:Y-m-d',
        'session_started_at' => 'datetime',
        'session_ended_at' => 'datetime',
        'current_queue_entry_id' => 'integer',
    ];

    public function queueEntries()
    {
        return $this->hasMany(LiveZoomCohortQueueEntry::class, 'livezoom_cohort_id');
    }
}
