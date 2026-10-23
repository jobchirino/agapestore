<?php

namespace Tests\Feature;

use App\Services\BcvRateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BcvRateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * El singleton memoiza la tasa por petición; en tests hay que olvidarlo para
     * simular una petición nueva.
     */
    private function service(): BcvRateService
    {
        $this->app->forgetInstance(BcvRateService::class);

        return $this->app->make(BcvRateService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'current' => ['date' => '2026-10-23', 'usd' => 886.2271, 'eur' => 993.08836371],
            'previous' => ['date' => '2026-10-22', 'usd' => 884.0, 'eur' => 990.0],
            'changePercentage' => ['usd' => 0.25, 'eur' => 0.31],
        ];
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response($this->payload()),
        ]);
    }

    public function test_refresh_stores_the_rates_in_cache_and_on_disk(): void
    {
        $this->fakeSuccess();

        $rates = $this->service()->refreshRates();

        $this->assertNotNull($rates);
        $this->assertSame(886.2271, $rates['usd']);
        $this->assertSame(993.08836371, $rates['eur']);
        $this->assertSame('2026-10-23', $rates['date']);
        $this->assertSame('api', $rates['source']);

        Storage::disk('local')->assertExists('bcv-rates.json');

        Http::assertSent(fn ($request) => $request->url() === 'https://rates.dolarvzla.com/bcv/current.json');

        // Petición nueva: debe leer de la caché sin tocar la red.
        $current = $this->service()->current();

        $this->assertNotNull($current);
        $this->assertSame('cache', $current['source']);
        $this->assertSame(886.2271, $current['usd']);
    }

    public function test_current_falls_back_to_the_disk_backup_when_the_cache_is_empty(): void
    {
        $this->fakeSuccess();
        $this->service()->refreshRates();

        Cache::flush();

        $current = $this->service()->current();

        $this->assertNotNull($current);
        $this->assertSame('backup', $current['source']);
        $this->assertSame(886.2271, $current['usd']);
    }

    public function test_current_never_hits_the_network(): void
    {
        Http::preventStrayRequests();

        $this->assertNull($this->service()->current());
    }

    public function test_refresh_keeps_the_previous_rate_when_the_api_fails(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::sequence()
                ->push($this->payload())
                ->push(['error' => 'down'], 500),
        ]);

        $this->service()->refreshRates();

        $service = $this->service();

        $this->assertNull($service->refreshRates());
        $this->assertTrue($service->failedRecently());

        $current = $service->current();

        $this->assertNotNull($current);
        $this->assertSame(886.2271, $current['usd']);
        Storage::disk('local')->assertExists('bcv-rates.json');
    }

    public function test_refresh_handles_a_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $service = $this->service();

        $this->assertNull($service->refreshRates());
        $this->assertTrue($service->failedRecently());
    }

    public function test_refresh_rejects_an_unexpected_payload(): void
    {
        Http::fake(['*' => Http::response(['foo' => 'bar'])]);

        $service = $this->service();

        $this->assertNull($service->refreshRates());
        $this->assertTrue($service->failedRecently());
    }

    public function test_is_stale_uses_thirty_minutes_outside_the_critical_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'America/Caracas'));
        $this->fakeSuccess();

        $service = $this->service();
        $rates = $service->refreshRates();

        $this->assertNotNull($rates);
        $this->assertFalse($service->isStale($rates));

        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:29:00', 'America/Caracas'));
        $this->assertFalse($service->isStale($rates));

        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:31:00', 'America/Caracas'));
        $this->assertTrue($service->isStale($rates));
    }

    public function test_is_stale_uses_five_minutes_inside_the_critical_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:00:00', 'America/Caracas'));
        $this->fakeSuccess();

        $service = $this->service();
        $rates = $service->refreshRates();

        $this->assertNotNull($rates);
        $this->assertTrue($service->isCriticalWindow());

        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:04:00', 'America/Caracas'));
        $this->assertFalse($service->isStale($rates));

        $this->travelTo(CarbonImmutable::parse('2026-10-22 16:06:00', 'America/Caracas'));
        $this->assertTrue($service->isStale($rates));
    }

    public function test_the_critical_window_is_skipped_on_weekends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 16:00:00', 'America/Caracas'));
        $this->fakeSuccess();

        $service = $this->service();
        $rates = $service->refreshRates();

        $this->assertNotNull($rates);
        $this->assertFalse($service->isCriticalWindow());

        $this->travelTo(CarbonImmutable::parse('2026-10-24 16:06:00', 'America/Caracas'));
        $this->assertFalse($service->isStale($rates));
    }

    public function test_active_rate_uses_the_default_currency_and_can_be_overridden(): void
    {
        $this->fakeSuccess();
        $this->service()->refreshRates();

        $this->assertSame(886.2271, $this->service()->activeRate());
        $this->assertSame(993.08836371, $this->service()->activeRate('eur'));
    }

    public function test_default_currency_can_be_switched_to_euro(): void
    {
        config(['bcv.default_currency' => 'eur']);

        $this->fakeSuccess();
        $this->service()->refreshRates();

        $this->assertSame('eur', $this->service()->defaultCurrency());
        $this->assertSame(993.08836371, $this->service()->activeRate());
    }

    public function test_bs_amount_component_renders_the_bolivar_equivalent(): void
    {
        $this->fakeSuccess();
        $this->service()->refreshRates();

        $html = $this->blade('<x-bs-amount :amount="25" />');

        // 25 * 886,2271 = 22.155,6775
        $html->assertSee('Bs. 22.155,68');
    }

    public function test_bs_amount_component_accepts_a_currency_prop(): void
    {
        $this->fakeSuccess();
        $this->service()->refreshRates();

        $html = $this->blade('<x-bs-amount :amount="25" currency="eur" />');

        // 25 * 993,08836371 = 24.827,2090925
        $html->assertSee('Bs. 24.827,21');
    }

    public function test_bs_amount_component_renders_nothing_without_a_rate(): void
    {
        Http::preventStrayRequests();

        $this->blade('<x-bs-amount :amount="25" />')->assertDontSee('Bs.');
    }

    public function test_command_refreshes_the_rates(): void
    {
        $this->fakeSuccess();

        $this->artisan('bcv:refresh')->assertSuccessful();

        $this->assertNotNull($this->service()->current());
    }

    public function test_command_fails_when_the_api_is_down(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('bcv:refresh')->assertExitCode(1);
    }
}
