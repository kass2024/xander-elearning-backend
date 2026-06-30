<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\CourseMaterial;
use App\Models\CourseEnrollment;
use App\Models\ElearningProgram;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_id',
        'platform_institution_id',
        'title',
        'course_code',
        'description',
        'general_information',
        'important_information',
        'guidelines',
        'how_to_use',
        'attendance_policy',
        'assessment_policy',
        'price',
        'duration',
        'requirements',
        'image',
        'status',
    ];

    protected $casts = [
        'guidelines' => 'array',
        'how_to_use' => 'array',
        'price' => 'float',
    ];

    public function program()
    {
        return $this->belongsTo(ElearningProgram::class, 'program_id');
    }

    public function instructors()
    {
        return $this->belongsToMany(
            User::class,
            'assign_cours',
            'course_id',
            'user_id'
        );
    }

    public function materials()
    {
        return $this->hasMany(CourseMaterial::class);
    }

    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }
}
