<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
        'phone',
        'country',
        'primary_goal',
        'platform_institution_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Student $student) {
            $full = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
            if ($full !== '') {
                $student->attributes['name'] = $full;
            } elseif (empty($student->attributes['name'])) {
                $student->attributes['name'] = (string) ($student->email ?? '');
            }
        });
    }

    protected $appends = [
        'name',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        // add casts here if you later add extra JSON/date columns
    ];

    public function getNameAttribute(): string
    {
        $full = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        if ($full !== '') {
            return $full;
        }

        if (isset($this->attributes['name']) && trim((string) $this->attributes['name']) !== '') {
            return trim((string) $this->attributes['name']);
        }

        return (string) ($this->email ?? '');
    }

    /**
     * Automatically hash password when setting it.
     */
    public function setPasswordAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['password'] = null;
            return;
        }
        // Avoid double-hashing: if it already looks like a bcrypt hash, keep it.
        if (is_string($value) && strlen($value) === 60 && preg_match('/^\$2y\$|^\$2a\$|^\$2b\$/', $value)) {
            $this->attributes['password'] = $value;
        } else {
            $this->attributes['password'] = Hash::make($value);
        }
    }

    public function platformInstitution()
    {
        return $this->belongsTo(PlatformInstitution::class, 'platform_institution_id');
    }
}
