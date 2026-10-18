<?php

namespace App\Support;

final class Money
{
    /**
     * Converts a decimal amount such as "18.50" to integer cents, so sums do not drift.
     */
    public static function toCents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
