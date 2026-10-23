<?php

use App\Services\BcvRateService;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calculadora BCV')] class extends Component {
    /** @var array<string, mixed>|null */
    public ?array $rates = null;

    public bool $failed = false;

    public string $defaultCurrency = 'usd';

    public function mount(): void
    {
        $service = app(BcvRateService::class);

        $this->rates = $service->current();
        $this->failed = $service->failedRecently();
        $this->defaultCurrency = $service->defaultCurrency();
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
}; ?>

@php
    $vigencia = $rates ? \Carbon\CarbonImmutable::parse($rates['date'])->format('d/m/Y') : null;
    $actualizada = $rates
        ? \Carbon\CarbonImmutable::parse($rates['fetched_at'])->timezone('America/Caracas')->format('d/m/Y g:i A')
        : null;
@endphp

<div wire:init="refreshIfNeeded" class="mx-auto flex w-full max-w-[1000px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• TASAS OFICIALES</p>
            <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Calculadora BCV</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Convierte entre divisas y bolívares con la tasa oficial del BCV.</p>
        </div>

        <flux:button variant="outline" icon="arrow-path" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
            {{ __('Actualizar tasa BCV') }}
        </flux:button>
    </header>

    @if ($failed)
        <x-bcv-unavailable />
    @endif

    @if ($rates === null && ! $failed)
        <div class="rounded-xl border border-gray-200/60 bg-white px-4 py-3 text-sm text-zinc-500 dark:border-white/10 dark:bg-panel dark:text-zinc-400">
            Todavía no hay una tasa guardada. Pulsa "Actualizar tasa BCV" para consultarla.
        </div>
    @endif

    <div
        x-data="{
            usd: @js((float) ($rates['usd'] ?? 0)),
            eur: @js((float) ($rates['eur'] ?? 0)),
            currency: @js($defaultCurrency),
            foreign: '',
            bs: '',
            lastEdited: 'foreign',
            copied: null,
            get rate() {
                return this.currency === 'usd' ? this.usd : this.eur
            },
            get symbol() {
                return this.currency === 'usd' ? '$' : '€'
            },
            parseNumber(value) {
                let s = String(value ?? '').trim().replace(/\s/g, '')
                if (! s) return 0
                if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.')
                const n = parseFloat(s)
                return Number.isFinite(n) ? n : 0
            },
            formatBs(n) {
                return 'Bs. ' + n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            },
            formatForeign(n) {
                return this.symbol + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            },
            fromForeign() {
                this.lastEdited = 'foreign'
                const n = this.parseNumber(this.foreign)
                this.bs = n > 0 && this.rate > 0 ? (n * this.rate).toFixed(2) : ''
            },
            fromBs() {
                this.lastEdited = 'bs'
                const n = this.parseNumber(this.bs)
                this.foreign = n > 0 && this.rate > 0 ? (n / this.rate).toFixed(2) : ''
            },
            setCurrency(currency) {
                this.currency = currency
                this.lastEdited === 'bs' ? this.fromBs() : this.fromForeign()
            },
            clear() {
                this.foreign = ''
                this.bs = ''
                this.copied = null
            },
            legacyCopy(text) {
                // Fallback para HTTP (LAN): navigator.clipboard exige contexto seguro.
                const area = document.createElement('textarea')
                area.value = text
                area.setAttribute('readonly', '')
                area.style.position = 'fixed'
                area.style.top = '0'
                area.style.left = '0'
                area.style.opacity = '0'
                area.style.pointerEvents = 'none'
                document.body.appendChild(area)
                area.focus()
                area.select()
                area.setSelectionRange(0, text.length)
                const ok = document.execCommand('copy')
                document.body.removeChild(area)
                return ok
            },
            async copy(text, key) {
                if (! text) return

                let copied = false

                if (navigator.clipboard && window.isSecureContext) {
                    try {
                        await navigator.clipboard.writeText(text)
                        copied = true
                    } catch (error) {
                        copied = false
                    }
                }

                if (! copied) {
                    copied = this.legacyCopy(text)
                }

                if (! copied) return

                this.copied = key
                setTimeout(() => { this.copied = null }, 1500)
            },
            get foreignPreview() {
                const n = this.parseNumber(this.foreign)
                return n > 0 ? this.formatForeign(n) : ''
            },
            get bsPreview() {
                const n = this.parseNumber(this.bs)
                return n > 0 ? this.formatBs(n) : ''
            },
            get bsCopy() {
                // Los bancos aceptan 1234,56: sin símbolo y sin separador de miles.
                const n = this.parseNumber(this.bs)
                return n > 0 ? n.toFixed(2).replace('.', ',') : ''
            },
        }"
        x-on:bcv-rates-updated.window="usd = $event.detail.usd; eur = $event.detail.eur; lastEdited === 'bs' ? fromBs() : fromForeign()"
        class="flex flex-col gap-5"
    >
        {{-- Tasas del día --}}
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div
                class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border bg-white p-5 transition-colors dark:bg-panel"
                :class="currency === 'usd' ? 'border-agape/40 ring-1 ring-agape/20 dark:border-agape-dark/40 dark:ring-agape-dark/20' : 'border-gray-200/60 dark:border-white/10'"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Dólar BCV ($)</p>
                    @if ($rates && $rates['change_usd'] !== null)
                        <span class="rounded-md px-2 py-0.5 text-[10px] font-bold tnum {{ $rates['change_usd'] >= 0 ? 'bg-profit-bg text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' }}">
                            {{ $rates['change_usd'] >= 0 ? '+' : '' }}{{ number_format((float) $rates['change_usd'], 2, ',', '.') }}%
                        </span>
                    @endif
                </div>
                <p class="kpi-figure text-zinc-900 dark:text-white">Bs. {{ number_format((float) ($rates['usd'] ?? 0), 2, ',', '.') }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Vigente {{ $vigencia ?? '—' }}</p>
            </div>

            <div
                class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border bg-white p-5 transition-colors dark:bg-panel"
                :class="currency === 'eur' ? 'border-agape/40 ring-1 ring-agape/20 dark:border-agape-dark/40 dark:ring-agape-dark/20' : 'border-gray-200/60 dark:border-white/10'"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Euro BCV (€)</p>
                    @if ($rates && $rates['change_eur'] !== null)
                        <span class="rounded-md px-2 py-0.5 text-[10px] font-bold tnum {{ $rates['change_eur'] >= 0 ? 'bg-profit-bg text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' }}">
                            {{ $rates['change_eur'] >= 0 ? '+' : '' }}{{ number_format((float) $rates['change_eur'], 2, ',', '.') }}%
                        </span>
                    @endif
                </div>
                <p class="kpi-figure text-zinc-900 dark:text-white">Bs. {{ number_format((float) ($rates['eur'] ?? 0), 2, ',', '.') }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Vigente {{ $vigencia ?? '—' }}</p>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <span>Última actualización: {{ $actualizada ?? 'sin datos' }} (America/Caracas)</span>

            <div class="flex gap-1 rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
                <button
                    type="button"
                    x-on:click="setCurrency('usd')"
                    class="rounded-full px-4 py-1.5 text-sm font-semibold transition"
                    :class="currency === 'usd' ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                >
                    Dólar BCV ($)
                </button>
                <button
                    type="button"
                    x-on:click="setCurrency('eur')"
                    class="rounded-full px-4 py-1.5 text-sm font-semibold transition"
                    :class="currency === 'eur' ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                >
                    Euro BCV (€)
                </button>
            </div>
        </div>

        {{-- Conversión --}}
        <section class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-6 dark:border-white/10 dark:bg-panel">
                <label class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">
                    Monto en divisas (<span x-text="symbol"></span>)
                </label>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-zinc-400 dark:text-zinc-500" x-text="symbol"></span>
                    <input
                        type="text"
                        inputmode="decimal"
                        x-model="foreign"
                        x-on:input="fromForeign()"
                        :disabled="! rate"
                        placeholder="0.00"
                        class="w-full bg-transparent text-4xl font-bold tabular-nums text-zinc-900 outline-none placeholder:text-zinc-300 disabled:cursor-not-allowed dark:text-white dark:placeholder:text-zinc-600"
                    >
                </div>
                <p class="h-4 text-sm tnum text-zinc-500 dark:text-zinc-400" x-text="foreignPreview"></p>
            </div>

            <div class="card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-6 dark:border-white/10 dark:bg-panel">
                <label class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Monto en bolívares (Bs.)</label>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-zinc-400 dark:text-zinc-500">Bs.</span>
                    <input
                        type="text"
                        inputmode="decimal"
                        x-model="bs"
                        x-on:input="fromBs()"
                        :disabled="! rate"
                        placeholder="0,00"
                        class="w-full bg-transparent text-4xl font-bold tabular-nums text-zinc-900 outline-none placeholder:text-zinc-300 disabled:cursor-not-allowed dark:text-white dark:placeholder:text-zinc-600"
                    >
                </div>
                <p class="h-4 text-sm tnum text-zinc-500 dark:text-zinc-400" x-text="bsPreview"></p>
            </div>
        </section>

        <section class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="x-mark" type="button" x-on:click="clear()">Limpiar</flux:button>
            <flux:button variant="outline" icon="clipboard" type="button" x-on:click="copy(bsCopy, 'bs')" x-bind:disabled="! bsCopy">
                Copiar monto en Bs.
            </flux:button>
            <flux:button variant="outline" icon="clipboard" type="button" x-on:click="copy(foreignPreview, 'foreign')" x-bind:disabled="! foreignPreview">
                Copiar monto en divisas
            </flux:button>

            <span x-show="copied" x-cloak class="text-sm font-semibold text-profit-text dark:text-emerald-300">¡Copiado!</span>
        </section>
    </div>
</div>
