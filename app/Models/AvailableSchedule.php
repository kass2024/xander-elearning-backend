<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AvailableSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'day_of_week',
        'available_on_date',
        'start_time',
        'end_time',
        'meeting_duration_minutes',
        'timezone',
        'is_active',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'meeting_duration_minutes' => 'integer',
        'available_on_date' => 'date:Y-m-d',
    ];
}
