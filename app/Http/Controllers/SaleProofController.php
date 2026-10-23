<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\PaymentProofs;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SaleProofController extends Controller
{
    /**
     * Descarga el comprobante cargado al registrar la venta.
     */
    public function sale(Sale $sale): StreamedResponse
    {
        return $this->download(
            $sale->payment_proof_path,
            "comprobante-venta-{$sale->id}-{$sale->date->format('Y-m-d')}",
        );
    }

    /**
     * Muestra el comprobante de la venta dentro del navegador (inline).
     */
    public function showSale(Sale $sale): StreamedResponse
    {
        return $this->preview(
            $sale->payment_proof_path,
            "comprobante-venta-{$sale->id}-{$sale->date->format('Y-m-d')}",
        );
    }

    /**
     * Descarga el comprobante de un abono concreto.
     */
    public function payment(Sale $sale, SalePayment $payment): StreamedResponse
    {
        $this->ensureBelongsToSale($sale, $payment);

        return $this->download(
            $payment->payment_proof_path,
            "comprobante-abono-{$payment->id}-{$payment->date->format('Y-m-d')}",
        );
    }

    /**
     * Muestra el comprobante de un abono dentro del navegador (inline).
     */
    public function showPayment(Sale $sale, SalePayment $payment): StreamedResponse
    {
        $this->ensureBelongsToSale($sale, $payment);

        return $this->preview(
            $payment->payment_proof_path,
            "comprobante-abono-{$payment->id}-{$payment->date->format('Y-m-d')}",
        );
    }

    private function ensureBelongsToSale(Sale $sale, SalePayment $payment): void
    {
        if ($payment->sale_id !== $sale->id) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }
    }

    private function download(?string $path, string $basename): StreamedResponse
    {
        $path = $this->validatedPath($path);
        $disk = Storage::disk(PaymentProofs::diskName());

        return $disk->download($path, $this->filename($path, $basename), [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function preview(?string $path, string $basename): StreamedResponse
    {
        $path = $this->validatedPath($path);
        $disk = Storage::disk(PaymentProofs::diskName());

        return $disk->response($path, $this->filename($path, $basename), [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function validatedPath(?string $path): string
    {
        // El path viene de la base de datos, pero nunca debe salir de payment-proofs/.
        if ($path === null || ! Str::startsWith($path, PaymentProofs::DIRECTORY.'/')) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }

        if (! Storage::disk(PaymentProofs::diskName())->exists($path)) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }

        return $path;
    }

    private function filename(string $path, string $basename): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' ? "{$basename}.jpg" : "{$basename}.{$extension}";
    }
}
