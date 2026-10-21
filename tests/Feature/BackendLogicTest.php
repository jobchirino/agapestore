<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\ClientService;
use App\Services\ProductService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BackendLogicTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Saturday 17 Oct 2026, so week and month boundaries are predictable.
        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- SaleService

    public function test_create_sale_freezes_price_and_cost_and_computes_exact_total_and_profit(): void
    {
        $category = Category::factory()->create();

        // detal_price = public price, major_price = what the owner pays the supplier.
        $perfume = Product::factory()->for($category)->create([
            'detal_price' => 25.00,
            'major_price' => 18.50,
            'total_stock' => 20,
        ]);
        $wallet = Product::factory()->for($category)->create([
            'detal_price' => 40.00,
            'major_price' => 22.00,
            'total_stock' => 12,
        ]);
        $necklace = Product::factory()->for($category)->create([
            'detal_price' => 8.00,
            'major_price' => 5.00,
            'total_stock' => 50,
        ]);

        $sale = app(SaleService::class)->createSale(
            ['user_id' => $this->user->id, 'payment_method' => 'zelle'],
            [
                ['product_id' => $perfume->id, 'quantity' => 2], // sells 2 x 25.00 = 50.00, costs 2 x 18.50 = 37.00
                ['product_id' => $wallet->id, 'quantity' => 3], // sells 3 x 40.00 = 120.00, costs 3 x 22.00 = 66.00
                ['product_id' => $necklace->id, 'quantity' => 5], // sells 5 x 8.00 = 40.00, costs 5 x 5.00 = 25.00
            ],
        );

        $this->assertSame('210.00', $sale->total);
        $this->assertSame('82.00', $sale->totalProfit()); // 13.00 + 54.00 + 15.00
        $this->assertCount(3, $sale->saleProducts);

        $this->assertSame(18, $perfume->fresh()->total_stock);
        $this->assertSame(9, $wallet->fresh()->total_stock);
        $this->assertSame(45, $necklace->fresh()->total_stock);

        // Change the prices after the sale: the sale lines must keep the frozen values.
        $perfume->update(['detal_price' => 99.00, 'major_price' => 1.00]);

        $lines = SaleProduct::query()->where('sale_id', $sale->id)->get()->keyBy('product_id');

        $this->assertSame('40.00', $lines[$wallet->id]->unit_price);
        $this->assertSame('22.00', $lines[$wallet->id]->unit_cost);
        $this->assertSame('13.00', $lines[$perfume->id]->profit());
        $this->assertSame('82.00', $sale->fresh()->totalProfit());
    }

    public function test_insufficient_stock_throws_validation_exception_and_rolls_back_the_whole_sale(): void
    {
        $category = Category::factory()->create();

        $available = Product::factory()->for($category)->create([
            'detal_price' => 10.00,
            'total_stock' => 10,
        ]);
        $scarce = Product::factory()->for($category)->create([
            'name' => 'Cartera escasa',
            'detal_price' => 40.00,
            'total_stock' => 2,
        ]);

        try {
            app(SaleService::class)->createSale(
                ['user_id' => $this->user->id, 'payment_method' => 'efectivo'],
                [
                    ['product_id' => $available->id, 'quantity' => 2],
                    ['product_id' => $scarce->id, 'quantity' => 3],
                ],
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cartera escasa', $e->getMessage());
            $this->assertStringContainsString('disponible 2', $e->getMessage());
        }

        $this->assertSame(10, $available->fresh()->total_stock);
        $this->assertSame(2, $scarce->fresh()->total_stock);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SaleProduct::query()->count());
    }

    public function test_credit_sale_stores_the_initial_payment_and_derived_balance(): void
    {
        $product = Product::factory()->create(['detal_price' => 50.00, 'major_price' => 30.00, 'total_stock' => 5]);

        $sale = app(SaleService::class)->createSale(
            [
                'user_id' => $this->user->id,
                'payment_method' => 'efectivo',
                'payment_type' => 'credito',
                'initial_payment' => '20',
            ],
            [['product_id' => $product->id, 'quantity' => 1]],
        );

        $this->assertSame('20.00', $sale->initial_payment);
        $this->assertSame('30.00', $sale->balanceDue());
    }

    public function test_initial_payment_cannot_exceed_the_sale_total(): void
    {
        $product = Product::factory()->create(['detal_price' => 10.00, 'total_stock' => 5]);

        try {
            app(SaleService::class)->createSale(
                [
                    'user_id' => $this->user->id,
                    'payment_method' => 'efectivo',
                    'payment_type' => 'credito',
                    'initial_payment' => '25',
                ],
                [['product_id' => $product->id, 'quantity' => 1]],
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede superar el total', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(5, $product->fresh()->total_stock);
    }

    public function test_cash_sale_cannot_have_an_initial_payment(): void
    {
        $product = Product::factory()->create(['detal_price' => 10.00, 'total_stock' => 5]);

        try {
            app(SaleService::class)->createSale(
                [
                    'user_id' => $this->user->id,
                    'payment_method' => 'efectivo',
                    'payment_type' => 'contado',
                    'initial_payment' => '5',
                ],
                [['product_id' => $product->id, 'quantity' => 1]],
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede tener inicial', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_negative_initial_payment_is_rejected(): void
    {
        $product = Product::factory()->create(['detal_price' => 10.00, 'total_stock' => 5]);

        try {
            app(SaleService::class)->createSale(
                [
                    'user_id' => $this->user->id,
                    'payment_method' => 'efectivo',
                    'payment_type' => 'credito',
                    'initial_payment' => '-5',
                ],
                [['product_id' => $product->id, 'quantity' => 1]],
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede ser negativa', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_quantity_below_one_is_rejected(): void
    {
        $product = Product::factory()->create(['total_stock' => 10]);

        $this->assertValidationFails(
            [['product_id' => $product->id, 'quantity' => 0]],
            'La cantidad de cada producto debe ser al menos 1.',
        );
        $this->assertValidationFails(
            [['product_id' => $product->id, 'quantity' => -2]],
            'La cantidad de cada producto debe ser al menos 1.',
        );

        $this->assertSame(10, $product->fresh()->total_stock);
    }

    public function test_empty_cart_is_rejected(): void
    {
        $this->assertValidationFails([], 'La venta debe tener al menos un producto.');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_payment_proof_is_stored_and_its_path_saved(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['total_stock' => 5, 'detal_price' => 10.00]);

        $sale = app(SaleService::class)->createSale(
            ['user_id' => $this->user->id, 'payment_method' => 'pago_movil'],
            [['product_id' => $product->id, 'quantity' => 1]],
            UploadedFile::fake()->image('comprobante.jpg'),
        );

        $this->assertNotNull($sale->payment_proof_path);
        $this->assertStringStartsWith('payment-proofs/', $sale->payment_proof_path);
        Storage::disk('public')->assertExists($sale->payment_proof_path);
    }

    public function test_failed_sale_does_not_leave_an_orphan_payment_proof(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['total_stock' => 1, 'detal_price' => 10.00]);

        try {
            app(SaleService::class)->createSale(
                ['user_id' => $this->user->id, 'payment_method' => 'pago_movil'],
                [['product_id' => $product->id, 'quantity' => 5]],
                UploadedFile::fake()->image('comprobante.jpg'),
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles('payment-proofs'));
        $this->assertSame(1, $product->fresh()->total_stock);
    }

    // ---------------------------------------------------------------- Product margins

    public function test_product_profit_margin_helpers(): void
    {
        $product = Product::factory()->create(['detal_price' => 25.00, 'major_price' => 18.50]);

        $this->assertSame('6.50', $product->profitMarginAmount());
        $this->assertSame(35.14, $product->profitMarginPercentage()); // markup: 6.50 / 18.50 * 100
    }

    // ---------------------------------------------------------------- ProductService

    public function test_adjust_stock_adds_and_refuses_to_go_negative(): void
    {
        $product = Product::factory()->create(['total_stock' => 5]);
        $service = app(ProductService::class);

        $service->adjustStock($product, 3);
        $this->assertSame(8, $product->fresh()->total_stock);

        $service->adjustStock($product, -3);
        $this->assertSame(5, $product->fresh()->total_stock);

        try {
            $service->adjustStock($product, -6);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Disponible: 5', $e->getMessage());
        }

        $this->assertSame(5, $product->fresh()->total_stock);
    }

    public function test_create_product_stores_the_image_on_the_public_disk(): void
    {
        Storage::fake('public');

        $product = app(ProductService::class)->createProduct([
            'category_id' => Category::factory()->create()->id,
            'name' => 'Perfume con foto',
            'detal_price' => 25.00,
            'major_price' => 18.50,
            'total_stock' => 4,
        ], UploadedFile::fake()->image('perfume.jpg'));

        $this->assertStringStartsWith('products/', $product->img_route);
        Storage::disk('public')->assertExists($product->img_route);
        $this->assertSame(4, $product->total_stock);
    }

    public function test_update_product_replaces_the_image_and_ignores_stock_changes(): void
    {
        Storage::fake('public');
        $service = app(ProductService::class);

        $product = $service->createProduct([
            'category_id' => Category::factory()->create()->id,
            'name' => 'Cartera',
            'detal_price' => 40.00,
            'major_price' => 22.00,
            'total_stock' => 7,
        ], UploadedFile::fake()->image('antigua.jpg'));

        $oldPath = $product->img_route;

        $service->updateProduct($product, [
            'name' => 'Cartera de cuero',
            'total_stock' => 999,
        ], UploadedFile::fake()->image('nueva.jpg'));

        $product->refresh();

        $this->assertSame('Cartera de cuero', $product->name);
        $this->assertSame(7, $product->total_stock);
        $this->assertNotSame($oldPath, $product->img_route);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($product->img_route);
    }

    public function test_major_price_is_required_and_cannot_be_negative(): void
    {
        $service = app(ProductService::class);
        $base = [
            'category_id' => Category::factory()->create()->id,
            'name' => 'Producto sin costo',
            'detal_price' => 10.00,
        ];

        $this->assertInvalidField(fn () => $service->createProduct($base), 'major_price');
        $this->assertInvalidField(fn () => $service->createProduct([...$base, 'major_price' => -1]), 'major_price');

        $product = Product::factory()->create(['major_price' => 5.00]);

        $this->assertInvalidField(fn () => $service->updateProduct($product, ['major_price' => -0.01]), 'major_price');
        $this->assertSame('5.00', $product->fresh()->major_price);
    }

    // ---------------------------------------------------------------- ClientService

    public function test_create_and_update_client(): void
    {
        $service = app(ClientService::class);

        $client = $service->createClient([
            'name' => 'María Pérez',
            'phone' => '0414-1112233',
            'email' => 'maria@example.com',
        ]);

        $this->assertDatabaseHas('clients', ['name' => 'María Pérez', 'phone' => '0414-1112233']);

        $service->updateClient($client, ['address' => 'Av. Principal']);

        $client->refresh();
        $this->assertSame('Av. Principal', $client->address);
        $this->assertSame('0414-1112233', $client->phone);
    }

    public function test_client_name_is_required_and_email_must_be_valid(): void
    {
        $service = app(ClientService::class);

        try {
            $service->createClient(['name' => '', 'email' => 'no-es-correo']);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame(0, Client::query()->count());
    }

    // ---------------------------------------------------------------- CategoryService

    public function test_category_name_must_be_unique_but_can_keep_its_own_name(): void
    {
        $service = app(CategoryService::class);

        $perfumes = $service->createCategory(['name' => 'Perfumes']);
        $service->updateCategory($perfumes, ['name' => 'Perfumes', 'description' => 'Fragancias']);
        $this->assertSame('Fragancias', $perfumes->fresh()->description);

        $this->expectException(ValidationException::class);
        $service->createCategory(['name' => 'Perfumes']);
    }

    public function test_category_slug_is_normalized_for_uniqueness(): void
    {
        $category = app(CategoryService::class)->createCategory(['name' => 'Bisutería Premium']);

        $this->assertSame('bisuteria-premium', $category->slug);

        $this->expectException(ValidationException::class);
        app(CategoryService::class)->createCategory(['name' => 'bisuteria premium']);
    }

    public function test_category_with_products_cannot_be_deleted_even_if_the_product_is_trashed(): void
    {
        $service = app(CategoryService::class);
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $product->delete();

        try {
            $service->deleteCategory($category);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
        }

        $this->assertNotNull($category->fresh());
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $category = Category::factory()->create();

        app(CategoryService::class)->deleteCategory($category);

        $this->assertNull(Category::query()->find($category->id));
    }

    // ---------------------------------------------------------------- Product scopes

    public function test_product_search_by_name_is_case_insensitive_partial_match(): void
    {
        $category = Category::factory()->create();
        $perfume = Product::factory()->for($category)->create(['name' => 'Perfume Floral 50ml']);
        $wallet = Product::factory()->for($category)->create(['name' => 'Cartera de cuero']);

        $this->assertEqualsCanonicalizing(
            [$perfume->id],
            Product::query()->search('floral')->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$perfume->id, $wallet->id],
            Product::query()->search(null)->pluck('id')->all(),
        );
    }

    public function test_product_by_category_filters_and_ignores_null(): void
    {
        $perfumes = Category::factory()->create();
        $carteras = Category::factory()->create();
        $perfume = Product::factory()->for($perfumes)->create();
        $wallet = Product::factory()->for($carteras)->create();

        $this->assertEqualsCanonicalizing(
            [$perfume->id],
            Product::query()->byCategory($perfumes->id)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$perfume->id, $wallet->id],
            Product::query()->byCategory(null)->pluck('id')->all(),
        );
    }

    public function test_in_stock_excludes_products_with_zero_stock(): void
    {
        $available = Product::factory()->create(['total_stock' => 3]);
        Product::factory()->create(['total_stock' => 0]);

        $this->assertEqualsCanonicalizing(
            [$available->id],
            Product::query()->inStock()->pluck('id')->all(),
        );
    }

    // ---------------------------------------------------------------- Client scope

    public function test_client_search_matches_name_or_phone(): void
    {
        $maria = Client::factory()->create(['name' => 'María Pérez', 'phone' => '0414-1112233']);
        $juan = Client::factory()->create(['name' => 'Juan López', 'phone' => '0424-9998877']);

        $this->assertEqualsCanonicalizing(
            [$maria->id],
            Client::query()->search('pérez')->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$juan->id],
            Client::query()->search('9998877')->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$maria->id, $juan->id],
            Client::query()->search(null)->pluck('id')->all(),
        );
    }

    // ---------------------------------------------------------------- Sale scope

    public function test_filter_by_period_uses_the_sale_date_column_not_created_at(): void
    {
        // created_at is "now" for every row, so only the `date` column can separate them.
        $todaySale = $this->makeSale('2026-10-17 09:00:00');
        $fridaySale = $this->makeSale('2026-10-16 18:00:00');
        $mondaySale = $this->makeSale('2026-10-12 08:00:00');
        $previousSundaySale = $this->makeSale('2026-10-11 23:30:00');
        $septemberSale = $this->makeSale('2026-09-30 23:00:00');
        $octoberThirdSale = $this->makeSale('2026-10-03 10:00:00');

        $this->assertEqualsCanonicalizing(
            [$todaySale->id],
            Sale::query()->filterByPeriod('today')->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$todaySale->id, $fridaySale->id, $mondaySale->id],
            Sale::query()->filterByPeriod('week')->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$todaySale->id, $fridaySale->id, $mondaySale->id, $previousSundaySale->id, $octoberThirdSale->id],
            Sale::query()->filterByPeriod('month')->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing(
            [$octoberThirdSale->id],
            Sale::query()->filterByPeriod('custom', '2026-10-01', '2026-10-05')->pluck('id')->all(),
        );

        // The end date is inclusive for the whole day (the 18:00 sale must be included).
        $this->assertEqualsCanonicalizing(
            [$fridaySale->id],
            Sale::query()->filterByPeriod('custom', '2026-10-16', '2026-10-16')->pluck('id')->all(),
        );

        $this->assertNotContains($septemberSale->id, Sale::query()->filterByPeriod('month')->pluck('id')->all());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    private function assertValidationFails(array $items, string $expectedMessage): void
    {
        try {
            app(SaleService::class)->createSale(
                ['user_id' => $this->user->id, 'payment_method' => 'efectivo'],
                $items,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expectedMessage, implode(' ', $e->validator->errors()->all()));
        }
    }

    private function assertInvalidField(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected ValidationException for [{$field}] was not thrown.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function makeSale(string $date): Sale
    {
        return Sale::factory()->create([
            'user_id' => $this->user->id,
            'date' => $date,
        ]);
    }
}
