<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstitutionPromoCode extends Model
{
    protected $fillable = [
        'code',
        'label',
        'max_uses',
        'uses_count',
        'is_active',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function institutions(): HasMany
    {
        return $this->hasMany(PlatformInstitution::class, 'promo_code_id');
    }

    public function isRedeemable(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return $this->uses_count < $this->max_uses;
    }
}
