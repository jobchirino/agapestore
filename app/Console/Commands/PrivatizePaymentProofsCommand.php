<?php

namespace App\Console\Commands;

use App\Support\PaymentProofs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizePaymentProofsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'agape:privatize-proofs';

    /**
     * @var string
     */
    protected $description = 'Mueve los comprobantes de pago del disco público al privado';

    public function handle(): int
    {
        if (PaymentProofs::diskName() === 'public') {
            $this->error('PAYMENT_PROOFS_DISK no puede ser "public": los comprobantes deben ser privados.');

            return self::FAILURE;
        }

        $public = Storage::disk('public');
        $private = PaymentProofs::disk();

        $files = $public->allFiles(PaymentProofs::DIRECTORY);

        if ($files === []) {
            $this->info('No hay comprobantes pendientes en el disco público.');

            return self::SUCCESS;
        }

        $moved = 0;
        $failed = 0;

        foreach ($files as $path) {
            if (! $private->exists($path)) {
                $contents = $public->get($path);

                if ($contents === null || ! $private->put($path, $contents)) {
                    $this->error("No se pudo mover: {$path}");
                    $failed++;

                    continue;
                }
            }

            // Si ya existía en privado, basta con retirar la copia pública.
            $public->delete($path);
            $moved++;
        }

        if ($public->allFiles(PaymentProofs::DIRECTORY) === []) {
            $public->deleteDirectory(PaymentProofs::DIRECTORY);
        }

        $this->info("Comprobantes movidos: {$moved}");

        if ($failed > 0) {
            $this->error("Fallaron: {$failed}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
