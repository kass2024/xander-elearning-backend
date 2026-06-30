<?php

namespace App\Models;

use App\Support\InstructorPayoutMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorPayoutRequest extends Model
{
    protected $fillable = [
        'instructor_id',
        'amount',
        'status',
        'payment_method',
        'payment_details',
        'notes',
        'processed_at',
    ];

    protected $appends = [
        'payment_method_label',
    ];

    protected $casts = [
        'amount' => 'float',
        'processed_at' => 'datetime',
    ];

    public function getPaymentMethodLabelAttribute(): string
    {
        return InstructorPayoutMethods::label($this->payment_method);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
