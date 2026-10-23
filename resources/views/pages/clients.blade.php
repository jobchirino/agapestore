<?php

use App\Models\Client;
use App\Models\Sale;
use App\Services\ClientService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Clientes')] class extends Component {
    use WithPagination;

    #[Url(as: 'filtro')]
    public string $filter = 'all';

    #[Url(as: 'buscar')]
    public string $search = '';

    #[Url(as: 'orden')]
    public string $sort = 'recent';

    public ?int $deletingClientId = null;
    public ?string $deleteError = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['filter', 'search', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function selectFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function selectSort(string $sort): void
    {
        $this->sort = $sort;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        // Igual que en el historial: redirección limpia para que la URL no arrastre filtros.
        $this->redirect(route('clients'), navigate: true);
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->filter !== 'all' || $this->search !== '' || $this->sort !== 'recent';
    }

    #[Computed]
    public function clients(): LengthAwarePaginator
    {
        // withMetrics() agrega compras, total gastado, última compra y deuda en la misma
        // consulta: la tabla no dispara consultas por fila.
        return $this->filteredQuery()
            ->withMetrics()
            ->when($this->sort === 'debt', fn (Builder $q) => $q->orderByDesc('pending_cents'))
            ->when($this->sort === 'spent', fn (Builder $q) => $q->orderByDesc('total_spent'))
            ->when($this->sort === 'name', fn (Builder $q) => $q->orderBy('name'))
            ->when($this->sort === 'recent', fn (Builder $q) => $q->orderByDesc('last_purchase_at')->orderByDesc('id'))
            ->paginate(10);
    }

    /**
     * @return array{total:int,with_purchases:int,debt_count:int,debt:string,billed:string,average:string}
     */
    #[Computed]
    public function kpis(): array
    {
        $base = $this->filteredQuery();
        $paid = Sale::paidAmountSql();

        $debtQuery = $this->applyDebtFilter(clone $base);

        $debtRow = Sale::query()
            ->whereIn('client_id', (clone $debtQuery)->select('clients.id'))
            ->where('sales.payment_type', 'credito')
            ->where(DB::raw('sales.total'), '>', DB::raw($paid))
            ->selectRaw("COALESCE(SUM(ROUND((sales.total - {$paid}) * 100)), 0) as cents")
            ->first();

        $billedRow = Sale::query()
            ->whereIn('client_id', (clone $base)->select('clients.id'))
            ->selectRaw('COALESCE(SUM(ROUND(sales.total * 100)), 0) as cents')
            ->selectRaw('COUNT(DISTINCT sales.client_id) as billed_clients')
            ->first();

        $billedCents = (int) ($billedRow?->getAttribute('cents') ?? 0);
        $billedClients = (int) ($billedRow?->getAttribute('billed_clients') ?? 0);

        return [
            'total' => (clone $base)->count(),
            'with_purchases' => (clone $base)->has('sales')->count(),
            'debt_count' => (clone $debtQuery)->count(),
            'debt' => Money::fromCents((int) ($debtRow?->getAttribute('cents') ?? 0)),
            'billed' => Money::fromCents($billedCents),
            'average' => $billedClients > 0 ? Money::fromCents(intdiv($billedCents, $billedClients)) : '0.00',
        ];
    }

    public function openCreate(): void
    {
        $this->dispatch('open-client-form', clientId: null);
    }

    public function openEdit(int $id): void
    {
        $this->dispatch('open-client-form', clientId: $id);
    }

    public function openClient(int $id): void
    {
        $this->dispatch('open-client', clientId: $id);
    }

    #[On('client-saved')]
    public function onClientSaved(): void
    {
        Flux::toast(variant: 'success', text: 'Cliente guardado.');
    }

    public function askDelete(int $id): void
    {
        $this->deletingClientId = $id;
        $this->deleteError = null;

        Flux::modal('delete-client')->show();
    }

    public function confirmDelete(ClientService $clients): void
    {
        if ($this->deletingClientId === null) {
            return;
        }

        try {
            $clients->deleteClient(Client::query()->findOrFail($this->deletingClientId));
        } catch (ValidationException $e) {
            // Bloqueado porque el cliente tiene compras: se explica dentro del modal.
            $this->deleteError = collect($e->errors())->flatten()->first();
            Flux::toast(variant: 'danger', text: 'No se pudo eliminar el cliente.');

            return;
        }

        Flux::modal('delete-client')->close();
        $this->deletingClientId = null;

        Flux::toast(variant: 'success', text: 'Cliente eliminado.');
    }

    #[Computed]
    public function deletingClient(): ?Client
    {
        return $this->deletingClientId === null
            ? null
            : Client::query()->withMetrics()->find($this->deletingClientId);
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'all' => 'Todos',
            'with_debt' => 'Con saldo pendiente',
            'with_purchases' => 'Con compras',
            'without_purchases' => 'Sin compras',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sorts(): array
    {
        return [
            'recent' => 'Recientes',
            'debt' => 'Mayor deuda pendiente',
            'spent' => 'Mayor monto comprado',
            'name' => 'Nombre (A-Z)',
        ];
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function applyDebtFilter(Builder $query): Builder
    {
        $paid = Sale::paidAmountSql();

        return $query->whereExists(fn ($sub) => $sub
            ->from('sales')
            ->select(DB::raw(1))
            ->whereColumn('sales.client_id', 'clients.id')
            ->where('sales.payment_type', 'credito')
            ->where(DB::raw('sales.total'), '>', DB::raw($paid)));
    }

    /**
     * @return Builder<Client>
     */
    private function filteredQuery(): Builder
    {
        return Client::query()
            ->search($this->search)
            ->when($this->filter === 'with_debt', fn (Builder $q) => $this->applyDebtFilter($q))
            ->when($this->filter === 'with_purchases', fn (Builder $q) => $q->has('sales'))
            ->when($this->filter === 'without_purchases', fn (Builder $q) => $q->doesntHave('sales'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">

    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• CARTERA DE CLIENTES</p>
            <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Clientes</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Compras, contacto y saldos por cobrar de cada cliente.</p>
        </div>

        <flux:button variant="primary" icon="user-plus" wire:click="openCreate">Nuevo cliente</flux:button>
    </header>

    {{-- KPIs --}}
    <section class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-5">
        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Total clientes</p>
            <p class="kpi-figure text-zinc-900 dark:text-white">{{ $this->kpis['total'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->kpis['with_purchases'] }} con compras registradas</p>
        </div>

        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Clientes con saldo pendiente</p>
            <p class="kpi-figure text-amber-600 dark:text-amber-300">{{ $this->kpis['debt_count'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Deben ${{ number_format((float) $this->kpis['debt'], 2) }} en total</p>
            <x-bs-amount :amount="$this->kpis['debt']" />
        </div>

        <div class="kpi-card card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Facturado a clientes</p>
            <p class="kpi-figure text-zinc-900 dark:text-white">${{ number_format((float) $this->kpis['billed'], 2) }}</p>
            <x-bs-amount :amount="$this->kpis['billed']" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Ticket promedio ${{ number_format((float) $this->kpis['average'], 2) }} por cliente</p>
            <x-bs-amount :amount="$this->kpis['average']" />
        </div>
    </section>

    {{-- Filtros --}}
    <section class="flex flex-col gap-3">
        <div class="flex max-w-full gap-1 overflow-x-auto rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
            @foreach ($this->filters() as $value => $label)
                <button
                    type="button"
                    wire:key="filter-{{ $value }}"
                    wire:click="selectFilter('{{ $value }}')"
                    class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition {{ $filter === $value ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Nombre, teléfono, correo o dirección"
                class="xl:w-96"
            />

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <flux:icon.loading wire:loading wire:target="search,selectFilter,selectSort" class="size-5 shrink-0 text-agape dark:text-agape-dark" />

                <flux:select wire:model.live="sort" class="sm:w-56">
                    @foreach ($this->sorts() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->hasActiveFilters)
                    <flux:button variant="ghost" icon="arrow-path" wire:click="clearFilters">Limpiar filtros</flux:button>
                @endif
            </div>
        </div>
    </section>

    {{-- Tabla --}}
    <section
        class="card-raise overflow-hidden rounded-2xl border border-gray-200/60 bg-white transition-opacity duration-200 dark:border-white/10 dark:bg-panel"
        wire:loading.class="opacity-60"
        wire:target="search,selectFilter,selectSort,gotoPage,previousPage,nextPage"
    >
        <div id="clients-table" class="scroll-mt-20 overflow-x-auto lg:scroll-mt-6">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="border-b border-gray-100 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-3 font-bold">Cliente</th>
                        <th class="px-5 py-3 font-bold">Teléfono</th>
                        <th class="px-5 py-3 font-bold">Compras</th>
                        <th class="px-5 py-3 text-right font-bold">Total comprado</th>
                        <th class="px-5 py-3 font-bold">Estado / pendiente</th>
                        <th class="px-5 py-3 text-right font-bold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->clients as $client)
                        @php($lastPurchase = $client->lastPurchaseAt())
                        <tr wire:key="client-{{ $client->id }}" class="h-16 transition hover:bg-zinc-50 dark:hover:bg-white/5">
                            <td class="px-5 py-2">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-agape-soft text-sm font-bold text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                                        {{ mb_substr($client->name, 0, 1) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $client->name }}</p>
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $client->email ?: ($client->address ?: 'Sin datos de contacto') }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-2 tnum text-zinc-600 dark:text-zinc-300">{{ $client->phone ?? '—' }}</td>

                            <td class="px-5 py-2">
                                <p class="font-medium tnum text-zinc-900 dark:text-white">{{ $client->purchasesCount() }} {{ $client->purchasesCount() === 1 ? 'compra' : 'compras' }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $lastPurchase ? $lastPurchase->format('d/m/Y g:i A') : 'Sin compras' }}
                                </p>
                            </td>

                            <td class="px-5 py-2 text-right font-bold tnum text-zinc-900 dark:text-white">
                                ${{ number_format((float) $client->totalSpent(), 2) }}
                                <x-bs-amount :amount="$client->totalSpent()" />
                            </td>

                            <td class="px-5 py-2">
                                @if ($client->hasPendingBalance())
                                    <span class="inline-block rounded-md bg-warn-bg px-2 py-1 text-xs font-bold tnum text-warn-text dark:bg-amber-500/15 dark:text-amber-300">
                                        Debe ${{ number_format((float) $client->pendingBalance(), 2) }}
                                    </span>
                                    <x-bs-amount :amount="$client->pendingBalance()" size="xs" />
                                @else
                                    <span class="inline-block rounded-md bg-zinc-900/5 px-2 py-1 text-xs font-semibold text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                                        Al día
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-2">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="eye" wire:click="openClient({{ $client->id }})">Ficha</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $client->id }})" aria-label="Editar" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="askDelete({{ $client->id }})" aria-label="Eliminar" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No hay clientes que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex justify-center">
        {{ $this->clients->links(data: ['scrollTo' => '#clients-table']) }}
    </div>

    {{-- Modal: confirmar eliminación --}}
    <flux:modal name="delete-client" class="w-full max-w-md">
        <form wire:submit="confirmDelete" class="flex flex-col gap-5">
            <div class="pe-10">
                <flux:heading size="xl" class="font-display">Eliminar cliente</flux:heading>
                <flux:subheading>
                    ¿Seguro que quieres eliminar a
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->deletingClient?->name }}</span>?
                </flux:subheading>
            </div>

            @if ($deleteError)
                <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    {{ $deleteError }}
                </div>
            @else
                <div class="rounded-xl bg-warn-bg px-4 py-3 text-sm text-warn-text dark:bg-amber-500/15 dark:text-amber-300">
                    No se puede eliminar si tiene compras registradas: se perdería su historial de ventas y su deuda.
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">Eliminar cliente</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Hijos aislados: formulario y ficha. Se abren por evento, nunca en mount(). --}}
    <livewire:pages::clients.form wire:key="clients-form" />
    <livewire:pages::clients.detail wire:key="clients-detail" />
</div>
