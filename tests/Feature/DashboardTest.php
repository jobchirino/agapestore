<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_executive_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Inicio')
            ->assertSee('Registrar Compra');
    }

    public function test_dashboard_shows_today_totals_and_recent_sales(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->seedSale($user, total: '100.00', clientName: 'Cliente Panel');

        Livewire::test('pages::dashboard')
            ->assertSee('Cliente Panel')
            ->assertSee('$100.00')
            ->assertSee('+$40.00');
    }

    public function test_new_product_quick_action_leaves_a_session_flag_and_redirects(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::dashboard')
            ->call('createProduct')
            ->assertRedirect(route('inventory'));

        $this->assertNotNull(session('open_create_product_modal.at'));
    }

    public function test_inventory_consumes_the_new_product_flag(): void
    {
        $this->actingAs(User::factory()->create());
        session(['open_create_product_modal' => ['at' => now()->timestamp]]);

        Livewire::test('pages::inventory')
            ->assertSet('autoCreateRequested', true)
            ->assertSet('editingId', null);

        $this->assertFalse(session()->has('open_create_product_modal'));
    }

    public function test_expired_new_product_flag_is_ignored(): void
    {
        $this->actingAs(User::factory()->create());
        session(['open_create_product_modal' => ['at' => now()->subMinutes(6)->timestamp]]);

        Livewire::test('pages::inventory')->assertSet('autoCreateRequested', false);
    }

    public function test_opening_a_recent_sale_redirects_to_the_history_with_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $sale = $this->seedSale($user);

        Livewire::test('pages::dashboard')
            ->call('openSale', $sale->id)
            ->assertRedirect(route('sales.history'));

        $this->assertSame($sale->id, session('open_sale.id'));
    }

    public function test_critical_stock_panel_lists_low_stock_products(): void
    {
        $this->actingAs(User::factory()->create());

        $category = Category::factory()->create(['name' => 'Perfumes']);
        Product::factory()->for($category)->create(['name' => 'Producto Crítico', 'total_stock' => 1]);
        Product::factory()->for($category)->create(['name' => 'Producto Sano', 'total_stock' => 30]);

        Livewire::test('pages::dashboard')
            ->assertSee('Producto Crítico')
            ->assertDontSee('Producto Sano');
    }

    private function seedSale(User $user, string $total = '100.00', ?string $clientName = null): Sale
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create([
            'detal_price' => 100.00,
            'major_price' => 60.00,
        ]);

        $client = $clientName === null ? null : Client::factory()->create(['name' => $clientName]);

        $sale = Sale::create([
            'client_id' => $client?->id,
            'user_id' => $user->id,
            'total' => $total,
            'initial_payment' => '0',
            'date' => now(),
            'payment_method' => 'efectivo',
            'payment_type' => 'contado',
        ]);

        SaleProduct::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => '60.00',
        ]);

        return $sale;
    }
}
