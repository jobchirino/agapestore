<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SalePaymentService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Sale $creditSale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $category = Category::factory()->create();
        $this->product = Product::factory()->for($category)->create([
            'detal_price' => 100.00,
            'major_price' => 60.00,
            'total_stock' => 10,
        ]);

        // Total 200, inicial 50 → saldo 150.
        $this->creditSale = app(SaleService::class)->createSale(
            [
                'user_id' => $this->user->id,
                'payment_method' => 'efectivo',
                'payment_type' => 'credito',
                'initial_payment' => '50',
            ],
            [['product_id' => $this->product->id, 'quantity' => 2]],
        );
    }

    public function test_registering_a_payment_reduces_the_balance(): void
    {
        app(SalePaymentService::class)->registerPayment($this->creditSale, [
            'amount' => '50',
            'payment_method' => 'zelle',
        ]);

        $this->assertSame('100.00', $this->creditSale->fresh()->balanceDue());
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $this->creditSale->id, 'amount' => 50.00]);
    }

    public function test_payment_cannot_exceed_the_pending_balance(): void
    {
        try {
            app(SalePaymentService::class)->registerPayment($this->creditSale, [
                'amount' => '200',
                'payment_method' => 'efectivo',
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('supera el saldo pendiente', $e->getMessage());
        }

        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('150.00', $this->creditSale->fresh()->balanceDue());
    }

    public function test_payment_must_be_greater_than_zero(): void
    {
        foreach (['0', '-10'] as $amount) {
            try {
                app(SalePaymentService::class)->registerPayment($this->creditSale, [
                    'amount' => $amount,
                    'payment_method' => 'efectivo',
                ]);

                $this->fail("Expected ValidationException for amount {$amount}.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('mayor que cero', $e->getMessage());
            }
        }

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_cash_sales_cannot_receive_payments(): void
    {
        $sale = Sale::factory()->create([
            'user_id' => $this->user->id,
            'payment_type' => 'contado',
            'total' => '100.00',
            'initial_payment' => '0',
        ]);

        try {
            app(SalePaymentService::class)->registerPayment($sale, [
                'amount' => '10',
                'payment_method' => 'efectivo',
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Solo las ventas a crédito', $e->getMessage());
        }

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_payment_proof_is_stored_on_the_public_disk(): void
    {
        Storage::fake('public');

        $payment = app(SalePaymentService::class)->registerPayment($this->creditSale, [
            'amount' => '30',
            'payment_method' => 'pago_movil',
        ], UploadedFile::fake()->image('abono.jpg'));

        $this->assertStringStartsWith('payment-proofs/', $payment->payment_proof_path);
        Storage::disk('public')->assertExists($payment->payment_proof_path);
    }

    public function test_failed_payment_does_not_leave_an_orphan_proof(): void
    {
        Storage::fake('public');

        try {
            app(SalePaymentService::class)->registerPayment($this->creditSale, [
                'amount' => '999',
                'payment_method' => 'efectivo',
            ], UploadedFile::fake()->image('abono.jpg'));
        } catch (ValidationException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles('payment-proofs'));
    }

    public function test_deleting_a_payment_restores_the_balance_and_removes_the_proof(): void
    {
        Storage::fake('public');

        $payment = app(SalePaymentService::class)->registerPayment($this->creditSale, [
            'amount' => '50',
            'payment_method' => 'efectivo',
        ], UploadedFile::fake()->image('abono.jpg'));

        $path = $payment->payment_proof_path;

        app(SalePaymentService::class)->deletePayment($payment);

        $this->assertDatabaseMissing('sale_payments', ['id' => $payment->id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('150.00', $this->creditSale->fresh()->balanceDue());
    }

    private function saleOn(string $date): Sale
    {
        return app(SaleService::class)->createSale(
            [
                'user_id' => $this->user->id,
                'payment_method' => 'efectivo',
                'payment_type' => 'credito',
                'initial_payment' => '0',
                'date' => $date,
            ],
            [['product_id' => $this->product->id, 'quantity' => 1]],
        );
    }

    public function test_payment_cannot_be_dated_before_the_sale_day(): void
    {
        Storage::fake('public');

        $sale = $this->saleOn('2026-10-19 10:00:00');

        try {
            app(SalePaymentService::class)->registerPayment($sale, [
                'amount' => '10',
                'date' => '2026-10-15 09:00',
                'payment_method' => 'efectivo',
            ], UploadedFile::fake()->image('abono.jpg'));

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede ser anterior a la venta', $e->getMessage());
        }

        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('payment-proofs'));
    }

    public function test_payment_on_the_sale_day_is_allowed_even_at_an_earlier_time(): void
    {
        $sale = $this->saleOn('2026-10-19 18:00:00');

        app(SalePaymentService::class)->registerPayment($sale, [
            'amount' => '10',
            'date' => '2026-10-19 08:00',
            'payment_method' => 'efectivo',
        ]);

        $this->assertSame(1, SalePayment::query()->count());
    }

    public function test_future_dated_payment_is_rejected_by_the_service(): void
    {
        $sale = $this->saleOn('2026-10-19 10:00:00');

        try {
            app(SalePaymentService::class)->registerPayment($sale, [
                'amount' => '10',
                'date' => now()->addDay()->format('Y-m-d').' 09:00',
                'payment_method' => 'efectivo',
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede ser futura', $e->getMessage());
        }

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_final_payment_settles_the_sale(): void
    {
        $service = app(SalePaymentService::class);

        $service->registerPayment($this->creditSale, ['amount' => '100', 'payment_method' => 'efectivo']);
        $service->registerPayment($this->creditSale, ['amount' => '50', 'payment_method' => 'binance']);

        $this->assertSame('0.00', $this->creditSale->fresh()->balanceDue());
        $this->assertSame(1, Sale::query()->filterByStatus('credito_paid')->count());
        $this->assertSame(0, Sale::query()->filterByStatus('credito_pending')->count());
    }
}
