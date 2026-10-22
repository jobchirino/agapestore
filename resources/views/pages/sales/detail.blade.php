<?php

use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\SalePaymentService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Componente HIJO aislado: detalle de venta y abonos.
 *
 * El historial tiene un buscador con wire:model.live; si este formulario viviera ahí, una
 * petición del buscador podría borrar lo que se está escribiendo (mismo motivo que el
 * alta rápida de cliente del POS). Al ser hijo, su estado es independiente.
 *
 * El padre le pasa el id de la venta y escucha `payment-registered` / `payment-deleted`
 * para refrescar la tabla y los KPIs.
 */
new class extends Component {
    use WithFileUploads;

    public ?int $saleId = null;

    // Formulario de abono.
    public string $amount = '';
    public string $date = '';
    public string $payment_method = 'efectivo';
    public $proof = null;
    public ?string $formError = null;

    // Confirmación de borrado de abono.
    public ?int $deletingPaymentId = null;

    public function mount(?int $saleId = null): void
    {
        $this->saleId = $saleId;
        $this->date = now()->format('Y-m-d\TH:i');

        // El modal NO se abre aquí: el padre reenvía saleId en cada render y el mount se
        // repetiría, reabriendo el detalle solo. Lo abre el evento open-sale.
    }

    /**
     * El padre envía el id por evento: los props de un hijo no se actualizan de forma
     * fiable al cambiar el estado del padre, así que el evento es explícito.
     */
    #[On('open-sale')]
    public function openFromParent(int $saleId): void
    {
        $this->saleId = $saleId;
        Flux::modal('sale-detail')->show();
    }

    #[Computed]
    public function sale(): ?Sale
    {
        if ($this->saleId === null) {
            return null;
        }

        return Sale::query()
            ->with(['client', 'user', 'saleProducts.product', 'payments.user'])
            ->withSum('payments', 'amount')
            ->find($this->saleId);
    }

    /**
     * @return Collection<int, SalePayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        $sale = $this->sale;

        return $sale === null ? collect() : $sale->payments->sortByDesc('date')->values();
    }

    #[Computed]
    public function deletingPayment(): ?SalePayment
    {
        return $this->deletingPaymentId === null
            ? null
            : SalePayment::query()->find($this->deletingPaymentId);
    }

    /**
     * Saldo que quedaría después del abono que se está escribiendo.
     */
    #[Computed]
    public function previewBalance(): string
    {
        $sale = $this->sale;

        if ($sale === null) {
            return '0.00';
        }

        $remaining = Money::toCents($sale->balanceDue()) - Money::toCents($this->amount);

        return Money::fromCents(max(0, $remaining));
    }

    public function fillBalance(): void
    {
        if ($this->sale !== null) {
            $this->amount = $this->sale->balanceDue();
        }
    }

    public function savePayment(SalePaymentService $payments): void
    {
        $this->formError = null;

        if ($this->sale === null) {
            return;
        }

        $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
            'proof' => ['nullable', 'image', 'max:5120'],
        ], [
            'amount.required' => 'Indica el monto del abono.',
            'amount.numeric' => 'El monto del abono debe ser un número.',
            'amount.gt' => 'El monto del abono debe ser mayor que cero.',
            'date.required' => 'Indica la fecha del abono.',
            'date.date' => 'La fecha del abono no es válida.',
            'proof.image' => 'El comprobante debe ser una imagen.',
            'proof.max' => 'El comprobante no puede pesar más de 5 MB.',
        ]);

        // Se compara por día completo: un abono el mismo día de la venta se permite,
        // anterior a ese día no, y futuro tampoco.
        $paymentDay = Carbon::parse($this->date)->startOfDay();

        if ($paymentDay->gt(now()->startOfDay())) {
            $this->addError('date', 'La fecha del abono no puede ser futura.');

            return;
        }

        if ($this->sale !== null && $paymentDay->lt($this->sale->date->copy()->startOfDay())) {
            $this->addError('date', 'El abono no puede ser anterior a la venta ('.$this->sale->date->format('d/m/Y').').');

            return;
        }

        try {
            $payments->registerPayment($this->sale, [
                'amount' => $this->amount,
                'date' => $this->date,
                'payment_method' => $this->payment_method,
            ], $this->proof);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['sale'])) {
                $this->formError = collect($errors['sale'])->first();
            } else {
                $this->setErrorBag($errors);
            }

            Flux::toast(variant: 'danger', text: 'No se pudo registrar el abono.');

            return;
        }

        $this->reset(['amount', 'proof', 'formError']);
        $this->date = now()->format('Y-m-d\TH:i');
        $this->dispatch('payment-registered');

        Flux::toast(variant: 'success', text: 'Abono registrado.');
    }

    public function removeProof(): void
    {
        $this->proof = null;
        $this->resetErrorBag('proof');
    }

    public function proofPreviewUrl(): ?string
    {
        if ($this->proof === null) {
            return null;
        }

        return str_starts_with((string) $this->proof->getMimeType(), 'image/')
            ? $this->proof->temporaryUrl()
            : null;
    }

    /**
     * Vista previa en 12 horas del input datetime-local (que usa el formato del navegador).
     */
    public function datePreview(): string
    {
        if ($this->date === '') {
            return '—';
        }

        try {
            return Carbon::parse($this->date)->format('d/m/Y g:i A');
        } catch (\Throwable) {
            return 'fecha no válida';
        }
    }

    /**
     * Se dispara al cerrar el modal (X, botón o Escape) y avisa al historial.
     */
    public function closeDetail(): void
    {
        $this->dispatch('sale-closed');
    }

    public function askDeletePayment(int $id): void
    {
        $this->deletingPaymentId = $id;
        Flux::modal('delete-payment')->show();
    }

    public function confirmDeletePayment(SalePaymentService $payments): void
    {
        if ($this->deletingPaymentId === null) {
            return;
        }

        $payments->deletePayment(SalePayment::query()->findOrFail($this->deletingPaymentId));

        Flux::modal('delete-payment')->close();
        $this->deletingPaymentId = null;
        $this->dispatch('payment-deleted');

        Flux::toast(variant: 'success', text: 'Abono eliminado.');
    }
}; ?>

@php($sale = $this->sale)

{{-- Un solo elemento raíz: Livewire exige exactamente uno por componente. --}}
<div>
<flux:modal name="sale-detail" class="w-full max-w-3xl" wire:close="closeDetail">
    @if ($sale)
        <div class="flex flex-col gap-6">
            {{-- pe-10 deja libre la esquina donde Flux dibuja su X de cierre; las píldoras
                 van en su propia fila para no invadirla en ningún tamaño de pantalla. --}}
            <div class="flex flex-col gap-3 pe-10">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• VENTA #{{ $sale->id }}</p>
                    <h2 class="font-display text-2xl font-medium text-zinc-900 dark:text-white">{{ $sale->dateLabel() }}</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $sale->client?->name ?? 'Cliente casual' }}
                        @if ($sale->client?->phone)
                            · {{ $sale->client->phone }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-md bg-agape-soft px-2 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                        {{ $sale->paymentMethodLabel() }}
                    </span>
                    <span class="rounded-md px-2 py-1 text-[11px] font-bold uppercase tracking-[0.06em] {{ $sale->payment_type === 'credito' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-zinc-900/5 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }}">
                        {{ $sale->payment_type === 'credito' ? 'Crédito' : 'Contado' }}
                    </span>
                </div>
            </div>

            {{-- Líneas --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b border-gray-100 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                        <tr>
                            <th class="py-2 font-bold">Producto</th>
                            <th class="py-2 text-right font-bold">Cant.</th>
                            <th class="py-2 text-right font-bold">Precio</th>
                            <th class="py-2 text-right font-bold">Costo</th>
                            <th class="py-2 text-right font-bold">Subtotal</th>
                            <th class="py-2 text-right font-bold">Ganancia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($sale->saleProducts as $line)
                            <tr wire:key="line-{{ $line->id }}">
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        @if ($line->product?->img_route)
                                            <img src="{{ asset('storage/'.$line->product->img_route) }}" alt="{{ $line->product->name }}" class="size-8 rounded-md object-cover" />
                                        @endif
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $line->product?->name ?? 'Producto eliminado' }}</span>
                                    </div>
                                </td>
                                <td class="py-2 text-right tnum">{{ $line->quantity }}</td>
                                <td class="py-2 text-right tnum">${{ number_format((float) $line->unit_price, 2) }}</td>
                                <td class="py-2 text-right tnum text-zinc-500 dark:text-zinc-400">${{ number_format((float) $line->unit_cost, 2) }}</td>
                                <td class="py-2 text-right font-semibold tnum text-zinc-900 dark:text-white">${{ number_format((float) $line->unit_price * $line->quantity, 2) }}</td>
                                <td class="py-2 text-right font-semibold tnum text-profit-text dark:text-emerald-300">+${{ number_format((float) $line->profit(), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Resumen financiero. En contado solo hay total y ganancia: no hay cuotas. --}}
            <div class="grid grid-cols-2 gap-3 {{ $sale->payment_type === 'credito' ? 'sm:grid-cols-5' : 'sm:grid-cols-2' }}">
                <div class="rounded-xl bg-zinc-900/5 px-4 py-3 dark:bg-white/5">
                    <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Total</p>
                    <p class="text-lg font-bold tnum whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $sale->total, 2) }}</p>
                </div>

                @if ($sale->payment_type === 'credito')
                    <div class="rounded-xl bg-zinc-900/5 px-4 py-3 dark:bg-white/5">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Inicial</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $sale->initial_payment, 2) }}</p>
                    </div>
                    <div class="rounded-xl bg-zinc-900/5 px-4 py-3 dark:bg-white/5">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Abonos</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $this->payments->sum('amount'), 2) }}</p>
                    </div>
                    <div class="rounded-xl px-4 py-3 {{ (float) $sale->balanceDue() > 0 ? 'bg-warn-bg dark:bg-amber-500/15' : 'bg-profit-bg dark:bg-emerald-500/15' }}">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] {{ (float) $sale->balanceDue() > 0 ? 'text-warn-text dark:text-amber-300' : 'text-profit-text dark:text-emerald-300' }}">Pendiente</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap {{ (float) $sale->balanceDue() > 0 ? 'text-warn-text dark:text-amber-300' : 'text-profit-text dark:text-emerald-300' }}">${{ number_format((float) $sale->balanceDue(), 2) }}</p>
                    </div>
                @endif

                <div class="rounded-xl bg-profit-bg px-4 py-3 dark:bg-emerald-500/15">
                    <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-profit-text dark:text-emerald-300">Ganancia</p>
                    <p class="text-lg font-bold tnum whitespace-nowrap text-profit-text dark:text-emerald-300">+${{ number_format((float) $sale->totalProfit(), 2) }}</p>
                </div>
            </div>

            {{-- Movimientos de pago: solo en crédito. En contado no hay cuotas ni inicial. --}}
            @if ($sale->payment_type === 'credito')
            <div>
                <h3 class="mb-2 text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Movimientos de pago</h3>

                <div class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-200/60 dark:divide-white/10 dark:border-white/10">
                    {{-- La inicial no es un abono: se muestra como primer movimiento. --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="rounded-md bg-zinc-900/5 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:bg-white/10 dark:text-zinc-400">Inicial</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $sale->dateLabel() }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $sale->paymentMethodLabel() }}</span>
                        </div>
                        <span class="font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $sale->initial_payment, 2) }}</span>
                    </div>

                    @foreach ($this->payments as $payment)
                        <div wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-md bg-agape-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.06em] text-agape dark:bg-agape-dark/15 dark:text-agape-dark">Abono</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $payment->dateLabel() }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $payment->paymentMethodLabel() }}</span>
                                @if ($payment->payment_proof_path)
                                    <a href="{{ asset('storage/'.$payment->payment_proof_path) }}" target="_blank" rel="noopener" class="text-xs font-semibold text-agape underline dark:text-agape-dark">Comprobante</a>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $payment->amount, 2) }}</span>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="askDeletePayment({{ $payment->id }})" aria-label="Eliminar abono" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Comprobante de la venta --}}
            @if ($sale->payment_proof_path)
                <div>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Comprobante de la venta</h3>
                    <div class="flex flex-wrap items-start gap-4">
                        <img src="{{ asset('storage/'.$sale->payment_proof_path) }}" alt="Comprobante de pago" class="max-h-56 rounded-xl border border-gray-200/60 object-contain dark:border-white/10" />
                        <a href="{{ asset('storage/'.$sale->payment_proof_path) }}" target="_blank" rel="noopener" class="text-sm font-semibold text-agape underline dark:text-agape-dark">
                            Ver tamaño completo
                        </a>
                    </div>
                </div>
            @endif

            {{-- Abono --}}
            @if ($sale->payment_type === 'credito' && (float) $sale->balanceDue() > 0)
                <form wire:submit="savePayment" class="flex flex-col gap-4 rounded-xl bg-zinc-900/5 p-4 dark:bg-white/5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Registrar abono</h3>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            Pendiente tras el abono: <span class="font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $this->previewBalance, 2) }}</span>
                        </span>
                    </div>

                    @if ($formError)
                        <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $formError }}</div>
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="flex items-end gap-2">
                            <flux:input wire:model.live.debounce.500ms="amount" type="number" step="0.01" min="0.01" label="Monto" prefix="$" class="flex-1" />
                            <flux:button type="button" variant="outline" size="sm" wire:click="fillBalance">Liquidar saldo</flux:button>
                        </div>

                        <flux:select wire:model="payment_method" label="Método de pago">
                            @foreach (\App\Models\Sale::PAYMENT_METHODS as $value => $label)
                                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- min en el inicio del día de la venta: coherente con la regla por día. --}}
                    <div class="flex flex-col gap-1">
                        <flux:input
                            wire:model.live.debounce.500ms="date"
                            type="datetime-local"
                            label="Fecha del abono"
                            :min="$sale->date->copy()->startOfDay()->format('Y-m-d\TH:i')"
                        />
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Se registrará: {{ $this->datePreview() }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            No puede ser anterior a la venta ({{ $sale->date->format('d/m/Y') }}).
                        </p>
                    </div>

                    {{-- Comprobante opcional, con la misma comprobación previa de tamaño. --}}
                    @if ($proof)
                        <div class="flex items-center gap-3 rounded-xl border border-gray-200/60 bg-white p-3 dark:border-white/10 dark:bg-panel-raise">
                            @if ($proofUrl = $this->proofPreviewUrl())
                                <img src="{{ $proofUrl }}" alt="Comprobante" class="size-14 rounded-lg object-cover" />
                            @endif
                            <span class="flex-1 text-xs text-zinc-500 dark:text-zinc-400">Comprobante listo.</span>
                            <flux:button type="button" size="sm" variant="ghost" icon="x-mark" wire:click="removeProof">Quitar</flux:button>
                        </div>
                    @else
                        <label
                            class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-4 text-center transition hover:border-agape dark:border-white/15 dark:bg-white/5"
                            x-data="{ localError: null, uploading: false }"
                        >
                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">Comprobante del abono (opcional, hasta 5 MB)</span>
                            <input
                                type="file"
                                accept="image/*"
                                class="sr-only"
                                x-on:change="
                                    localError = null
                                    const file = $event.target.files[0]
                                    if (! file) return
                                    if (! file.type.startsWith('image/')) { localError = 'El archivo debe ser una imagen (PNG o JPG).'; $event.target.value = ''; return }
                                    if (file.size > 5 * 1024 * 1024) {
                                        localError = 'Tu imagen pesa ' + (file.size / 1024 / 1024).toFixed(1) + ' MB y el máximo es 5 MB.'
                                        $event.target.value = ''
                                        return
                                    }
                                    uploading = true
                                    $wire.upload('proof', file,
                                        () => { uploading = false; $event.target.value = '' },
                                        () => { uploading = false; localError = 'No se pudo subir el comprobante.'; $event.target.value = '' }
                                    )
                                "
                            />
                            <span x-show="uploading" x-cloak class="text-xs font-semibold text-agape dark:text-agape-dark">Subiendo...</span>
                            <span x-show="localError" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400" x-text="localError"></span>
                        </label>
                    @endif

                    <flux:error name="proof" />

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">Registrar abono</flux:button>
                    </div>
                </form>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cerrar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    @endif
</flux:modal>

{{-- Confirmación de borrado de abono (acción destructiva) --}}
<flux:modal name="delete-payment" class="w-full max-w-md">
    <form wire:submit="confirmDeletePayment" class="flex flex-col gap-5">
        <div>
            <flux:heading size="xl" class="font-display">Eliminar abono</flux:heading>
            <flux:subheading>
                ¿Seguro que quieres eliminar el abono de
                <span class="font-semibold text-zinc-900 dark:text-white">${{ number_format((float) ($this->deletingPayment?->amount ?? 0), 2) }}</span>?
                El saldo pendiente volverá a subir.
            </flux:subheading>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button type="button" variant="ghost">Cancelar</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="danger">Eliminar abono</flux:button>
        </div>
    </form>
</flux:modal>
</div>
