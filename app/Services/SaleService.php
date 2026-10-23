<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Support\LowStock;
use App\Support\Money;
use App\Support\PaymentProofs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SaleService
{
    public function __construct(private readonly TelegramService $telegram) {}

    /**
     * Registers a sale and deducts stock atomically. Any failure rolls back everything.
     *
     * Every line is sold at the product's detal_price and stores its current major_price as unit_cost.
     * Clients never choose the price.
     *
     * @param  array{client_id?: int|null, user_id: int, date?: Carbon|string|null, payment_method: string, payment_type?: string, initial_payment?: string|int|float|null, payment_proof_path?: string|null}  $saleData
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function createSale(array $saleData, array $items, ?UploadedFile $paymentProof = null): Sale
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'La venta debe tener al menos un producto.',
            ]);
        }

        // Autoritativo: la UI también lo valida, pero el servicio no debe permitir
        // fechas futuras aunque se llame desde otro lugar. Se compara por día completo.
        $saleDate = Carbon::parse($saleData['date'] ?? now());

        if ($saleDate->copy()->startOfDay()->gt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'date' => 'La fecha de la venta no puede ser futura.',
            ]);
        }

        // La inicial solo existe en ventas a crédito. Se rechaza explícitamente si llega
        // con una venta de contado, aunque la interfaz no permita enviarla.
        $initialCents = Money::toCents($saleData['initial_payment'] ?? 0);

        if ($initialCents < 0) {
            throw ValidationException::withMessages([
                'initial_payment' => 'La inicial no puede ser negativa.',
            ]);
        }

        if ($initialCents > 0 && ($saleData['payment_type'] ?? 'contado') === 'contado') {
            throw ValidationException::withMessages([
                'initial_payment' => 'Una venta de contado no puede tener inicial.',
            ]);
        }

        // Stored before the transaction so the file exists; deleted again if anything below fails.
        $proofPath = null;

        if ($paymentProof !== null) {
            $proofPath = $paymentProof->store(PaymentProofs::DIRECTORY, PaymentProofs::diskName());

            if ($proofPath === false) {
                throw new RuntimeException('No se pudo guardar el comprobante de pago.');
            }
        }

        // Se llena dentro de la transacción; se envía solo si el commit es exitoso.
        /** @var list<array{product: Product, before: int, after: int}> $lowStock */
        $lowStock = [];

        try {
            $sale = DB::transaction(function () use ($saleData, $items, $proofPath, $initialCents, $saleDate, &$lowStock): Sale {
                $lines = [];
                $totalCents = 0;

                foreach ($items as $item) {
                    $quantity = (int) $item['quantity'];

                    if ($quantity < 1) {
                        throw ValidationException::withMessages([
                            'items' => 'La cantidad de cada producto debe ser al menos 1.',
                        ]);
                    }

                    $product = Product::query()
                        ->whereKey($item['product_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $stockBefore = $product->total_stock;

                    // Re-checked with a conditional UPDATE below: lockForUpdate() is a no-op on SQLite.
                    if ($stockBefore < $quantity) {
                        throw ValidationException::withMessages([
                            'items' => "Stock insuficiente para {$product->name}: solicitado {$quantity}, disponible {$stockBefore}.",
                        ]);
                    }

                    $updated = Product::query()
                        ->whereKey($product->getKey())
                        ->where('total_stock', '>=', $quantity)
                        ->decrement('total_stock', $quantity);

                    if ($updated === 0) {
                        throw ValidationException::withMessages([
                            'items' => "Stock insuficiente para {$product->name}: solicitado {$quantity}.",
                        ]);
                    }

                    $stockAfter = $stockBefore - $quantity;

                    if (LowStock::shouldNotify($stockBefore, $stockAfter)) {
                        $lowStock[] = ['product' => $product, 'before' => $stockBefore, 'after' => $stockAfter];
                    }

                    $line = [
                        'product_id' => $product->getKey(),
                        'quantity' => $quantity,
                        'unit_price' => $product->detal_price,
                        'unit_cost' => $product->major_price,
                    ];

                    $totalCents += Money::toCents($line['unit_price']) * $quantity;
                    $lines[] = $line;
                }

                if ($initialCents > $totalCents) {
                    throw ValidationException::withMessages([
                        'initial_payment' => 'La inicial ('.Money::fromCents($initialCents).') no puede superar el total de la venta ('.Money::fromCents($totalCents).').',
                    ]);
                }

                $sale = Sale::create([
                    'client_id' => $saleData['client_id'] ?? null,
                    'user_id' => $saleData['user_id'],
                    'total' => Money::fromCents($totalCents),
                    'initial_payment' => Money::fromCents($initialCents),
                    'date' => $saleDate,
                    'payment_method' => $saleData['payment_method'],
                    'payment_type' => $saleData['payment_type'] ?? 'contado',
                    'payment_proof_path' => $proofPath ?? ($saleData['payment_proof_path'] ?? null),
                ]);

                foreach ($lines as $line) {
                    $sale->saleProducts()->create($line);
                }

                return $sale->load(['client', 'user', 'saleProducts.product']);
            });
        } catch (Throwable $e) {
            if ($proofPath !== null) {
                PaymentProofs::disk()->delete($proofPath);
            }

            throw $e;
        }

        // Ya fuera de DB::transaction(): si hubo rollback no se llega aquí, así que
        // Telegram nunca recibe una venta que no se guardó. Los fallos de Telegram
        // se capturan dentro del servicio y no afectan la venta ni al POS.
        if ($this->telegram->isConfigured()) {
            try {
                $this->telegram->notifySaleCreated($sale);

                if ($lowStock !== []) {
                    $this->telegram->notifyLowStockSummary($lowStock, $sale->id);
                }
            } catch (Throwable $e) {
                Log::warning('No se pudieron enviar las notificaciones de Telegram de la venta.', [
                    'sale_id' => $sale->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sale;
    }
}
