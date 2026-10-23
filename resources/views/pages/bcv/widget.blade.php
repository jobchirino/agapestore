<?php

use App\Services\BcvRateService;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Widget discreto del sidebar con la tasa BCV del dólar.
 *
 * Igual que el resto de la app: mount y render son de solo lectura (caché o respaldo).
 * `wire:init` refresca en segundo plano si no hay tasa o si está vencida.
 */
new class extends Component {
    /** @var array<string, mixed>|null */
    public ?array $rates = null;

    public bool $failed = false;

    public function mount(): void
    {
        $service = app(BcvRateService::class);

        $this->rates = $service->current();
        $this->failed = $service->failedRecently();
    }

    public function refreshIfNeeded(): void
    {
        $service = app(BcvRateService::class);

        if ($service->current() === null || $service->isStale()) {
            $this->refresh(silent: true);
        }
    }

    public function refresh(bool $silent = false): void
    {
        $service = app(BcvRateService::class);
        $rates = $service->refreshRates();

        $this->failed = $rates === null;
        $this->rates = $rates ?? $service->current();

        if ($rates === null) {
            if (! $silent) {
                Flux::toast(variant: 'danger', text: BcvRateService::FAILURE_MESSAGE);
            }

            return;
        }

        $this->dispatch('bcv-rates-updated', usd: $rates['usd'], eur: $rates['eur']);

        if (! $silent) {
            Flux::toast(variant: 'success', text: 'Tasa BCV actualizada.');
        }
    }

    #[On('bcv-rates-updated')]
    public function onRatesUpdated(): void
    {
        $service = app(BcvRateService::class);

        $this->rates = $service->current();
        $this->failed = $service->failedRecently();
    }
}; ?>

<div wire:init="refreshIfNeeded" class="px-2 pb-1">
    <div class="flex items-center justify-between gap-2 in-data-flux-sidebar-collapsed-desktop:justify-center">
        <span class="text-[10px] font-bold uppercase tracking-[0.06em] text-zinc-500 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-400">
            Dólar BCV
        </span>

        <button
            type="button"
            wire:click="refresh"
            wire:loading.attr="disabled"
            wire:target="refresh"
            title="{{ $rates ? 'Dólar BCV: Bs. '.number_format((float) $rates['usd'], 2, ',', '.').' — Actualizar tasa' : __('Actualizar tasa BCV') }}"
            class="rounded-md p-1 text-zinc-400 transition hover:bg-zinc-900/5 hover:text-zinc-700 disabled:opacity-50 dark:hover:bg-white/10 dark:hover:text-white"
        >
            <flux:icon name="arrow-path" class="size-3.5" wire:loading.class="animate-spin" wire:target="refresh" />
        </button>
    </div>

    @if ($rates)
        <p class="text-sm font-bold tabular-nums text-zinc-900 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-white">
            Bs. {{ number_format((float) $rates['usd'], 2, ',', '.') }}
        </p>
        <p class="text-[10px] text-zinc-500 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-400">
            Vigente {{ \Carbon\CarbonImmutable::parse($rates['date'])->format('d/m/Y') }}
        </p>
    @else
        <p class="text-[10px] text-zinc-500 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-400">
            Sin tasa disponible
        </p>
    @endif

    @if ($failed)
        <x-bcv-unavailable compact class="mt-1 in-data-flux-sidebar-collapsed-desktop:hidden" />
    @endif
</div>
