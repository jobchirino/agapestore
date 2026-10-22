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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ClientsPageTest extends TestCase
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

    private function saleFor(Client $client, array $attributes = [], ?array $lines = null): Sale
    {
        $sale = Sale::create(array_merge([
            'client_id' => $client->id,
            'user_id' => $this->user->id,
            'total' => '100.00',
            'initial_payment' => '0',
            'date' => now(),
            'payment_method' => 'efectivo',
            'payment_type' => 'contado',
        ], $attributes));

        foreach ($lines ?? [['quantity' => 1, 'unit_price' => $sale->total, 'unit_cost' => '60.00']] as $line) {
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

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('clients'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_clients_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('clients'))
            ->assertOk()
            ->assertSee('Clientes');
    }

    public function test_search_matches_name_phone_email_and_address(): void
    {
        $maria = Client::factory()->create([
            'name' => 'María Pérez',
            'phone' => '04141112233',
            'email' => 'maria@correo.com',
            'address' => 'Av. Principal',
        ]);
        $juan = Client::factory()->create(['name' => 'Juan López']);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->set('search', 'maría')
            ->assertSee('María Pérez')
            ->assertDontSee('Juan López')
            ->set('search', '1112233')
            ->assertSee('María Pérez')
            ->set('search', 'maria@correo')
            ->assertSee('María Pérez')
            ->set('search', 'principal')
            ->assertSee('María Pérez')
            ->set('search', 'juan')
            ->assertSee('Juan López')
            ->assertDontSee('María Pérez');
    }

    public function test_filters_with_purchases_without_purchases_and_with_debt(): void
    {
        $cash = Client::factory()->create(['name' => 'Cliente Contado']);
        $debtor = Client::factory()->create(['name' => 'Cliente Deudor']);
        $empty = Client::factory()->create(['name' => 'Cliente Sin Compras']);

        $this->saleFor($cash);
        $this->saleFor($debtor, ['total' => '200.00', 'payment_type' => 'credito', 'initial_payment' => '50.00']);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->call('selectFilter', 'with_purchases')
            ->assertSee('Cliente Contado')
            ->assertSee('Cliente Deudor')
            ->assertDontSee('Cliente Sin Compras')
            ->call('selectFilter', 'without_purchases')
            ->assertSee('Cliente Sin Compras')
            ->assertDontSee('Cliente Contado')
            ->call('selectFilter', 'with_debt')
            ->assertSee('Cliente Deudor')
            ->assertSee('Debe $150.00')
            ->assertDontSee('Cliente Contado');
    }

    public function test_sorting_by_name_and_debt(): void
    {
        $ana = Client::factory()->create(['name' => 'Ana']);
        $zoe = Client::factory()->create(['name' => 'Zoe']);

        $this->saleFor($ana, ['total' => '100.00', 'payment_type' => 'credito']);
        $this->saleFor($zoe, ['total' => '500.00', 'payment_type' => 'credito']);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->call('selectSort', 'name')
            ->assertSeeInOrder(['Ana', 'Zoe'])
            ->call('selectSort', 'debt')
            ->assertSeeInOrder(['Zoe', 'Ana']);
    }

    public function test_kpis_reflect_the_filters(): void
    {
        $cash = Client::factory()->create();
        $debtor = Client::factory()->create(['name' => 'Deudor KPI']);
        Client::factory()->create(['name' => 'Sin Compras KPI']);

        // Contado 100 (pagado al 100%). Crédito 200 con inicial 50 → pendiente 150.
        $this->saleFor($cash);
        $this->saleFor($debtor, ['total' => '200.00', 'payment_type' => 'credito', 'initial_payment' => '50.00']);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->assertSee('3')                 // total clientes
            ->assertSee('2 con compras registradas')
            ->assertSee('$150.00')           // deuda total
            ->assertSee('$300.00')           // facturado
            ->assertSee('Ticket promedio $150.00 por cliente');
    }

    public function test_create_and_update_client_from_the_form_component(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::clients.form')
            ->dispatch('open-client-form', clientId: null)
            ->set('name', 'Cliente Nuevo')
            ->set('phone', '0412-473 71 16')
            ->set('email', 'nuevo@correo.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('client-saved');

        $client = Client::query()->where('name', 'Cliente Nuevo')->firstOrFail();
        $this->assertSame('04124737116', $client->phone); // normalizado

        Livewire::test('pages::clients.form')
            ->dispatch('open-client-form', clientId: $client->id)
            ->assertSet('name', 'Cliente Nuevo')
            ->set('name', 'Cliente Editado')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Cliente Editado']);
    }

    public function test_invalid_phone_is_rejected_in_the_form(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::clients.form')
            ->dispatch('open-client-form', clientId: null)
            ->set('name', 'Teléfono Malo')
            ->set('phone', '0412ABC7116')
            ->call('save')
            ->assertHasErrors(['phone']);

        $this->assertSame(0, Client::query()->count());
    }

    public function test_client_with_purchases_cannot_be_deleted(): void
    {
        $client = Client::factory()->create(['name' => 'Con Compras']);
        $this->saleFor($client);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->call('askDelete', $client->id)
            ->call('confirmDelete')
            ->assertSee('tiene compras registradas');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_client_without_purchases_can_be_deleted(): void
    {
        $client = Client::factory()->create(['name' => 'Sin Compras']);

        $this->actingAs($this->user);

        Livewire::test('pages::clients')
            ->call('askDelete', $client->id)
            ->call('confirmDelete');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_client_detail_shows_totals_and_sales_and_can_jump_to_the_history(): void
    {
        $client = Client::factory()->create(['name' => 'Cliente Ficha']);
        $sale = $this->saleFor($client, [
            'total' => '200.00',
            'payment_type' => 'credito',
            'initial_payment' => '50.00',
        ], [['quantity' => 2, 'unit_price' => '100.00', 'unit_cost' => '60.00']]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => '30.00',
            'date' => now(),
            'payment_method' => 'efectivo',
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::clients.detail')
            ->dispatch('open-client', clientId: $client->id)
            ->assertSet('clientId', $client->id)
            ->assertSee('Cliente Ficha')
            ->assertSee('Historial de compras')
            ->assertSee('$200.00')  // total comprado
            ->assertSee('$80.00')   // pagado (50 inicial + 30 abono)
            ->assertSee('$120.00')  // pendiente
            ->call('openInHistory', $sale->id)
            ->assertRedirect(route('sales.history'));

        $this->assertSame($sale->id, session('open_sale.id'));
    }

    public function test_the_clients_table_does_not_add_queries_per_row(): void
    {
        $this->actingAs($this->user);

        foreach (range(1, 3) as $i) {
            $client = Client::factory()->create();
            $this->saleFor($client, ['total' => '100.00', 'payment_type' => 'credito']);
        }

        $small = $this->renderQueryCount();

        foreach (range(1, 12) as $i) {
            $client = Client::factory()->create();
            $this->saleFor($client, ['total' => '100.00', 'payment_type' => 'credito']);
        }

        $large = $this->renderQueryCount();

        $this->assertLessThanOrEqual(
            $small + 1,
            $large,
            "La tabla sumó consultas al crecer: {$small} con 3 clientes y {$large} con 15.",
        );
    }

    private function renderQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test('pages::clients');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
