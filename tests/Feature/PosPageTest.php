<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PosPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::factory()->create(['name' => 'Perfumes']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->category)->create(array_merge([
            'detal_price' => 25.00,
            'major_price' => 18.50,
            'total_stock' => 10,
        ], $attributes));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('pos'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_pos_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('pos'))
            ->assertOk()
            ->assertSee('Registrar Compra');
    }

    public function test_the_search_filters_products_by_name(): void
    {
        $this->product(['name' => 'Perfume Floral']);
        $this->product(['name' => 'Bolso Ejecutivo']);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('productSearch', 'floral')
            ->assertSee('Perfume Floral')
            ->assertDontSee('Bolso Ejecutivo');
    }

    public function test_out_of_stock_products_show_as_sold_out_and_cannot_be_added(): void
    {
        $product = $this->product(['name' => 'Agotado Test', 'total_stock' => 0]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('productSearch', 'agotado')
            ->assertSee('Agotado')
            ->call('addProduct', $product->id)
            ->assertSet('cart', []);
    }

    public function test_clicking_a_product_repeatedly_increments_the_quantity(): void
    {
        $product = $this->product(['total_stock' => 5]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 1)
            ->assertSee('1 en carrito')
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 2)
            ->assertSee('2 en carrito');
    }

    public function test_adding_is_capped_at_the_available_stock(): void
    {
        $product = $this->product(['total_stock' => 2]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('addProduct', $product->id)
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 2);
    }

    public function test_decrement_stops_at_one_and_remove_line_clears_it(): void
    {
        $product = $this->product(['total_stock' => 5]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('addProduct', $product->id)
            ->call('decrement', $product->id)
            ->assertSet("cart.{$product->id}", 1)
            ->call('decrement', $product->id)
            ->assertSet("cart.{$product->id}", 1) // en 1 ya no baja
            ->call('removeLine', $product->id)
            ->assertSet('cart', []);
    }

    public function test_summary_calculates_total_and_estimated_profit(): void
    {
        $perfume = $this->product(['detal_price' => 25.00, 'major_price' => 18.50]);
        $wallet = $this->product(['detal_price' => 40.00, 'major_price' => 22.00]);

        $this->actingAs($this->user);

        // 2 x 25.00 + 1 x 40.00 = 90.00; ganancia = 2 x 6.50 + 1 x 18.00 = 31.00
        $component = Livewire::test('pages::pos')
            ->call('addProduct', $perfume->id)
            ->call('addProduct', $perfume->id)
            ->call('addProduct', $wallet->id);

        $this->assertSame('90.00', $component->instance()->summary()['total']);
        $this->assertSame('31.00', $component->instance()->summary()['profit']);
    }

    public function test_confirm_sale_deducts_stock_and_freezes_the_cost(): void
    {
        $product = $this->product(['detal_price' => 25.00, 'major_price' => 18.50, 'total_stock' => 10]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('addProduct', $product->id)
            ->call('confirmSale')
            ->assertSet('cart', []);

        $sale = Sale::query()->firstOrFail();

        $this->assertSame('50.00', $sale->total);
        $this->assertSame(8, $product->fresh()->total_stock);

        $line = SaleProduct::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame('25.00', $line->unit_price);
        $this->assertSame('18.50', $line->unit_cost);
    }

    public function test_confirm_when_stock_changed_shows_an_error_and_keeps_the_cart(): void
    {
        $product = $this->product(['total_stock' => 1]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            // Otra venta se lleva el stock por detrás.
            ->tap(function () use ($product): void {
                $product->update(['total_stock' => 0]);
            })
            ->call('confirmSale')
            ->assertSet("cart.{$product->id}", 1) // el carrito se conserva
            ->assertSee('Stock insuficiente');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_confirm_with_empty_cart_shows_an_error(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('confirmSale')
            ->assertSee('Agrega al menos un producto');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_payment_proof_is_uploaded_and_saved_with_the_sale(): void
    {
        Storage::fake('local');

        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('payment_proof', UploadedFile::fake()->image('comprobante.jpg'))
            ->call('confirmSale')
            ->assertSet('payment_proof', null);

        $sale = Sale::query()->firstOrFail();

        $this->assertNotNull($sale->payment_proof_path);
        Storage::disk('local')->assertExists($sale->payment_proof_path);
    }

    public function test_casual_client_sale_and_custom_past_date_are_saved(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('date', '2026-10-10T09:30')
            ->call('confirmSale');

        $sale = Sale::query()->firstOrFail();

        $this->assertNull($sale->client_id);
        $this->assertSame('2026-10-10 09:30', $sale->date->format('Y-m-d H:i'));
    }

    public function test_default_sale_date_uses_caracas_time_near_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 20:29:00', 'America/Caracas'));

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->assertSet('date', '2026-10-22T20:29');
    }

    public function test_future_dates_are_rejected(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');

        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('date', '2026-10-19T09:00')
            ->call('confirmSale')
            ->assertHasErrors(['date']);

        $this->assertSame(0, Sale::query()->count());

        Carbon::setTestNow();
    }

    public function test_selecting_an_existing_client_attaches_it_to_the_sale(): void
    {
        $client = Client::factory()->create(['name' => 'María Pérez']);
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('clientSearch', 'maría')
            ->assertSee('María Pérez')
            ->call('selectClient', $client->id)
            ->assertSet('client_id', $client->id)
            ->call('addProduct', $product->id)
            ->call('confirmSale');

        $this->assertSame($client->id, Sale::query()->firstOrFail()->client_id);
    }

    public function test_quick_client_component_creates_a_client_and_dispatches_the_event(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::pos.client-form')
            ->set('name', 'Juan López')
            ->set('phone', '0424-1112233')
            ->call('save')
            ->assertDispatched('client-created');

        // El guion se normaliza: solo se guardan dígitos.
        $this->assertDatabaseHas('clients', ['name' => 'Juan López', 'phone' => '04241112233']);
    }

    public function test_pos_listens_for_the_created_client_without_losing_the_cart(): void
    {
        $product = $this->product();
        $client = Client::factory()->create();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->dispatch('client-created', clientId: $client->id)
            ->assertSet('client_id', $client->id)
            ->assertSet("cart.{$product->id}", 1); // el carrito sigue intacto
    }

    public function test_quick_quantity_adds_several_units_in_one_click_and_resets(): void
    {
        $product = $this->product(['total_stock' => 10]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('quickQuantity', '4')
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 4)
            ->assertSet('quickQuantity', '1'); // se reinicia para no agregar en bloque por error
    }

    public function test_quick_quantity_is_capped_at_the_available_stock(): void
    {
        $product = $this->product(['total_stock' => 3]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('quickQuantity', '10')
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 3);
    }

    public function test_increment_adds_exactly_one_unit(): void
    {
        $product = $this->product(['total_stock' => 5]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->set('quickQuantity', '4')
            ->call('addProduct', $product->id)
            ->assertSet("cart.{$product->id}", 4)
            ->call('increment', $product->id)
            ->assertSet("cart.{$product->id}", 5) // no usa quickQuantity
            ->call('increment', $product->id)
            ->assertSet("cart.{$product->id}", 5); // topado al stock
    }

    public function test_the_cart_survives_leaving_the_page(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')->call('addProduct', $product->id);

        // Una visita nueva (como si hubiera salido y vuelto) recupera el carrito de la sesión.
        Livewire::test('pages::pos')->assertSet("cart.{$product->id}", 1);
    }

    public function test_a_draft_older_than_eight_hours_is_discarded(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        // Se arma el borrador y se envejece su marca de tiempo (queda guardada en sesión).
        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('cartSavedAt', now()->subHours(9)->timestamp);

        // Al volver, el borrador viejo ya no está.
        Livewire::test('pages::pos')->assertSet('cart', []);
    }

    public function test_a_recent_draft_is_kept(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('cartSavedAt', now()->subHours(2)->timestamp);

        Livewire::test('pages::pos')->assertSet("cart.{$product->id}", 1);
    }

    public function test_clear_cart_empties_the_draft(): void
    {
        $product = $this->product();
        $client = Client::factory()->create();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('selectClient', $client->id)
            ->call('clearCart')
            ->assertSet('cart', [])
            ->assertSet('client_id', null)
            ->assertSet('cartSavedAt', null);
    }

    public function test_confirming_a_sale_clears_the_session_draft(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('confirmSale')
            ->assertSet('cart', [])
            ->assertSet('cartSavedAt', null);
    }

    public function test_initial_payment_field_only_appears_for_credit(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->assertDontSee('Inicial / abono')
            ->call('selectPaymentType', 'credito')
            ->assertSee('Inicial / abono')
            ->assertSee('Pendiente')
            ->call('selectPaymentType', 'contado')
            ->assertDontSee('Inicial / abono')
            ->assertSet('initial_payment', '0');
    }

    public function test_credit_sale_shows_initial_and_pending_balance_in_the_success_panel(): void
    {
        $product = $this->product(['detal_price' => 50.00, 'major_price' => 30.00]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('selectPaymentType', 'credito')
            ->set('initial_payment', '20')
            ->call('confirmSale')
            ->assertSee('Inicial recibida')
            ->assertSee('Pendiente')
            ->assertSet('initial_payment', '0');

        $sale = Sale::query()->firstOrFail();

        $this->assertSame('credito', $sale->payment_type);
        $this->assertSame('20.00', $sale->initial_payment);
        $this->assertSame('30.00', $sale->balanceDue());
    }

    public function test_initial_payment_over_the_total_shows_an_error_and_keeps_the_cart(): void
    {
        $product = $this->product(['detal_price' => 10.00]);

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('selectPaymentType', 'credito')
            ->set('initial_payment', '25')
            ->call('confirmSale')
            ->assertHasErrors(['initial_payment'])
            ->assertSet("cart.{$product->id}", 1);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_oversized_payment_proof_is_rejected_with_a_specific_spanish_message(): void
    {
        Storage::fake('local');
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('payment_proof', UploadedFile::fake()->image('enorme.jpg')->size(6144)) // 6 MB
            ->call('confirmSale')
            ->assertHasErrors(['payment_proof'])
            ->assertSee('El comprobante no puede pesar más de 5 MB');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_four_megabyte_payment_proof_is_accepted(): void
    {
        Storage::fake('local');
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->set('payment_proof', UploadedFile::fake()->image('ok.jpg')->size(4096)) // 4 MB
            ->call('confirmSale')
            ->assertHasNoErrors();

        $this->assertNotNull(Sale::query()->firstOrFail()->payment_proof_path);
    }

    public function test_success_panel_hands_the_sale_to_the_history_via_session(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('confirmSale')
            ->assertSee('Ver en el historial')
            ->assertSee(route('sales.history'));

        // La URL queda limpia: el id viaja por sesión y se consume una sola vez.
        $this->assertSame(Sale::query()->firstOrFail()->id, session('open_sale.id'));
    }

    public function test_success_panel_shows_the_sale_and_can_start_another_one(): void
    {
        $product = $this->product();

        $this->actingAs($this->user);

        Livewire::test('pages::pos')
            ->call('addProduct', $product->id)
            ->call('confirmSale')
            ->assertSee('VENTA REGISTRADA')
            ->assertSee('Ganancia real')
            ->call('startNewSale')
            ->assertSet('lastSale', null);
    }
}
