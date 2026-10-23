<?php

namespace App\Console\Commands;

use App\Services\BcvRateService;
use Illuminate\Console\Command;

class RefreshBcvRatesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bcv:refresh';

    /**
     * @var string
     */
    protected $description = 'Actualiza las tasas del BCV (dólar y euro) en caché y respaldo local';

    public function handle(BcvRateService $rates): int
    {
        $result = $rates->refreshRates();

        if ($result === null) {
            $this->error('No se pudo obtener la tasa BCV. Se mantiene la última conocida.');

            return self::FAILURE;
        }

        $this->info("Tasas BCV actualizadas: USD {$result['usd']} / EUR {$result['eur']} (vigencia {$result['date']}).");

        return self::SUCCESS;
    }
}
