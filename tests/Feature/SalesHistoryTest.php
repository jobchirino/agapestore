<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $category = Category::factory()->create();
        $this->product = Product::factory()->for($category)->create([
            'detal_price' => 100.00,
            'major_price' => 60.00,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeSale(array $attributes = [], ?array $lines = null): Sale
    {
        $sale = Sale::create(array_merge([
            'client_id' => null,
            'user_id' => $this->user->id,
            'total' => '100.00',
            'initial_payment' => '0',
            'date' => now(),
            'payment_method' => 'efectivo',
            'payment_type' => 'contado',
        ], $attributes));

        $lines ??= [['quantity' => 1, 'unit_price' => $sale->total, 'unit_cost' => '60.00']];

        foreach ($lines as $line) {
            SaleProduct::create([
                'sale_id' => $sale->id,
                'product_id' => $this->product->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'],
            ]);
        }

        return $sale;
    }

    private function client(string $name, ?string $phone = null): Client
    {
        return Client::factory()->create(['name' => $name, 'phone' => $phone]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('sales.history'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_history_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('sales.history'))
            ->assertOk()
            ->assertSee('Historial de Ventas');
    }

    public function test_all_sales_are_visible_by_default(): void
    {
        $client = $this->client('Cliente Antiguo');
        $this->makeSale(['client_id' => $client->id, 'date' => '2020-01-01 10:00:00']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->assertSet('period', 'all')
            ->assertSee('Cliente Antiguo');
    }

    public function test_period_filters_use_the_sale_date_column(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00'); // martes

        $today = $this->client('Cliente Hoy');
        $yesterday = $this->client('Cliente Ayer');
        $thisMonth = $this->client('Cliente Mes');
        $older = $this->client('Cliente Anteayer');

        $this->makeSale(['client_id' => $today->id, 'date' => '2026-10-20 09:00:00']);
        $this->makeSale(['client_id' => $yesterday->id, 'date' => '2026-10-19 09:00:00']);
        $this->makeSale(['client_id' => $thisMonth->id, 'date' => '2026-10-05 09:00:00']);
        $this->makeSale(['client_id' => $older->id, 'date' => '2026-09-30 09:00:00']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->call('selectPeriod', 'today')
            ->assertSee('Cliente Hoy')
            ->assertDontSee('Cliente Ayer')
            ->call('selectPeriod', 'week')
            ->assertSee('Cliente Hoy')
            ->assertSee('Cliente Ayer')
            ->assertDontSee('Cliente Mes')
            ->call('selectPeriod', 'month')
            ->assertSee('Cliente Mes')
            ->assertDontSee('Cliente Anteayer')
            ->call('selectPeriod', 'all')
            ->assertSee('Cliente Anteayer');
    }

    public function test_status_filter_separates_cash_pending_and_paid_credits(): void
    {
        $cash = $this->client('Cliente Contado');
        $pending = $this->client('Cliente Pendiente');
        $paid = $this->client('Cliente Pagado');
        $partial = $this->client('Cliente Abonado');

        $this->makeSale(['client_id' => $cash->id, 'payment_type' => 'contado']);
        $this->makeSale(['client_id' => $pending->id, 'payment_type' => 'credito', 'total' => '100.00', 'initial_payment' => '0']);
        $this->makeSale(['client_id' => $paid->id, 'payment_type' => 'credito', 'total' => '100.00', 'initial_payment' => '100.00']);
        $abonado = $this->makeSale(['client_id' => $partial->id, 'payment_type' => 'credito', 'total' => '100.00', 'initial_payment' => '40.00']);

        SalePayment::create([
            'sale_id' => $abonado->id,
            'amount' => '20.00',
            'date' => now(),
            'payment_method' => 'zelle',
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->call('selectStatus', 'contado')
            ->assertSee('Cliente Contado')
            ->assertDontSee('Cliente Pendiente')
            ->call('selectStatus', 'credito_pending')
            ->assertSee('Cliente Pendiente')
            ->assertSee('Cliente Abonado') // pagó 60 de 100: sigue con saldo
            ->assertDontSee('Cliente Pagado')
            ->call('selectStatus', 'credito_paid')
            ->assertSee('Cliente Pagado')
            ->assertDontSee('Cliente Pendiente');
    }

    public function test_method_filter_and_search(): void
    {
        $maria = $this->client('María Pérez', '0414-1112233');
        $juan = $this->client('Juan López', '0424-9998877');

        $zelleSale = $this->makeSale(['client_id' => $maria->id, 'payment_method' => 'zelle']);
        $this->makeSale(['client_id' => $juan->id, 'payment_method' => 'efectivo']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->set('method', 'zelle')
            ->assertSee('María Pérez')
            ->assertDontSee('Juan López')
            ->set('method', 'all')
            ->set('search', 'juan')
            ->assertSee('Juan López')
            ->assertDontSee('María Pérez')
            ->set('search', '1112233')
            ->assertSee('María Pérez')
            ->set('search', '#'.$zelleSale->id)
            ->assertSee('María Pérez')
            ->assertDontSee('Juan López');
    }

    public function test_plain_numeric_search_matches_the_sale_id(): void
    {
        $target = $this->makeSale(['client_id' => $this->client('Cliente Cuatro')->id]);
        $this->makeSale(['client_id' => $this->client('Cliente Otro')->id]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->set('search', (string) $target->id)
            ->assertSee('Cliente Cuatro')
            ->assertDontSee('Cliente Otro')
            ->set('search', '#'.$target->id)
            ->assertSee('Cliente Cuatro')
            ->assertDontSee('Cliente Otro');
    }

    public function test_clear_filters_button_only_shows_when_a_filter_is_active(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->assertDontSee('Limpiar filtros')
            ->set('search', 'maría')
            ->assertSee('Limpiar filtros')
            ->set('search', '')
            ->assertDontSee('Limpiar filtros');
    }

    public function test_clear_filters_redirects_to_the_clean_url(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->set('period', 'month')
            ->set('status', 'contado')
            ->set('search', 'algo')
            ->call('clearFilters')
            ->assertRedirect(route('sales.history'));
    }

    public function test_typing_in_the_search_does_not_open_the_sale_detail(): void
    {
        $this->makeSale();

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->set('search', 'algo')
            ->assertSet('saleId', null)
            ->assertNotDispatched('open-sale');
    }

    public function test_entering_after_a_sale_opens_it_from_the_session(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->user);
        session(['open_sale' => ['id' => $sale->id, 'at' => now()->timestamp]]);

        Livewire::test('pages::sales.history')
            ->assertSet('saleId', $sale->id)
            ->assertDispatched('open-sale', saleId: $sale->id);
    }

    public function test_an_expired_pending_sale_is_ignored(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->user);
        session(['open_sale' => ['id' => $sale->id, 'at' => now()->subMinutes(11)->timestamp]]);

        Livewire::test('pages::sales.history')
            ->assertSet('saleId', null)
            ->assertNotDispatched('open-sale');
    }

    public function test_reloading_the_history_does_not_reopen_the_sale(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->user);
        session(['open_sale' => ['id' => $sale->id, 'at' => now()->timestamp]]);

        Livewire::test('pages::sales.history')->assertSet('saleId', $sale->id);

        // Segunda carga (recargar): la sesión ya se consumió con pull().
        Livewire::test('pages::sales.history')
            ->assertSet('saleId', null)
            ->assertNotDispatched('open-sale');
    }

    public function test_kpis_reflect_the_active_filters(): void
    {
        // Contado: total 100, costo 60 → ganancia 40.
        $this->makeSale(['total' => '100.00'], [['quantity' => 1, 'unit_price' => '100.00', 'unit_cost' => '60.00']]);

        // Crédito: total 200, costo 120 → ganancia 80, saldo 150.
        $this->makeSale(
            ['total' => '200.00', 'payment_type' => 'credito', 'initial_payment' => '50.00'],
            [['quantity' => 2, 'unit_price' => '100.00', 'unit_cost' => '60.00']],
        );

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->assertSee('$300.00')       // total vendido
            ->assertSee('+$120.00')      // ganancia
            ->assertSee('67% margen promedio')
            ->assertSee('$150.00')       // saldo por cobrar
            ->assertSee('2 ventas registradas')
            ->assertSee('1 crédito activo')
            ->call('selectStatus', 'contado')
            ->assertSee('$100.00')
            ->assertSee('+$40.00')
            ->assertSee('1 venta registrada');
    }

    public function test_opening_a_sale_dispatches_the_detail_event(): void
    {
        $sale = $this->makeSale(['total' => '200.00', 'payment_type' => 'credito', 'initial_payment' => '50.00']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->call('openSale', $sale->id)
            ->assertSet('saleId', $sale->id)
            ->assertDispatched('open-sale', saleId: $sale->id);
    }

    public function test_the_detail_component_shows_lines_and_payment_history(): void
    {
        $client = $this->client('María Pérez');
        $sale = $this->makeSale([
            'client_id' => $client->id,
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ], [['quantity' => 2, 'unit_price' => '100.00', 'unit_cost' => '60.00']]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail')
            ->dispatch('open-sale', saleId: $sale->id)
            ->assertSet('saleId', $sale->id)
            ->assertSee('María Pérez')
            ->assertSee('Movimientos de pago')
            ->assertSee('Registrar abono')
            ->assertSee('$150.00'); // saldo pendiente
    }

    public function test_registering_a_payment_from_the_detail_lowers_the_balance(): void
    {
        $sale = $this->makeSale([
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->set('amount', '80')
            ->set('payment_method', 'zelle')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertDispatched('payment-registered')
            ->assertSee('$80.00')
            ->assertSee('$70.00');

        $this->assertSame('70.00', $sale->fresh()->balanceDue());
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'amount' => 80.00]);
    }

    public function test_liquidating_the_balance_settles_the_credit(): void
    {
        $sale = $this->makeSale([
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->call('fillBalance')
            ->assertSet('amount', '150.00')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame('0.00', $sale->fresh()->balanceDue());
        $this->assertSame(1, Sale::query()->filterByStatus('credito_paid')->count());
    }

    public function test_closing_the_detail_notifies_the_history(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->call('closeDetail')
            ->assertDispatched('sale-closed');
    }

    public function test_the_history_clears_the_selected_sale_when_the_detail_closes(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')
            ->set('saleId', $sale->id)
            ->dispatch('sale-closed')
            ->assertSet('saleId', null);
    }

    public function test_cash_sales_are_fully_paid_and_hide_payment_tracking(): void
    {
        $sale = $this->makeSale(['total' => '100.00', 'payment_type' => 'contado', 'initial_payment' => '0']);

        $this->assertSame('100.00', $sale->paidAmount());
        $this->assertSame('0.00', $sale->balanceDue());

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->assertSee('Ganancia')
            ->assertDontSee('Movimientos de pago')
            ->assertDontSee('Inicial')
            ->assertDontSee('Pendiente');
    }

    public function test_credit_sales_keep_the_payment_tracking(): void
    {
        $sale = $this->makeSale(['total' => '200.00', 'payment_type' => 'credito', 'initial_payment' => '50.00']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->assertSee('Movimientos de pago')
            ->assertSee('Inicial')
            ->assertSee('Pendiente');
    }

    public function test_dates_are_shown_with_am_pm(): void
    {
        $sale = $this->makeSale(['date' => '2026-10-19 15:45:00']);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.history')->assertSee('3:45 PM');

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])->assertSee('3:45 PM');
    }

    public function test_payment_date_before_the_sale_is_rejected_in_the_detail(): void
    {
        $sale = $this->makeSale([
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
            'date' => '2026-10-19 10:00:00',
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->set('amount', '10')
            ->set('date', '2026-10-15T09:00')
            ->call('savePayment')
            ->assertHasErrors(['date'])
            ->assertSee('no puede ser anterior a la venta');

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_payment_over_the_balance_is_rejected_in_the_detail(): void
    {
        $sale = $this->makeSale([
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->set('amount', '999')
            ->call('savePayment')
            ->assertHasErrors(['amount']);

        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('150.00', $sale->fresh()->balanceDue());
    }

    public function test_deleting_a_payment_from_the_detail_restores_the_balance(): void
    {
        $sale = $this->makeSale([
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => '30.00',
            'date' => now(),
            'payment_method' => 'efectivo',
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->call('askDeletePayment', $payment->id)
            ->call('confirmDeletePayment')
            ->assertDispatched('payment-deleted');

        $this->assertDatabaseMissing('sale_payments', ['id' => $payment->id]);
        $this->assertSame('150.00', $sale->fresh()->balanceDue());
    }

    public function test_the_history_table_does_not_add_queries_per_row(): void
    {
        $this->actingAs($this->user);

        foreach (range(1, 3) as $i) {
            $this->makeSale(['total' => '10.00'], [['quantity' => 1, 'unit_price' => '10.00', 'unit_cost' => '5.00']]);
        }

        $small = $this->renderQueryCount();

        foreach (range(1, 12) as $i) {
            $this->makeSale(['total' => '10.00'], [['quantity' => 1, 'unit_price' => '10.00', 'unit_cost' => '5.00']]);
        }

        $large = $this->renderQueryCount();

        $this->assertLessThanOrEqual(
            $small + 1,
            $large,
            "La tabla sumó consultas al crecer: {$small} con 3 ventas y {$large} con 15.",
        );
    }

    private function renderQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test('pages::sales.history');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
