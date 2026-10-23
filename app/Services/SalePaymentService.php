<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Money;
use App\Support\PaymentProofs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SalePaymentService
{
    /**
     * Registra un abono sobre una venta a crédito.
     *
     * @param  array{amount: string|int|float, date?: string|Carbon|null, payment_method: string, notes?: string|null}  $data
     */
    public function registerPayment(Sale $sale, array $data, ?UploadedFile $proof = null): SalePayment
    {
        $validated = Validator::make($data, [
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
            'payment_method' => ['required', Rule::in(array_keys(Sale::PAYMENT_METHODS))],
            'notes' => ['nullable', 'string'],
        ], [
            'amount.required' => 'Indica el monto del abono.',
            'amount.numeric' => 'El monto del abono debe ser un número.',
            'amount.gt' => 'El monto del abono debe ser mayor que cero.',
            'date.date' => 'La fecha del abono no es válida.',
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago no es válido.',
        ])->validate();

        $amountCents = Money::toCents($validated['amount']);

        $proofPath = null;

        if ($proof !== null) {
            $proofPath = $proof->store(PaymentProofs::DIRECTORY, PaymentProofs::diskName());

            if ($proofPath === false) {
                throw new RuntimeException('No se pudo guardar el comprobante del abono.');
            }
        }

        try {
            return DB::transaction(function () use ($sale, $validated, $amountCents, $proofPath): SalePayment {
                // Bloqueo + relectura: un doble clic no puede registrar dos abonos que juntos
                // superen el total. (En SQLite lockForUpdate no bloquea, pero las escrituras
                // se serializan y el saldo se recalcula en cada intento.)
                $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->payment_type !== 'credito') {
                    throw ValidationException::withMessages([
                        'sale' => 'Solo las ventas a crédito admiten abonos.',
                    ]);
                }

                // Comparación por día: el abono no puede ser anterior al día de la venta,
                // ni de un día futuro. Mismo día se permite a cualquier hora.
                $paymentDate = Carbon::parse($validated['date'] ?? now());

                if ($paymentDate->copy()->startOfDay()->lt($locked->date->copy()->startOfDay())) {
                    throw ValidationException::withMessages([
                        'date' => 'El abono no puede ser anterior a la venta ('.$locked->date->format('d/m/Y').').',
                    ]);
                }

                if ($paymentDate->copy()->startOfDay()->gt(now()->startOfDay())) {
                    throw ValidationException::withMessages([
                        'date' => 'La fecha del abono no puede ser futura.',
                    ]);
                }

                $balanceCents = Money::toCents($locked->balanceDue());

                if ($amountCents > $balanceCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'El abono ('.Money::fromCents($amountCents).') supera el saldo pendiente ('.Money::fromCents($balanceCents).').',
                    ]);
                }

                return SalePayment::create([
                    'sale_id' => $locked->id,
                    'amount' => Money::fromCents($amountCents),
                    'date' => $paymentDate,
                    'payment_method' => $validated['payment_method'],
                    'payment_proof_path' => $proofPath,
                    'notes' => $validated['notes'] ?? null,
                    'user_id' => auth()->id(),
                ]);
            });
        } catch (Throwable $e) {
            // Sin archivos huérfanos si el abono no llega a guardarse.
            if ($proofPath !== null) {
                PaymentProofs::disk()->delete($proofPath);
            }

            throw $e;
        }
    }

    /**
     * Elimina un abono (corrección de un error) y su comprobante, si tenía.
     */
    public function deletePayment(SalePayment $payment): void
    {
        $proofPath = $payment->payment_proof_path;

        DB::transaction(fn () => $payment->delete());

        if ($proofPath !== null) {
            PaymentProofs::disk()->delete($proofPath);
        }
    }
}
