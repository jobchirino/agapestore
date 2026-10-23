<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\BcvRateService;
use App\Services\ProductService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::factory()->create(['name' => 'Perfumes']);

        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '123456',
            'services.telegram.timeout' => 3,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->category)->create(array_merge([
            'name' => 'Perfume X',
            'detal_price' => 100.00,
            'major_price' => 60.00,
            'total_stock' => 10,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createSale(Product $product, int $quantity = 1, array $extra = []): Sale
    {
        return app(SaleService::class)->createSale(
            array_merge([
                'user_id' => $this->user->id,
                'payment_method' => 'pago_movil',
            ], $extra),
            [['product_id' => $product->id, 'quantity' => $quantity]],
        );
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    private function textOf(mixed $request): string
    {
        return (string) ($request->data()['text'] ?? '');
    }

    public function test_creating_a_product_with_low_stock_does_not_notify(): void
    {
        Http::preventStrayRequests();

        $service = app(ProductService::class);

        $service->createProduct([
            'category_id' => $this->category->id,
            'name' => 'Producto de prueba 2',
            'detal_price' => 10,
            'major_price' => 5,
            'total_stock' => 2,
        ]);

        $service->createProduct([
            'category_id' => $this->category->id,
            'name' => 'Producto de prueba 1',
            'detal_price' => 10,
            'major_price' => 5,
            'total_stock' => 1,
        ]);

        Http::assertNothingSent();
    }

    public function test_updating_a_product_does_not_notify(): void
    {
        Http::preventStrayRequests();

        $product = $this->product(['total_stock' => 2]);

        app(ProductService::class)->updateProduct($product, [
            'category_id' => $this->category->id,
            'name' => 'Perfume Editado',
            'detal_price' => 120,
            'major_price' => 70,
        ]);

        Http::assertNothingSent();
    }

    public function test_positive_stock_adjustment_does_not_notify(): void
    {
        Http::preventStrayRequests();

        $product = $this->product(['total_stock' => 2]);

        $updated = app(ProductService::class)->adjustStock($product, 5);

        $this->assertSame(7, $updated->total_stock);
        Http::assertNothingSent();
    }

    public function test_negative_adjustment_crossing_the_threshold_notifies(): void
    {
        $this->fakeTelegram();

        $product = $this->product(['total_stock' => 5]);

        app(ProductService::class)->adjustStock($product, -3);

        Http::assertSent(function ($request): bool {
            $text = $this->textOf($request);

            return str_contains($request->url(), 'sendMessage')
                && str_contains($text, 'Alerta de Poco Stock')
                && str_contains($text, 'Perfume X')
                && str_contains($text, 'Stock anterior: 5')
                && str_contains($text, 'Stock actual: 2');
        });
    }

    public function test_negative_adjustment_to_zero_notifies_as_sold_out(): void
    {
        $this->fakeTelegram();

        $product = $this->product(['total_stock' => 2]);

        app(ProductService::class)->adjustStock($product, -2);

        Http::assertSent(fn ($request): bool => str_contains($this->textOf($request), 'Producto Agotado')
            && str_contains($this->textOf($request), 'Stock actual: 0'));
    }

    public function test_two_to_one_does_not_notify_again(): void
    {
        Http::preventStrayRequests();

        $product = $this->product(['total_stock' => 2]);

        app(ProductService::class)->adjustStock($product, -1);

        Http::assertNothingSent();
    }

    public function test_a_sale_sends_the_receipt_and_the_low_stock_alert(): void
    {
        $this->fakeTelegram();

        $product = $this->product(['total_stock' => 5]);

        $sale = $this->createSale($product, 3);

        $this->assertSame(2, $product->fresh()->total_stock);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($this->textOf($request), 'Nueva Venta Registrada (#'.$sale->id.')'));
        Http::assertSent(fn ($request): bool => str_contains($this->textOf($request), 'Alerta de Poco Stock')
            && str_contains($this->textOf($request), 'Venta: #'.$sale->id));
    }

    public function test_low_stock_alerts_from_one_sale_are_consolidated(): void
    {
        $this->fakeTelegram();

        $first = $this->product(['name' => 'Producto A', 'total_stock' => 5]);
        $second = $this->product([
            'name' => 'Producto B',
            'detal_price' => 50,
            'major_price' => 30,
            'total_stock' => 4,
        ]);

        $sale = app(SaleService::class)->createSale(
            ['user_id' => $this->user->id, 'payment_method' => 'efectivo'],
            [
                ['product_id' => $first->id, 'quantity' => 3],
                ['product_id' => $second->id, 'quantity' => 2],
            ],
        );

        // Un mensaje para el recibo y uno solo para las dos alertas de stock.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($sale): bool {
            $text = $this->textOf($request);

            return str_contains($text, 'Alerta de Stock Bajo')
                && str_contains($text, 'Producto A')
                && str_contains($text, 'Producto B')
                && str_contains($text, 'Venta: #'.$sale->id);
        });
    }

    public function test_a_rolled_back_sale_does_not_notify(): void
    {
        Http::preventStrayRequests();

        $product = $this->product(['total_stock' => 1]);

        try {
            $this->createSale($product, 2);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1, $product->fresh()->total_stock);
        Http::assertNothingSent();
    }

    public function test_a_telegram_error_does_not_break_the_sale(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response('', 500)]);

        $sale = $this->createSale($this->product());

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_a_telegram_timeout_does_not_break_the_sale(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $sale = $this->createSale($this->product());

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
    }

    public function test_disabled_notifications_send_nothing(): void
    {
        config(['services.telegram.enabled' => false]);
        Http::preventStrayRequests();

        $this->createSale($this->product(['total_stock' => 5]), 3);

        Http::assertNothingSent();
    }

    public function test_missing_credentials_send_nothing(): void
    {
        config([
            'services.telegram.bot_token' => null,
            'services.telegram.chat_id' => null,
        ]);
        Http::preventStrayRequests();

        $this->createSale($this->product(['total_stock' => 5]), 3);

        Http::assertNothingSent();
    }

    public function test_the_sale_message_includes_credit_details_and_the_bcv_rate(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => ['date' => '2026-10-23', 'usd' => 886.2271, 'eur' => 993.08836371],
                'changePercentage' => ['usd' => 0.1, 'eur' => 0.1],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(BcvRateService::class)->refreshRates();

        $client = Client::factory()->create(['name' => 'María Pérez']);
        $product = $this->product(['total_stock' => 10]);

        $sale = $this->createSale($product, 1, [
            'client_id' => $client->id,
            'payment_type' => 'credito',
            'initial_payment' => '20',
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            $text = $this->textOf($request);

            return str_contains($text, 'María Pérez')
                && str_contains($text, 'Pago Móvil · Crédito')
                && str_contains($text, 'Inicial abonada: $20.00')
                && str_contains($text, 'Saldo pendiente: $80.00')
                && str_contains($text, 'Bs. 88.622,71')
                && str_contains($text, 'BCV: Bs. 886,23')
                && str_contains($text, '+$40.00');
        });

        $this->assertSame('80.00', $sale->balanceDue());
    }
}
