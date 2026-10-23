<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Los comprobantes de pago son privados: no viven en el disco `public` ni se
 * exponen por `/storage`. Este helper centraliza el disco y el directorio.
 */
final class PaymentProofs
{
    public const DIRECTORY = 'payment-proofs';

    public static function diskName(): string
    {
        return (string) config('filesystems.payment_proofs_disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }
}
