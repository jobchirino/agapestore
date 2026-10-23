<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BcvRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => ['date' => '2026-10-23', 'usd' => 886.2271, 'eur' => 993.08836371],
                'previous' => ['date' => '2026-10-22', 'usd' => 884.0, 'eur' => 990.0],
                'changePercentage' => ['usd' => 0.25, 'eur' => 0.31],
            ]),
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('calculator'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders_without_touching_the_network(): void
    {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->get(route('calculator'))
            ->assertOk()
            ->assertSee('Calculadora BCV');
    }

    public function test_the_sidebar_links_to_the_calculator(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Calculadora');
    }

    public function test_the_calculator_shows_the_cached_rates(): void
    {
        $this->fakeSuccess();
        app(BcvRateService::class)->refreshRates();

        Livewire::test('pages::calculator')
            ->assertSee('Calculadora BCV')
            ->assertSee('Bs. 886,23')
            ->assertSee('Bs. 993,09');
    }

    public function test_the_calculator_uses_a_clipboard_fallback_and_a_bank_friendly_bs_copy(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('calculator'))
            ->assertOk()
            ->assertSee('legacyCopy', false)
            ->assertSee('bsCopy', false)
            ->assertSee("replace('.', ',')", false);
    }

    public function test_refresh_updates_the_rates_and_notifies_the_frontend(): void
    {
        $this->fakeSuccess();

        Livewire::test('pages::calculator')
            ->call('refresh')
            ->assertDispatched('bcv-rates-updated')
            ->assertSee('Bs. 886,23');
    }

    public function test_refresh_shows_the_bcv_message_when_the_api_is_down(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        Livewire::test('pages::calculator')
            ->call('refresh')
            ->assertSee(BcvRateService::FAILURE_MESSAGE)
            ->assertSee('bcv.org.ve');
    }

    public function test_wire_init_refreshes_automatically_when_there_is_no_rate(): void
    {
        $this->fakeSuccess();

        Livewire::test('pages::calculator')
            ->call('refreshIfNeeded')
            ->assertSee('Bs. 886,23');
    }

    public function test_the_widget_renders_the_rate_and_can_refresh(): void
    {
        $this->fakeSuccess();

        Livewire::test('pages::bcv.widget')
            ->assertSee('Dólar BCV')
            ->call('refresh')
            ->assertSee('Bs. 886,23');
    }

    public function test_the_widget_shows_the_bcv_message_when_the_api_is_down(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        Livewire::test('pages::bcv.widget')
            ->call('refresh')
            ->assertSee(BcvRateService::FAILURE_MESSAGE);
    }
}
