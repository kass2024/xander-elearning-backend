<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyShift extends Model
{
    protected $fillable = [
        'course_id',
        'name',
        'day_of_week',
        'start_time',
        'end_time',
        'timezone',
        'max_students',
        'is_active',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'max_students' => 'integer',
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @deprecated Prefer enrollmentLinks() for seat counts */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'study_shift_id');
    }

    public function enrollmentLinks(): HasMany
    {
        return $this->hasMany(CourseEnrollmentStudyShift::class, 'study_shift_id');
    }
}
