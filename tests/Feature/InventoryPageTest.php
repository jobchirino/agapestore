<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $perfumes;

    private Category $carteras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->perfumes = Category::factory()->create(['name' => 'Perfumes']);
        $this->carteras = Category::factory()->create(['name' => 'Carteras']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('inventory'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_inventory_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('inventory'))
            ->assertOk()
            ->assertSee('Inventario de Productos')
            ->assertSee('Ágape Store');
    }

    public function test_kpis_are_calculated_from_the_database(): void
    {
        // Sale value: 10 x 5 + 20 x 2 = 90. Cost value: 4 x 5 + 8 x 2 = 36. Markup average: (150 + 150) / 2.
        Product::factory()->for($this->perfumes)->create(['detal_price' => 10, 'major_price' => 4, 'total_stock' => 5]);
        Product::factory()->for($this->carteras)->create(['detal_price' => 20, 'major_price' => 8, 'total_stock' => 2]);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->assertSee('$90.00')
            ->assertSee('Costo base $36.00')
            ->assertSee('+150% margen promedio');
    }

    public function test_search_filters_products_by_name(): void
    {
        Product::factory()->for($this->perfumes)->create(['name' => 'Perfume Floral']);
        Product::factory()->for($this->carteras)->create(['name' => 'Bolso Ejecutivo']);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->set('search', 'floral')
            ->assertSee('Perfume Floral')
            ->assertDontSee('Bolso Ejecutivo');
    }

    public function test_category_pill_filters_products(): void
    {
        Product::factory()->for($this->perfumes)->create(['name' => 'Perfume Floral']);
        Product::factory()->for($this->carteras)->create(['name' => 'Bolso Ejecutivo']);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('selectCategory', $this->carteras->id)
            ->assertSee('Bolso Ejecutivo')
            ->assertDontSee('Perfume Floral');
    }

    public function test_critical_filter_shows_only_products_with_three_units_or_less(): void
    {
        Product::factory()->for($this->perfumes)->create(['name' => 'Stock Bajo Crema', 'total_stock' => 2]);
        Product::factory()->for($this->perfumes)->create(['name' => 'Stock Alto Loción', 'total_stock' => 30]);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->set('criticalOnly', true)
            ->assertSee('Stock Bajo Crema')
            ->assertDontSee('Stock Alto Loción');
    }

    public function test_sort_by_stock_orders_ascending(): void
    {
        Product::factory()->for($this->perfumes)->create(['name' => 'Mucho Stock', 'total_stock' => 30]);
        Product::factory()->for($this->perfumes)->create(['name' => 'Poco Stock', 'total_stock' => 1]);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->set('sort', 'stock')
            ->assertSeeInOrder(['Poco Stock', 'Mucho Stock']);
    }

    public function test_create_product_stores_the_image_and_the_stock(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openCreate')
            ->set('name', 'Crema Facial')
            ->set('category_id', $this->carteras->id)
            ->set('detal_price', '15.50')
            ->set('major_price', '11.00')
            ->set('total_stock', 12)
            ->set('image', UploadedFile::fake()->image('crema.jpg'))
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product = Product::query()->where('name', 'Crema Facial')->firstOrFail();

        $this->assertSame(12, $product->total_stock);
        $this->assertNotNull($product->img_route);
        Storage::disk('public')->assertExists($product->img_route);
    }

    public function test_wholesale_cost_is_required_when_creating(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openCreate')
            ->set('name', 'Sin costo')
            ->set('category_id', $this->perfumes->id)
            ->set('detal_price', '10')
            ->set('major_price', '')
            ->call('saveProduct')
            ->assertHasErrors(['major_price']);

        $this->assertDatabaseMissing('products', ['name' => 'Sin costo']);
    }

    public function test_editing_updates_prices_but_never_changes_stock(): void
    {
        $product = Product::factory()->for($this->perfumes)->create([
            'detal_price' => 25.00,
            'major_price' => 18.50,
            'total_stock' => 7,
        ]);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openEdit', $product->id)
            ->set('detal_price', '30.00')
            ->set('total_stock', 999)
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product->refresh();

        $this->assertSame('30.00', $product->detal_price);
        $this->assertSame(7, $product->total_stock);
    }

    public function test_stock_adjustment_adds_and_refuses_to_go_negative(): void
    {
        $product = Product::factory()->for($this->perfumes)->create(['total_stock' => 5]);

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openAdjust', $product->id)
            ->set('adjustKind', 'out')
            ->set('adjustQuantity', 9)
            ->call('saveAdjustment')
            ->assertHasErrors(['quantity']);

        $this->assertSame(5, $product->fresh()->total_stock);

        Livewire::test('pages::inventory')
            ->call('openAdjust', $product->id)
            ->set('adjustKind', 'in')
            ->set('adjustQuantity', 3)
            ->call('saveAdjustment')
            ->assertHasNoErrors();

        $this->assertSame(8, $product->fresh()->total_stock);
    }

    public function test_deleting_a_product_is_a_soft_delete(): void
    {
        $product = Product::factory()->for($this->perfumes)->create();

        $this->actingAs($this->user);

        Livewire::test('pages::inventory')->call('deleteProduct', $product->id);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_category_can_be_created_from_the_modal(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openCategory')
            ->set('categoryName', 'Accesorios')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Accesorios']);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $this->actingAs($this->user);

        Livewire::test('pages::inventory')
            ->call('openCategory')
            ->set('categoryName', 'Perfumes')
            ->call('saveCategory')
            ->assertHasErrors(['name']);
    }
}
