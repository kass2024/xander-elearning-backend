<?php

namespace App\Support;

class InstructorPayoutMethods
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'bank_transfer' => 'Bank transfer',
            'mtn_momo' => 'MTN Mobile Money (MoMo)',
            'airtel_money' => 'Airtel Money',
            'paypal' => 'PayPal',
            'wise' => 'Wise / International transfer',
            'cash' => 'Cash pickup',
            'other' => 'Other',
        ];
    }

    public static function label(?string $key): string
    {
        if (!$key) {
            return '—';
        }

        return self::options()[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

  /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    public static function isValid(?string $key): bool
    {
        return $key && in_array($key, self::keys(), true);
    }
}
