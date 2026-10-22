<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inicio')] class extends Component {
    /**
     * Acción rápida: deja la intención en sesión (5 minutos) y navega al inventario,
     * que la consume y abre el modal de "Nuevo Producto". Sin ensuciar la URL.
     */
    public function createProduct(): void
    {
        session()->put('open_create_product_modal', ['at' => now()->timestamp]);

        $this->redirect(route('inventory'), navigate: true);
    }

    /**
     * Abre una venta concreta en el historial reutilizando el traspaso de un solo uso.
     */
    public function openSale(int $saleId): void
    {
        session()->put('open_sale', ['id' => $saleId, 'at' => now()->timestamp]);

        $this->redirect(route('sales.history'), navigate: true);
    }

    /**
     * @return array{today_total:string,today_count:int,today_profit:string,week_total:string,week_count:int,week_profit:string,debt:string,critical:int}
     */
    #[Computed]
    public function kpis(): array
    {
        $paid = Sale::paidAmountSql();
        $weekStart = now()->subDays(6)->startOfDay();

        $today = Sale::query()->filterByPeriod('today')
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(ROUND(sales.total * 100)), 0) as total_cents')
            ->first();

        $week = Sale::query()->whereBetween('sales.date', [$weekStart, now()->endOfDay()])
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(ROUND(sales.total * 100)), 0) as total_cents')
            ->first();

        $debt = Sale::query()
            ->where('sales.payment_type', 'credito')
            ->where(DB::raw('sales.total'), '>', DB::raw($paid))
            ->selectRaw("COALESCE(SUM(ROUND((sales.total - {$paid}) * 100)), 0) as cents")
            ->first();

        return [
            'today_total' => Money::fromCents((int) $today->total_cents),
            'today_count' => (int) $today->sales_count,
            'today_profit' => $this->profitFor(Sale::query()->filterByPeriod('today')),
            'week_total' => Money::fromCents((int) $week->total_cents),
            'week_count' => (int) $week->sales_count,
            'week_profit' => $this->profitFor(
                Sale::query()->whereBetween('sales.date', [$weekStart, now()->endOfDay()]),
            ),
            'debt' => Money::fromCents((int) ($debt?->getAttribute('cents') ?? 0)),
            'critical' => Product::query()->where('total_stock', '<=', 3)->count(),
        ];
    }

    private function profitFor(Builder $base): string
    {
        $row = SaleProduct::query()
            ->whereIn('sale_id', (clone $base)->select('sales.id'))
            ->selectRaw('COALESCE(SUM((unit_price - unit_cost) * quantity * 100), 0) as profit_cents')
            ->first();

        return Money::fromCents((int) ($row?->getAttribute('profit_cents') ?? 0));
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function criticalProducts(): Collection
    {
        return Product::query()
            ->with('category')
            ->where('total_stock', '<=', 3)
            ->orderBy('total_stock')
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Sale>
     */
    #[Computed]
    public function recentSales(): Collection
    {
        return Sale::query()
            ->with(['client', 'saleProducts.product'])
            ->withSum('payments', 'amount')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">

    <header class="flex flex-col gap-1">
        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• RESUMEN DEL NEGOCIO</p>
        <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Inicio</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Lo que importa hoy: ventas, cobranza y stock por reponer.</p>
    </header>

    {{-- Acciones rápidas --}}
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <a
            href="{{ route('pos') }}"
            wire:navigate
            class="card-raise group flex items-center gap-4 rounded-2xl bg-agape p-5 text-white transition hover:-translate-y-0.5 hover:bg-agape-hover dark:bg-agape-dark dark:text-ink dark:hover:bg-agape-dark-hover"
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-white/15">
                <flux:icon name="shopping-cart" class="size-6" />
            </span>
            <span class="min-w-0">
                <span class="block font-display text-xl font-medium">Registrar Compra</span>
                <span class="block text-xs opacity-80">Ir al punto de venta</span>
            </span>
        </a>

        <button
            type="button"
            wire:click="createProduct"
            class="card-raise group flex items-center gap-4 rounded-2xl border border-gray-200/60 bg-white p-5 text-left transition hover:-translate-y-0.5 hover:border-agape/40 dark:border-white/10 dark:bg-panel"
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-agape-soft text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                <flux:icon name="plus" class="size-6" />
            </span>
            <span class="min-w-0">
                <span class="block font-display text-xl font-medium text-zinc-900 dark:text-white">Nuevo Producto</span>
                <span class="block text-xs text-zinc-500 dark:text-zinc-400">Abre el formulario de inventario</span>
            </span>
        </button>

        <a
            href="{{ route('inventory') }}"
            wire:navigate
            class="card-raise group flex items-center gap-4 rounded-2xl border border-gray-200/60 bg-white p-5 transition hover:-translate-y-0.5 hover:border-agape/40 dark:border-white/10 dark:bg-panel"
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-zinc-900/5 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                <flux:icon name="archive-box" class="size-6" />
            </span>
            <span class="min-w-0">
                <span class="block font-display text-xl font-medium text-zinc-900 dark:text-white">Ver Inventario</span>
                <span class="block text-xs text-zinc-500 dark:text-zinc-400">Stock, precios y márgenes</span>
            </span>
        </a>

        <a
            href="{{ route('sales.history') }}"
            wire:navigate
            class="card-raise group flex items-center gap-4 rounded-2xl border border-gray-200/60 bg-white p-5 transition hover:-translate-y-0.5 hover:border-agape/40 dark:border-white/10 dark:bg-panel"
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-zinc-900/5 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                <flux:icon name="clock" class="size-6" />
            </span>
            <span class="min-w-0">
                <span class="block font-display text-xl font-medium text-zinc-900 dark:text-white">Historial de Ventas</span>
                <span class="block text-xs text-zinc-500 dark:text-zinc-400">Ventas, ganancias y abonos</span>
            </span>
        </a>
    </section>

    {{-- KPIs --}}
    <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4 md:gap-5">
        <div class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Vendido hoy</p>
            <p class="kpi-figure text-zinc-900 dark:text-white">${{ number_format((float) $this->kpis['today_total'], 2) }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $this->kpis['today_count'] }} {{ $this->kpis['today_count'] === 1 ? 'operación' : 'operaciones' }}
                · ganancia +${{ number_format((float) $this->kpis['today_profit'], 2) }}
            </p>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Últimos 7 días</p>
            <p class="kpi-figure text-zinc-900 dark:text-white">${{ number_format((float) $this->kpis['week_total'], 2) }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $this->kpis['week_count'] }} {{ $this->kpis['week_count'] === 1 ? 'venta' : 'ventas' }}
                · ganancia +${{ number_format((float) $this->kpis['week_profit'], 2) }}
            </p>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Cuentas por cobrar</p>
            <p class="kpi-figure text-amber-600 dark:text-amber-300">${{ number_format((float) $this->kpis['debt'], 2) }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Pendiente en ventas a crédito activas</p>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-2 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Stock crítico</p>
            <p class="kpi-figure {{ $this->kpis['critical'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->kpis['critical'] }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Productos con 3 unidades o menos</p>
        </div>
    </section>

    {{-- Paneles --}}
    <section class="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <div class="card-raise overflow-hidden rounded-2xl border border-gray-200/60 bg-white dark:border-white/10 dark:bg-panel">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Stock crítico</h2>
                <flux:button size="sm" variant="ghost" :href="route('inventory')" wire:navigate icon="arrow-right">Ver inventario</flux:button>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($this->criticalProducts as $product)
                    <div wire:key="critical-{{ $product->id }}" class="flex items-center gap-3 px-5 py-3">
                        @if ($product->img_route)
                            <img src="{{ asset('storage/'.$product->img_route) }}" alt="{{ $product->name }}" class="size-10 rounded-lg object-cover" />
                        @else
                            <div class="flex size-10 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
                                <flux:icon name="photo" class="size-4" />
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $product->category->name }}</p>
                        </div>

                        <span class="shrink-0 rounded-md px-2 py-1 text-xs font-bold tnum {{ $product->total_stock === 0 ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-warn-bg text-warn-text dark:bg-amber-500/15 dark:text-amber-300' }}">
                            {{ $product->total_stock }} uds
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Todo el inventario está por encima de 3 unidades.</p>
                @endforelse
            </div>
        </div>

        <div class="card-raise overflow-hidden rounded-2xl border border-gray-200/60 bg-white dark:border-white/10 dark:bg-panel">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Últimas ventas</h2>
                <flux:button size="sm" variant="ghost" :href="route('sales.history')" wire:navigate icon="arrow-right">Ver historial</flux:button>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($this->recentSales as $sale)
                    <div wire:key="recent-sale-{{ $sale->id }}" class="flex items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $sale->dateLabel() }} · #{{ $sale->id }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $sale->client?->name ?? 'Cliente casual' }}</p>
                        </div>

                        <div class="text-right">
                            <p class="font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $sale->total, 2) }}</p>
                            <p class="text-xs font-semibold tnum text-profit-text dark:text-emerald-300">+${{ number_format((float) $sale->totalProfit(), 2) }}</p>
                        </div>

                        <flux:button size="sm" variant="ghost" icon="eye" wire:click="openSale({{ $sale->id }})" aria-label="Ver venta" />
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Todavía no hay ventas registradas.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
