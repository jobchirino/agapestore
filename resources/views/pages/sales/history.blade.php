<?php

use App\Models\Sale;
use App\Models\SaleProduct;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Historial de Ventas')] class extends Component {
    use WithPagination;

    // Filtros sincronizados con la URL: se pueden compartir o recargar sin perder el estado.
    #[Url(as: 'periodo')]
    public string $period = 'all';

    #[Url(as: 'desde')]
    public string $start = '';

    #[Url(as: 'hasta')]
    public string $end = '';

    #[Url(as: 'estado')]
    public string $status = 'all';

    #[Url(as: 'metodo')]
    public string $method = 'all';

    #[Url(as: 'buscar')]
    public string $search = '';

    // Venta abierta en el detalle. NO va en la URL: el enlace del POS llega como ?venta=ID
    // y se lee una sola vez en mount(). Si estuviera atado a la URL, el parámetro quedaría
    // pegado para siempre (Livewire no elimina parámetros que ya venían en la URL al cargar).
    public ?int $saleId = null;

    public function mount(): void
    {
        // El POS deja el id en la sesión (no en la URL) al confirmar una venta.
        // Se consume una sola vez y caduca a los 10 minutos: recargar no reabre el detalle.
        $pending = session()->pull('open_sale');

        if (
            is_array($pending)
            && isset($pending['id'], $pending['at'])
            && (int) $pending['at'] > now()->subMinutes(10)->timestamp
        ) {
            $this->saleId = (int) $pending['id'];
            $this->dispatch('open-sale', saleId: $this->saleId);
        }
    }

    /**
     * Cualquier cambio de filtro vuelve a la página 1.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'start', 'end', 'status', 'method', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function selectPeriod(string $period): void
    {
        $this->period = $period;
        $this->resetPage();
    }

    public function selectStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function selectMethod(string $method): void
    {
        $this->method = $method;
        $this->resetPage();
    }

    public function openSale(int $id): void
    {
        $this->saleId = $id;

        // El hijo es quien abre el modal: recibe el id por evento y consulta la venta.
        $this->dispatch('open-sale', saleId: $id);
    }

    /**
     * Vuelve todo a los valores por defecto. Se redirige a la ruta limpia (navegación
     * interna) porque Livewire no elimina de la URL los parámetros que ya estaban al cargar.
     */
    public function clearFilters(): void
    {
        $this->redirect(route('sales.history'), navigate: true);
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->period !== 'all'
            || $this->start !== ''
            || $this->end !== ''
            || $this->status !== 'all'
            || $this->method !== 'all'
            || $this->search !== '';
    }

    /**
     * El hijo avisa cuando registra o elimina un abono: al re-renderizar el padre,
     * los #[Computed] de la tabla y los KPIs se recalculan solos.
     */
    #[On('payment-registered')]
    public function onPaymentRegistered(): void
    {
        Flux::toast(variant: 'success', text: 'Saldo actualizado.');
    }

    #[On('payment-deleted')]
    public function onPaymentDeleted(): void
    {
        Flux::toast(variant: 'success', text: 'Saldo actualizado.');
    }

    /**
     * El hijo avisa al cerrar el detalle: se limpia la venta seleccionada.
     */
    #[On('sale-closed')]
    public function onSaleClosed(): void
    {
        $this->saleId = null;
    }

    /**
     * @return array<string, string>
     */
    public function periods(): array
    {
        return [
            'all' => 'Todas',
            'today' => 'Hoy',
            'week' => 'Esta semana',
            'month' => 'Este mes',
            'custom' => 'Rango',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return [
            'all' => 'Todos',
            'contado' => 'Contado',
            'credito_pending' => 'Crédito con saldo',
            'credito_paid' => 'Crédito pagado',
        ];
    }

    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        // Eager loading obligatorio: la tabla llama totalProfit() y balanceDue() por fila y
        // así no se dispara una consulta por venta (N+1).
        return $this->filteredQuery()
            ->with(['client', 'user', 'saleProducts.product'])
            ->withSum('payments', 'amount')
            ->orderByDesc('sales.date')
            ->orderByDesc('sales.id')
            ->paginate(10);
    }

    /**
     * KPIs recalculados con los filtros activos. Los totales se agregan en SQL: no se traen
     * todas las ventas a memoria.
     *
     * @return array{sales_count:int,total:string,profit:string,margin:float,credit_count:int,balance:string}
     */
    #[Computed]
    public function kpis(): array
    {
        $base = $this->filteredQuery();

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(ROUND(sales.total * 100)), 0) as total_cents')
            ->first();

        $profitRow = SaleProduct::query()
            ->whereIn('sale_id', (clone $base)->select('sales.id'))
            ->selectRaw('COALESCE(SUM((unit_price - unit_cost) * quantity * 100), 0) as profit_cents')
            ->selectRaw('COALESCE(SUM(unit_cost * quantity * 100), 0) as cost_cents')
            ->first();

        // El saldo pendiente solo cuenta ventas a crédito con deuda. La condición usa la misma
        // expresión que el filtro "Crédito con saldo", para que tabla y KPI nunca discrepen.
        $paid = Sale::paidAmountSql();

        $credit = (clone $base)
            ->filterByStatus('credito_pending')
            ->selectRaw('COUNT(*) as credit_count')
            ->selectRaw("COALESCE(SUM(ROUND((sales.total - {$paid}) * 100)), 0) as balance_cents")
            ->first();

        $profitCents = (int) $profitRow->profit_cents;
        $costCents = (int) $profitRow->cost_cents;

        return [
            'sales_count' => (int) $totals->sales_count,
            'total' => Money::fromCents((int) $totals->total_cents),
            'profit' => Money::fromCents($profitCents),
            'margin' => $costCents > 0 ? round($profitCents / $costCents * 100, 0) : 0.0,
            'credit_count' => (int) $credit->credit_count,
            'balance' => Money::fromCents((int) $credit->balance_cents),
        ];
    }

    /**
     * @return Builder<Sale>
     */
    private function filteredQuery(): Builder
    {
        return Sale::query()
            ->filterByPeriod(
                $this->period,
                $this->start !== '' ? $this->start : null,
                $this->end !== '' ? $this->end : null,
            )
            ->filterByStatus($this->status)
            ->filterByMethod($this->method)
            ->search($this->search);
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">

    {{-- Encabezado --}}
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• REGISTRO DE VENTAS</p>
            <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Historial de Ventas</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Ingresos, ganancia real y saldos por cobrar del período.</p>
        </div>

        <flux:button variant="primary" icon="shopping-cart" :href="route('pos')" wire:navigate>Registrar Compra</flux:button>
    </header>

    {{-- KPIs dinámicos --}}
    <section class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-5">
        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Total vendido</p>
            <p class="kpi-figure text-zinc-900 dark:text-white">${{ number_format((float) $this->kpis['total'], 2) }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->kpis['sales_count'] }} {{ $this->kpis['sales_count'] === 1 ? 'venta registrada' : 'ventas registradas' }}
            </p>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Ganancia neta real</p>
            <p class="kpi-figure text-profit-text dark:text-emerald-300">+${{ number_format((float) $this->kpis['profit'], 2) }}</p>
            <div>
                <span class="inline-block rounded-md bg-profit-bg px-2 py-0.5 text-xs font-bold tnum text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300">
                    {{ number_format($this->kpis['margin'], 0) }}% margen promedio
                </span>
            </div>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Saldo por cobrar</p>
            <p class="kpi-figure text-amber-600 dark:text-amber-300">${{ number_format((float) $this->kpis['balance'], 2) }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->kpis['credit_count'] }} {{ $this->kpis['credit_count'] === 1 ? 'crédito activo' : 'créditos activos' }}
            </p>
        </div>
    </section>

    {{-- Filtros --}}
    <section class="flex flex-col gap-3">
        <div class="flex max-w-full gap-1 overflow-x-auto rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
            @foreach ($this->periods() as $value => $label)
                <button
                    type="button"
                    wire:key="period-{{ $value }}"
                    wire:click="selectPeriod('{{ $value }}')"
                    class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition {{ $period === $value ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($period === 'custom')
            <div class="flex flex-col gap-2 rounded-xl border border-gray-200/60 bg-white p-3 sm:flex-row dark:border-white/10 dark:bg-panel">
                <flux:input wire:model.live="start" type="date" label="Desde" class="sm:w-48" />
                <flux:input wire:model.live="end" type="date" label="Hasta" class="sm:w-48" />
            </div>
        @endif

        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
                @foreach ($this->statuses() as $value => $label)
                    <button
                        type="button"
                        wire:key="status-{{ $value }}"
                        wire:click="selectStatus('{{ $value }}')"
                        class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition {{ $status === $value ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <flux:icon.loading wire:loading wire:target="search,selectPeriod,selectStatus,selectMethod,start,end" class="size-5 shrink-0 text-agape dark:text-agape-dark" />

                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    placeholder="Cliente, teléfono o ID (ej. 12 o #12)"
                    class="sm:w-64"
                />

                <flux:select wire:model.live="method" class="sm:w-44">
                    <flux:select.option value="all">Todos los métodos</flux:select.option>
                    @foreach (\App\Models\Sale::PAYMENT_METHODS as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->hasActiveFilters)
                    <flux:button variant="ghost" icon="arrow-path" wire:click="clearFilters">Limpiar filtros</flux:button>
                @endif
            </div>
        </div>

        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            El ID es el número de la primera columna. Puedes buscar por nombre o teléfono del cliente y combinarlo con los filtros.
        </p>
    </section>

    {{-- Tabla --}}
    <section
        class="card-raise overflow-hidden rounded-2xl border border-gray-200/60 bg-white transition-opacity duration-200 dark:border-white/10 dark:bg-panel"
        wire:loading.class="opacity-60"
        wire:target="search,selectPeriod,selectStatus,selectMethod,start,end,gotoPage,previousPage,nextPage"
    >
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="border-b border-gray-100 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-3 font-bold">Fecha / #Venta</th>
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold">Artículos</th>
                        <th class="px-5 py-3 font-bold">Método y condición</th>
                        <th class="px-5 py-3 text-right font-bold">Total venta</th>
                        <th class="px-5 py-3 text-right font-bold">Ganancia neta</th>
                        <th class="px-5 py-3 text-right font-bold">Comprobante / Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->sales as $sale)
                        @php($first = $sale->saleProducts->first())
                        @php($units = (int) $sale->saleProducts->sum('quantity'))
                        @php($balance = (float) $sale->balanceDue())
                        <tr wire:key="sale-{{ $sale->id }}" class="h-16 transition hover:bg-zinc-50 dark:hover:bg-white/5">
                            <td class="px-5 py-2">
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $sale->dateLabel() }}</p>
                                <p class="text-xs tnum text-zinc-500 dark:text-zinc-400">#{{ $sale->id }}</p>
                            </td>

                            <td class="px-5 py-2">
                                @if ($sale->client)
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $sale->client->name }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $sale->client->phone ?? 'Sin teléfono' }}</p>
                                @else
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">Cliente casual</span>
                                @endif
                            </td>

                            <td class="px-5 py-2">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="max-w-40 truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $first?->product?->name ?? 'Sin artículos' }}</span>
                                    @if ($sale->saleProducts->count() > 1)
                                        <span class="rounded-md bg-zinc-900/5 px-1.5 py-0.5 text-[10px] font-bold text-zinc-500 dark:bg-white/10 dark:text-zinc-400">+{{ $sale->saleProducts->count() - 1 }} más</span>
                                    @endif
                                </div>
                                <p class="text-xs tnum text-zinc-500 dark:text-zinc-400">{{ $units }} uds</p>
                            </td>

                            <td class="px-5 py-2">
                                <span class="inline-block rounded-md bg-agape-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.06em] text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                                    {{ $sale->paymentMethodLabel() }}
                                </span>

                                @if ($sale->payment_type === 'credito')
                                    @if ($balance > 0)
                                        <p class="mt-1 text-xs font-semibold tnum text-amber-700 dark:text-amber-300">Crédito · pendiente ${{ number_format($balance, 2) }}</p>
                                    @else
                                        <p class="mt-1 text-xs font-semibold tnum text-profit-text dark:text-emerald-300">Crédito · pagado</p>
                                    @endif
                                @else
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Contado</p>
                                @endif
                            </td>

                            <td class="px-5 py-2 text-right font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $sale->total, 2) }}</td>

                            <td class="px-5 py-2 text-right">
                                <span class="inline-block rounded-md bg-profit-bg px-2 py-1 text-xs font-bold tnum text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300">
                                    +${{ number_format((float) $sale->totalProfit(), 2) }}
                                </span>
                            </td>

                            <td class="px-5 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($sale->payment_proof_path)
                                        <span title="Tiene comprobante" class="text-zinc-400 dark:text-zinc-500">
                                            <flux:icon name="paper-clip" class="size-4" />
                                        </span>
                                    @endif

                                    <flux:button size="sm" variant="ghost" icon="eye" wire:click="openSale({{ $sale->id }})">Detalle</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No hay ventas que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex justify-center">
        {{ $this->sales->links() }}
    </div>

    {{-- Detalle de venta y abonos: componente hijo aislado. Se renderiza siempre para que
         el modal exista aunque todavía no se haya elegido una venta. --}}
    <livewire:pages::sales.detail :sale-id="$saleId" />
</div>
