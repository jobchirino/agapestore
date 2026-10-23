<?php

namespace App\Support;

/**
 * Política única de alertas de stock bajo.
 *
 * No avisamos en cada bajada: si un producto nace con 1 o 2 unidades (o se sube
 * stock) no queremos ruido. Solo avisamos cuando el stock cruza el umbral desde
 * arriba o cuando llega a cero.
 */
final class LowStock
{
    public const THRESHOLD = 2;

    public static function shouldNotify(int $before, int $after): bool
    {
        if ($after >= $before) {
            return false;
        }

        if ($after === 0) {
            return true;
        }

        return $before > self::THRESHOLD && $after <= self::THRESHOLD;
    }
}
