<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BcvRateService
{
    public const FAILURE_MESSAGE = 'Servicio de tasas para divisas caído, por favor consulte la web directa del BCV';

    private const CACHE_KEY = 'bcv.rates.current';

    private const FAILED_KEY = 'bcv.rates.failed';

    private const BACKUP_FILE = 'bcv-rates.json';

    /**
     * Memo por petición: las vistas pueden pedir la tasa decenas de veces sin coste extra.
     *
     * @var array{usd: float, eur: float, date: string, fetched_at: string, change_usd: float|null, change_eur: float|null, source: string}|null
     */
    private ?array $memo = null;

    private bool $lastRefreshFailed = false;

    /**
     * Lectura 100% local: caché → respaldo en disco → null. Nunca hace HTTP.
     *
     * @return array{usd:float,eur:float,date:string,fetched_at:string,change_usd:float|null,change_eur:float|null,source:string}|null
     */
    public function current(): ?array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $this->isValid($cached)) {
            return $this->memo = [...$this->normalize($cached), 'source' => 'cache'];
        }

        $backup = $this->readBackup();

        if ($backup !== null) {
            return $this->memo = [...$this->normalize($backup), 'source' => 'backup'];
        }

        return null;
    }

    /**
     * Único camino que consulta la API. Si falla, devuelve null y deja intactos
     * la caché y el respaldo (se sigue calculando con la última tasa conocida).
     *
     * @return array{usd:float,eur:float,date:string,fetched_at:string,change_usd:float|null,change_eur:float|null,source:string}|null
     */
    public function refreshRates(): ?array
    {
        try {
            $response = Http::timeout((int) config('bcv.timeout', 4))
                ->acceptJson()
                ->get((string) config('bcv.api_url'));

            if (! $response->successful()) {
                return $this->fail('HTTP '.$response->status());
            }

            $payload = $response->json();

            if (! is_array($payload) || ! is_array($payload['current'] ?? null)) {
                return $this->fail('Respuesta JSON inesperada.');
            }

            $current = $payload['current'];

            if (
                ! is_numeric($current['usd'] ?? null)
                || ! is_numeric($current['eur'] ?? null)
                || ! is_string($current['date'] ?? null)
            ) {
                return $this->fail('La respuesta no trae usd/eur/date.');
            }

            $change = is_array($payload['changePercentage'] ?? null) ? $payload['changePercentage'] : [];

            $rates = [
                'usd' => (float) $current['usd'],
                'eur' => (float) $current['eur'],
                'date' => (string) $current['date'],
                'fetched_at' => now($this->timezone())->toIso8601String(),
                'change_usd' => is_numeric($change['usd'] ?? null) ? (float) $change['usd'] : null,
                'change_eur' => is_numeric($change['eur'] ?? null) ? (float) $change['eur'] : null,
            ];

            Cache::put(self::CACHE_KEY, $rates, now()->addDay());
            Cache::forget(self::FAILED_KEY);
            $this->writeBackup($rates);
            $this->lastRefreshFailed = false;
            $this->memo = [...$rates, 'source' => 'api'];

            return $this->memo;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * TTL dinámico: 5 minutos dentro de la ventana crítica del BCV, 30 minutos fuera.
     *
     * @param  array{usd: float, eur: float, date: string, fetched_at: string, change_usd: float|null, change_eur: float|null, source: string}|null  $rates
     */
    public function isStale(?array $rates = null): bool
    {
        $rates ??= $this->current();

        if ($rates === null) {
            return true;
        }

        $fetchedAt = CarbonImmutable::parse($rates['fetched_at']);
        $now = now($this->timezone());
        $ttl = $this->isCriticalWindow($now)
            ? (int) config('bcv.critical_ttl_minutes', 5)
            : (int) config('bcv.cache_ttl_minutes', 30);

        return $fetchedAt->addMinutes($ttl)->lt($now);
    }

    public function isCriticalWindow(?CarbonImmutable $now = null): bool
    {
        $now ??= now($this->timezone());

        if (! $now->isWeekday()) {
            return false;
        }

        $start = $now->setTimeFromTimeString((string) config('bcv.critical_window_start', '15:45'));
        $end = $now->setTimeFromTimeString((string) config('bcv.critical_window_end', '17:45'));

        return $now->betweenIncluded($start, $end);
    }

    public function activeRate(?string $currency = null): ?float
    {
        $currency ??= $this->defaultCurrency();
        $rates = $this->current();
        $value = $rates[$currency] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function defaultCurrency(): string
    {
        $currency = (string) config('bcv.default_currency', 'usd');

        return in_array($currency, ['usd', 'eur'], true) ? $currency : 'usd';
    }

    /**
     * El último intento de refresco falló (para el banner del sidebar y la calculadora).
     */
    public function failedRecently(): bool
    {
        return $this->lastRefreshFailed || (bool) Cache::get(self::FAILED_KEY, false);
    }

    public function timezone(): string
    {
        return (string) config('bcv.timezone', 'America/Caracas');
    }

    /**
     * @return array{usd:float,eur:float,date:string,fetched_at:string,change_usd:float|null,change_eur:float|null}|null
     */
    private function readBackup(): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::BACKUP_FILE)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get(self::BACKUP_FILE), true);

        return is_array($decoded) && $this->isValid($decoded) ? $this->normalize($decoded) : null;
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private function writeBackup(array $rates): void
    {
        Storage::disk('local')->put(self::BACKUP_FILE, json_encode($rates, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isValid(array $data): bool
    {
        return is_numeric($data['usd'] ?? null)
            && is_numeric($data['eur'] ?? null)
            && is_string($data['date'] ?? null)
            && is_string($data['fetched_at'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{usd:float,eur:float,date:string,fetched_at:string,change_usd:float|null,change_eur:float|null}
     */
    private function normalize(array $data): array
    {
        return [
            'usd' => (float) $data['usd'],
            'eur' => (float) $data['eur'],
            'date' => (string) $data['date'],
            'fetched_at' => (string) $data['fetched_at'],
            'change_usd' => is_numeric($data['change_usd'] ?? null) ? (float) $data['change_usd'] : null,
            'change_eur' => is_numeric($data['change_eur'] ?? null) ? (float) $data['change_eur'] : null,
        ];
    }

    private function fail(string $reason): null
    {
        Log::warning('No se pudo actualizar la tasa BCV.', ['reason' => $reason]);

        Cache::put(self::FAILED_KEY, true, now()->addMinutes(15));
        $this->lastRefreshFailed = true;

        return null;
    }
}
