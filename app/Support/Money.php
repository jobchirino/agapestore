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

    /**
     * Formato venezolano para bolívares: Bs. 1.234,56
     */
    public static function bs(string|int|float $amount): string
    {
        return 'Bs. '.number_format((float) $amount, 2, ',', '.');
    }
}
