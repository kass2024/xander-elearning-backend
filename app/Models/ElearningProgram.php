<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElearningProgram extends Model
{
    use HasFactory;

    protected $table = 'elearning_programs';

    protected $fillable = [
        'name',
        'description',
        'image',
        'status',
        'sort_order',
        'platform_institution_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function courses()
    {
        return $this->hasMany(Course::class, 'program_id');
    }

    public function activeCourses()
    {
        return $this->hasMany(Course::class, 'program_id')
            ->where('status', 'Active')
            ->orderBy('title');
    }
}
