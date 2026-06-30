<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseEnrollment extends Model
{
    protected $fillable = [
        'student_id',
        'course_id',
        'status',
        'level',
        'study_shift_id',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function studyShift()
    {
        return $this->belongsTo(StudyShift::class, 'study_shift_id');
    }

    public function studyShifts(): BelongsToMany
    {
        return $this->belongsToMany(StudyShift::class, 'course_enrollment_study_shifts', 'course_enrollment_id', 'study_shift_id')
            ->withTimestamps()
            ->orderBy('day_of_week')
            ->orderBy('start_time');
    }

    public function studyShiftLinks(): HasMany
    {
        return $this->hasMany(CourseEnrollmentStudyShift::class, 'course_enrollment_id');
    }
}
