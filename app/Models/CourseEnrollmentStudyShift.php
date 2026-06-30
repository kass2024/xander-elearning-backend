<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEnrollmentStudyShift extends Model
{
    protected $fillable = [
        'course_enrollment_id',
        'study_shift_id',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id');
    }

    public function studyShift(): BelongsTo
    {
        return $this->belongsTo(StudyShift::class, 'study_shift_id');
    }
}
