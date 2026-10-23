<?php

use App\Models\Client;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Componente HIJO aislado: ficha del cliente e historial de compras.
 * Se abre por el evento open-client (nunca en mount()).
 */
new class extends Component {
    public ?int $clientId = null;

    #[On('open-client')]
    public function open(int $clientId): void
    {
        $this->clientId = $clientId;
        Flux::modal('client-detail')->show();
    }

    #[Computed]
    public function client(): ?Client
    {
        return $this->clientId === null
            ? null
            : Client::query()->withMetrics()->find($this->clientId);
    }

    /**
     * Compras del cliente con sus líneas y abonos cargados (sin N+1 al pintar la lista).
     *
     * @return Collection<int, \App\Models\Sale>
     */
    #[Computed]
    public function sales(): Collection
    {
        $client = $this->client;

        if ($client === null) {
            return collect();
        }

        return $client->sales()
            ->with(['saleProducts.product'])
            ->withSum('payments', 'amount')
            ->orderByDesc('date')
            ->get();
    }

    public function paidTotal(): string
    {
        $client = $this->client;

        if ($client === null) {
            return '0.00';
        }

        $paidCents = Money::toCents($client->totalSpent()) - Money::toCents($client->pendingBalance());

        return Money::fromCents(max(0, $paidCents));
    }

    /**
     * Reutiliza el traspaso de un solo uso del POS: deja la venta en sesión y navega.
     */
    public function openInHistory(int $saleId): void
    {
        session()->put('open_sale', ['id' => $saleId, 'at' => now()->timestamp]);

        $this->redirect(route('sales.history'), navigate: true);
    }
}; ?>

<div>
    <flux:modal name="client-detail" class="w-full max-w-3xl">
        @php($client = $this->client)
        @if ($client)
            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-3 pe-10">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• FICHA DEL CLIENTE</p>
                        <h2 class="font-display text-2xl font-medium text-zinc-900 dark:text-white">{{ $client->name }}</h2>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            <span>{{ $client->phone ?? 'Sin teléfono' }}</span>
                            <span>{{ $client->email ?? 'Sin correo' }}</span>
                            <span>{{ $client->address ?? 'Sin dirección' }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-zinc-900/5 px-4 py-3 dark:bg-white/5">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Total comprado</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $client->totalSpent(), 2) }}</p>
                        <x-bs-amount :amount="$client->totalSpent()" size="xs" />
                    </div>
                    <div class="rounded-xl bg-zinc-900/5 px-4 py-3 dark:bg-white/5">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Pagado</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap text-zinc-900 dark:text-white">${{ number_format((float) $this->paidTotal(), 2) }}</p>
                        <x-bs-amount :amount="$this->paidTotal()" size="xs" />
                    </div>
                    <div class="rounded-xl px-4 py-3 {{ (float) $client->pendingBalance() > 0 ? 'bg-warn-bg dark:bg-amber-500/15' : 'bg-profit-bg dark:bg-emerald-500/15' }}">
                        <p class="text-[11px] font-bold uppercase tracking-[0.06em] {{ (float) $client->pendingBalance() > 0 ? 'text-warn-text dark:text-amber-300' : 'text-profit-text dark:text-emerald-300' }}">Pendiente</p>
                        <p class="text-lg font-bold tnum whitespace-nowrap {{ (float) $client->pendingBalance() > 0 ? 'text-warn-text dark:text-amber-300' : 'text-profit-text dark:text-emerald-300' }}">${{ number_format((float) $client->pendingBalance(), 2) }}</p>
                        <x-bs-amount :amount="$client->pendingBalance()" size="xs" />
                    </div>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Historial de compras</h3>

                    <div class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-200/60 dark:divide-white/10 dark:border-white/10">
                        @forelse ($this->sales as $sale)
                            @php($first = $sale->saleProducts->first())
                            @php($pending = (float) $sale->balanceDue())
                            <div wire:key="client-sale-{{ $sale->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $sale->dateLabel() }} · #{{ $sale->id }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $first?->product?->name ?? 'Sin artículos' }}
                                        @if ($sale->saleProducts->count() > 1)
                                            · +{{ $sale->saleProducts->count() - 1 }} más
                                        @endif
                                        · {{ $sale->paymentMethodLabel() }}
                                        · {{ $sale->payment_type === 'credito' ? 'Crédito' : 'Contado' }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-3">
                                    <div class="text-right">
                                        <p class="font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $sale->total, 2) }}</p>
                                        <x-bs-amount :amount="$sale->total" size="xs" />
                                        @if ($pending > 0)
                                            <p class="text-xs font-semibold tnum text-amber-700 dark:text-amber-300">Pendiente ${{ number_format($pending, 2) }}</p>
                                            <x-bs-amount :amount="$pending" size="xs" />
                                        @endif
                                    </div>

                                    <flux:button size="sm" variant="outline" icon="clock" wire:click="openInHistory({{ $sale->id }})">
                                        Ver en historial / Abonar
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Este cliente todavía no tiene compras.</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">Cerrar</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
