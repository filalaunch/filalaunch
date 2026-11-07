<?php

namespace App\Support\Billing;

use Illuminate\Support\Number;

/**
 * Amounts are stored as integers in the smallest currency unit (cents).
 */
class Money
{
    public static function format(int $amount, ?string $currency = null): string
    {
        return Number::currency($amount / 100, in: $currency ?: Billing::currency());
    }

    public static function toMinor(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromMinor(?int $amount): ?string
    {
        return $amount === null ? null : number_format($amount / 100, 2, '.', '');
    }
}
