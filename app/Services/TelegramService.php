<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    public function __construct(private readonly BcvRateService $bcv) {}

    public function isConfigured(): bool
    {
        return $this->canSend();
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    public function hasChatId(): bool
    {
        return $this->chatId() !== '';
    }

    public function notifySaleCreated(Sale $sale): bool
    {
        if (! $this->canSend()) {
            return false;
        }

        try {
            $sale->loadMissing(['client', 'saleProducts.product', 'payments']);

            return $this->sendMessage($this->saleMessage($sale));
        } catch (Throwable $e) {
            Log::warning('No se pudo preparar la notificación de venta para Telegram.', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resumen de todas las alertas de stock de una misma venta (una sola llamada).
     *
     * @param  list<array{product: Product, before: int, after: int}>  $alerts
     */
    public function notifyLowStockSummary(array $alerts, ?int $saleId = null): bool
    {
        if ($alerts === [] || ! $this->canSend()) {
            return false;
        }

        try {
            if (count($alerts) === 1) {
                $alert = $alerts[0];
                $alert['product']->loadMissing('category');

                return $this->sendMessage($this->lowStockMessage(
                    $alert['product'],
                    $alert['before'],
                    $alert['after'],
                    $saleId,
                ));
            }

            return $this->sendMessage($this->lowStockSummaryMessage($alerts, $saleId));
        } catch (Throwable $e) {
            Log::warning('No se pudo preparar la alerta de stock para Telegram.', [
                'sale_id' => $saleId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function notifyLowStock(Product $product, int $previousStock, int $currentStock, ?int $saleId = null): bool
    {
        if (! $this->canSend()) {
            return false;
        }

        try {
            $product->loadMissing('category');

            return $this->sendMessage($this->lowStockMessage($product, $previousStock, $currentStock, $saleId));
        } catch (Throwable $e) {
            Log::warning('No se pudo preparar la alerta de stock para Telegram.', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendMessage(string $text, bool $force = false): bool
    {
        if ($text === '' || ! $this->canSend($force)) {
            return false;
        }

        try {
            $response = Http::connectTimeout(2)
                ->timeout((int) config('services.telegram.timeout', 3))
                ->post($this->endpoint('sendMessage'), [
                    'chat_id' => $this->chatId(),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful()) {
                Log::warning('Telegram rechazó el mensaje.', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar la notificación de Telegram.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(): array
    {
        if (! $this->hasToken()) {
            return [];
        }

        try {
            $response = Http::timeout(10)->get($this->endpoint('getUpdates'));

            if (! $response->successful()) {
                Log::warning('Telegram rechazó getUpdates.', ['status' => $response->status()]);

                return [];
            }

            $result = $response->json('result');

            if (! is_array($result)) {
                return [];
            }

            $updates = [];

            foreach ($result as $update) {
                if (is_array($update)) {
                    $updates[] = $update;
                }
            }

            return $updates;
        } catch (Throwable $e) {
            Log::warning('No se pudo consultar getUpdates de Telegram.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function saleMessage(Sale $sale): string
    {
        $client = $sale->client_id !== null ? $sale->client->name : 'Cliente casual';
        $type = $sale->payment_type === 'credito' ? 'Crédito' : 'Contado';

        $lines = [];
        foreach ($sale->saleProducts as $line) {
            $name = Product::withTrashed()->whereKey($line->product_id)->value('name');
            $name = is_string($name) && $name !== '' ? $name : 'Producto eliminado';
            $subtotal = (float) $line->unit_price * $line->quantity;
            $lines[] = '• '.$line->quantity.' x '.e($name).' — $'.number_format($subtotal, 2);
        }

        $message = '🛍️ <b>Nueva Venta Registrada (#'.$sale->id.')</b>'."\n\n";
        $message .= '📅 '.e($sale->dateLabel())."\n";
        $message .= '👤 Cliente: '.e($client)."\n";
        $message .= '💳 Pago: '.e($sale->paymentMethodLabel()).' · '.$type."\n\n";
        $message .= "📦 <b>Productos</b>\n".implode("\n", $lines)."\n\n";
        $message .= '💵 <b>Total: $'.number_format((float) $sale->total, 2)."</b>\n";

        $rate = $this->bcv->activeRate();

        if ($rate !== null) {
            $message .= '🇻🇪 '.Money::bs((float) $sale->total * $rate).' (BCV: '.Money::bs($rate).")\n";
        }

        $message .= '📈 Ganancia neta: +$'.number_format((float) $sale->totalProfit(), 2);

        if ($sale->payment_type === 'credito') {
            $message .= "\n\nInicial abonada: $".number_format((float) $sale->initial_payment, 2);
            $message .= "\nSaldo pendiente: $".number_format((float) $sale->balanceDue(), 2);
        }

        return $message;
    }

    private function lowStockMessage(Product $product, int $previousStock, int $currentStock, ?int $saleId = null): string
    {
        $header = $currentStock === 0
            ? '🚨 <b>Producto Agotado</b>'
            : '⚠️ <b>Alerta de Poco Stock</b>';

        $message = $header."\n";
        $message .= 'Producto: '.e($product->name)."\n";
        $message .= 'Categoría: '.e($product->category->name)."\n";
        $message .= 'Stock anterior: '.$previousStock."\n";
        $message .= 'Stock actual: '.$currentStock."\n";
        $message .= $saleId !== null ? 'Venta: #'.$saleId."\n" : "Origen: ajuste manual\n";

        return trim($message);
    }

    /**
     * @param  list<array{product: Product, before: int, after: int}>  $alerts
     */
    private function lowStockSummaryMessage(array $alerts, ?int $saleId = null): string
    {
        $allOut = true;

        foreach ($alerts as $alert) {
            if ($alert['after'] !== 0) {
                $allOut = false;
                break;
            }
        }

        $message = ($allOut ? '🚨 <b>Productos Agotados</b>' : '⚠️ <b>Alerta de Stock Bajo</b>')."\n\n";

        foreach ($alerts as $alert) {
            $product = $alert['product'];
            $product->loadMissing('category');
            $flag = $alert['after'] === 0 ? '🚨 Agotado' : '⚠️ Poco stock';
            $message .= '• <b>'.e($product->name).'</b> ('.e($product->category->name).'): '
                .$alert['before'].' → '.$alert['after'].' — '.$flag."\n";
        }

        $message .= $saleId !== null ? 'Venta: #'.$saleId."\n" : "Origen: ajuste manual\n";

        return trim($message);
    }

    private function canSend(bool $force = false): bool
    {
        if (! $force && ! (bool) config('services.telegram.enabled', true)) {
            return false;
        }

        return $this->hasToken() && $this->hasChatId();
    }

    private function token(): string
    {
        return trim((string) config('services.telegram.bot_token'));
    }

    private function chatId(): string
    {
        return trim((string) config('services.telegram.chat_id'));
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->token().'/'.$method;
    }
}
