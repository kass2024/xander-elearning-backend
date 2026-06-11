<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\CourseMaterial;
use App\Models\CourseEnrollment;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'price',
        'duration',
        'requirements',
        'image',
        'status',
    ];

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
