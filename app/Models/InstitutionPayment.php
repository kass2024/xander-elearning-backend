<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionPayment extends Model
{
    protected $fillable = [
        'platform_institution_id',
        'amount_cents',
        'currency',
        'provider',
        'type',
        'status',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(PlatformInstitution::class, 'platform_institution_id');
    }
}
