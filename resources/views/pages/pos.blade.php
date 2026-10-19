<?php

use App\Models\Client;
use App\Models\Product;
use App\Services\SaleService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Registrar Compra')] class extends Component {
    use WithFileUploads;

    /**
     * El carrito guarda SOLO cantidades: product_id => cantidad.
     *
     * No copiamos nombre ni precio aquí. En cada render se consultan los productos del carrito
     * con una sola query (whereIn) y se arman las líneas. Así el precio y el stock que se ven
     * son siempre los de la base, y el total mostrado coincide con el que cobra SaleService.
     *
     * @var array<int, int>
     */
    public array $cart = [];

    // Buscador de productos (reactivo, con debounce).
    public string $productSearch = '';

    // Comprador.
    public string $clientSearch = '';
    public ?int $client_id = null;

    // Pago.
    public string $payment_method = 'pago_movil';
    public string $payment_type = 'contado';
    public string $date = '';
    public $payment_proof = null;

    // Error general del carrito (stock insuficiente, producto borrado, carrito vacío).
    public ?string $cartError = null;

    // Resumen de la última venta registrada (activa el panel de éxito).
    public ?array $lastSale = null;

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d\TH:i');
    }

    /**
     * Métodos de pago disponibles: valor que se guarda => etiqueta que se muestra.
     *
     * @return array<string, string>
     */
    public function paymentMethods(): array
    {
        return [
            'pago_movil' => 'Pago Móvil',
            'zelle' => 'Zelle',
            'efectivo' => 'Efectivo',
            'binance' => 'Binance',
            'punto' => 'Punto',
        ];
    }

    /**
     * Resultados del buscador. Se calcula una vez por petición (#[Computed]).
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function results(): Collection
    {
        $query = Product::query()->with('category');

        $this->productSearch === ''
            ? $query->latest()
            : $query->search($this->productSearch);

        return $query->limit(8)->get();
    }

    /**
     * Líneas del carrito, con precio y stock frescos.
     *
     * @return Collection<int, array{id:int,name:string,category:string,image:?string,stock:int,quantity:int,unit_price:string,unit_cost:string}>
     */
    #[Computed]
    public function lines(): Collection
    {
        if ($this->cart === []) {
            return collect();
        }

        $products = Product::query()
            ->with('category')
            ->whereIn('id', array_keys($this->cart))
            ->get()
            ->keyBy('id');

        return collect($this->cart)
            ->filter(fn (int $quantity, int $id): bool => $products->has($id))
            ->map(fn (int $quantity, int $id): array => [
                'id' => $id,
                'name' => $products->get($id)->name,
                'category' => $products->get($id)->category->name,
                'image' => $products->get($id)->img_route,
                'stock' => $products->get($id)->total_stock,
                'quantity' => $quantity,
                'unit_price' => (string) $products->get($id)->detal_price,
                'unit_cost' => (string) $products->get($id)->major_price,
            ])
            ->values();
    }

    /**
     * Total y ganancia estimada, calculados en centavos para no arrastrar errores de decimales.
     *
     * @return array{total:string,profit:string,percentage:float}
     */
    #[Computed]
    public function summary(): array
    {
        $totalCents = 0;
        $costCents = 0;
        $profitCents = 0;

        foreach ($this->lines as $line) {
            $unitPrice = Money::toCents($line['unit_price']);
            $unitCost = Money::toCents($line['unit_cost']);

            $totalCents += $unitPrice * $line['quantity'];
            $costCents += $unitCost * $line['quantity'];
            $profitCents += ($unitPrice - $unitCost) * $line['quantity'];
        }

        return [
            'total' => Money::fromCents($totalCents),
            'profit' => Money::fromCents($profitCents),
            'percentage' => $costCents > 0 ? round($profitCents / $costCents * 100, 0) : 0.0,
        ];
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clientResults(): Collection
    {
        if ($this->clientSearch === '') {
            return collect();
        }

        return Client::query()->search($this->clientSearch)->orderBy('name')->limit(6)->get();
    }

    #[Computed]
    public function selectedClient(): ?Client
    {
        return $this->client_id ? Client::query()->find($this->client_id) : null;
    }

    // ---------------------------------------------------------------- Carrito

    public function addProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);

        if ($product->total_stock < 1) {
            Flux::toast(variant: 'danger', text: "{$product->name} está agotado.");

            return;
        }

        $current = $this->cart[$id] ?? 0;

        if ($current >= $product->total_stock) {
            Flux::toast(
                variant: 'danger',
                text: "No hay más stock de {$product->name}. Disponible: {$product->total_stock}.",
            );

            return;
        }

        $this->cart[$id] = $current + 1;
        $this->cartError = null;
    }

    public function decrement(int $id): void
    {
        // El botón ya se deshabilita en 1; esto es una segunda red de seguridad.
        if (! isset($this->cart[$id]) || $this->cart[$id] <= 1) {
            return;
        }

        $this->cart[$id]--;
    }

    public function removeLine(int $id): void
    {
        unset($this->cart[$id]);
    }

    // ---------------------------------------------------------------- Comprador

    public function selectClient(int $id): void
    {
        $this->client_id = $id;
        $this->clientSearch = '';
    }

    public function clearClient(): void
    {
        $this->client_id = null;
    }

    public function openClientForm(): void
    {
        Flux::modal('client-form')->show();
    }

    /**
     * Escucha el evento que emite el componente hijo de alta rápida de cliente.
     * El carrito no se toca: solo se selecciona el cliente recién creado.
     */
    #[On('client-created')]
    public function onClientCreated(int $clientId): void
    {
        $this->client_id = $clientId;
        $this->clientSearch = '';

        Flux::toast(variant: 'success', text: 'Cliente creado y seleccionado.');
    }

    // ---------------------------------------------------------------- Pago

    public function selectPaymentMethod(string $method): void
    {
        $this->payment_method = $method;
    }

    public function selectPaymentType(string $type): void
    {
        $this->payment_type = $type;
    }

    public function removeProof(): void
    {
        $this->payment_proof = null;
        $this->resetErrorBag('payment_proof');
    }

    /**
     * URL de vista previa solo si el archivo es una imagen (temporaryUrl falla con otros tipos).
     */
    public function proofPreviewUrl(): ?string
    {
        if ($this->payment_proof === null) {
            return null;
        }

        return str_starts_with((string) $this->payment_proof->getMimeType(), 'image/')
            ? $this->payment_proof->temporaryUrl()
            : null;
    }

    // ---------------------------------------------------------------- Confirmar

    public function confirmSale(SaleService $sales): void
    {
        $this->cartError = null;

        if ($this->cart === []) {
            $this->cartError = 'Agrega al menos un producto al carrito.';
            Flux::toast(variant: 'danger', text: $this->cartError);

            return;
        }

        $this->validate($this->rules(), $this->messages());

        $items = collect($this->cart)
            ->map(fn (int $quantity, int $id): array => ['product_id' => $id, 'quantity' => $quantity])
            ->values()
            ->all();

        try {
            $sale = $sales->createSale([
                'client_id' => $this->client_id,
                'user_id' => auth()->id(),
                'date' => $this->date,
                'payment_method' => $this->payment_method,
                'payment_type' => $this->payment_type,
            ], $items, $this->payment_proof);
        } catch (ValidationException $e) {
            // El servicio ya explica en español qué producto no tiene stock. El carrito NO se toca.
            $this->cartError = collect($e->errors())->flatten()->first() ?? 'No se pudo registrar la venta.';
            Flux::toast(variant: 'danger', text: 'No se pudo registrar la venta.');

            return;
        } catch (ModelNotFoundException) {
            $this->cartError = 'Uno de los productos del carrito ya no existe. Quítalo y vuelve a intentar.';
            Flux::toast(variant: 'danger', text: 'Producto no encontrado.');

            return;
        }

        $this->lastSale = [
            'id' => $sale->id,
            'total' => $sale->total,
            'profit' => $sale->totalProfit(),
        ];

        $this->reset(['cart', 'payment_proof', 'client_id', 'clientSearch', 'cartError']);
        $this->payment_method = 'pago_movil';
        $this->payment_type = 'contado';
        $this->date = now()->format('Y-m-d\TH:i');

        Flux::toast(variant: 'success', text: 'Venta registrada.');
    }

    public function startNewSale(): void
    {
        $this->lastSale = null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(array_keys($this->paymentMethods()))],
            'payment_type' => ['required', Rule::in(['contado', 'credito'])],
            'date' => [
                'required',
                'date',
                // Se compara por día completo: así una venta de hoy a cualquier hora pasa,
                // y hay margen suficiente mientras el usuario llena el formulario.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Carbon::parse((string) $value)->startOfDay()->gt(now()->startOfDay())) {
                        $fail('La fecha no puede ser futura.');
                    }
                },
            ],
            'payment_proof' => ['nullable', 'image', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago no es válido.',
            'payment_type.required' => 'Selecciona el tipo de pago.',
            'payment_type.in' => 'El tipo de pago no es válido.',
            'date.required' => 'Indica la fecha de la venta.',
            'date.date' => 'La fecha no es válida.',
            'payment_proof.image' => 'El comprobante debe ser una imagen.',
            'payment_proof.max' => 'El comprobante no puede pesar más de 5 MB.',
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">

    {{-- Encabezado --}}
    <header class="flex flex-col gap-1">
        <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• PUNTO DE VENTA</p>
        <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Registrar Compra</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Arma el carrito, registra el pago y descuenta el stock.</p>
    </header>

    {{-- Panel de éxito: se muestra tras registrar una venta --}}
    @if ($lastSale)
        <section class="rounded-2xl border border-emerald-200 bg-profit-bg p-6 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-profit-text dark:text-emerald-300">• VENTA REGISTRADA</p>
            <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex gap-8">
                    <div>
                        <p class="text-xs text-profit-text/80 dark:text-emerald-300/80">Total cobrado</p>
                        <p class="text-3xl font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $lastSale['total'], 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-profit-text/80 dark:text-emerald-300/80">Ganancia real</p>
                        <p class="text-3xl font-bold tnum text-profit-text dark:text-emerald-300">+${{ number_format((float) $lastSale['profit'], 2) }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <flux:button variant="outline" wire:click="startNewSale" icon="plus">Registrar otra venta</flux:button>
                    <flux:button variant="primary" :href="route('inventory')" wire:navigate icon="archive-box">Ir al inventario</flux:button>
                </div>
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">

        {{-- ============================ Columna izquierda: productos y carrito --}}
        <div class="flex flex-col gap-5">

            {{-- Buscador de productos --}}
            <section class="card-raise rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
                <flux:input
                    wire:model.live.debounce.300ms="productSearch"
                    icon="magnifying-glass"
                    label="Buscar producto"
                    placeholder="Nombre del producto..."
                />

                <div class="mt-4 flex flex-col gap-2">
                    @forelse ($this->results as $product)
                        @php($inCart = $cart[$product->id] ?? 0)
                        @php($soldOut = $product->total_stock < 1)
                        <button
                            type="button"
                            wire:key="result-{{ $product->id }}"
                            wire:click="addProduct({{ $product->id }})"
                            @disabled($soldOut)
                            class="flex items-center gap-3 rounded-xl border border-transparent px-3 py-2 text-left transition hover:border-agape/30 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:hover:bg-white/5"
                        >
                            @if ($product->img_route)
                                <img src="{{ asset('storage/'.$product->img_route) }}" alt="{{ $product->name }}" class="size-10 shrink-0 rounded-lg object-cover" />
                            @else
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
                                    <flux:icon name="photo" class="size-4" />
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $product->category->name }}</p>
                            </div>

                            <span class="shrink-0 text-sm font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $product->detal_price, 2) }}</span>

                            @if ($soldOut)
                                <span class="shrink-0 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">Agotado</span>
                            @else
                                <span class="shrink-0 rounded-full bg-zinc-900/5 px-2.5 py-1 text-xs font-semibold tnum text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $product->total_stock }} uds</span>
                            @endif

                            @if ($inCart > 0)
                                <span class="shrink-0 rounded-full bg-agape px-2.5 py-1 text-xs font-bold tnum text-white dark:bg-agape-dark dark:text-ink">{{ $inCart }} en carrito</span>
                            @endif
                        </button>
                    @empty
                        <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No hay productos que coincidan.</p>
                    @endforelse
                </div>
            </section>

            {{-- Artículos cargados --}}
            <section class="card-raise rounded-2xl border border-gray-200/60 bg-white dark:border-white/10 dark:bg-panel">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                    <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Artículos cargados</h2>
                </div>

                @if ($cartError)
                    <div class="mx-5 mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ $cartError }}
                    </div>
                @endif

                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->lines as $line)
                        <div wire:key="line-{{ $line['id'] }}" class="flex items-center gap-4 px-5 py-4">
                            @if ($line['image'])
                                <img src="{{ asset('storage/'.$line['image']) }}" alt="{{ $line['name'] }}" class="size-12 shrink-0 rounded-lg object-cover" />
                            @else
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
                                    <flux:icon name="photo" class="size-5" />
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $line['name'] }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    ${{ number_format((float) $line['unit_price'], 2) }} c/u · stock {{ $line['stock'] }}
                                </p>
                            </div>

                            <div class="flex items-center gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="minus"
                                    wire:click="decrement({{ $line['id'] }})"
                                    :disabled="$line['quantity'] <= 1"
                                    aria-label="Quitar una unidad"
                                />
                                <span class="w-8 text-center font-bold tnum text-zinc-900 dark:text-white">{{ $line['quantity'] }}</span>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="plus"
                                    wire:click="addProduct({{ $line['id'] }})"
                                    :disabled="$line['quantity'] >= $line['stock']"
                                    aria-label="Agregar una unidad"
                                />
                            </div>

                            <span class="w-24 shrink-0 text-right font-bold tnum text-zinc-900 dark:text-white">
                                ${{ number_format((float) $line['unit_price'] * $line['quantity'], 2) }}
                            </span>

                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeLine({{ $line['id'] }})" aria-label="Quitar del carrito" />
                        </div>
                    @empty
                        <p class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            El carrito está vacío. Busca un producto arriba para agregarlo.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>

        {{-- ============================ Columna derecha: comprador, pago y resumen --}}
        <div class="flex flex-col gap-5">

            {{-- Comprador --}}
            <section class="card-raise rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Comprador</h2>

                @if ($this->selectedClient)
                    <div class="mt-3 flex items-center gap-3 rounded-xl bg-zinc-900/5 px-3 py-2 dark:bg-white/5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->selectedClient->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $this->selectedClient->phone ?? 'Sin teléfono' }}</p>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearClient">Quitar</flux:button>
                    </div>
                @else
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Cliente casual (sin registro).</p>

                    <div class="mt-3">
                        <flux:input
                            wire:model.live.debounce.300ms="clientSearch"
                            icon="magnifying-glass"
                            placeholder="Buscar por nombre o teléfono..."
                        />
                    </div>

                    @if ($clientSearch !== '')
                        <div class="mt-2 flex flex-col gap-1">
                            @forelse ($this->clientResults as $client)
                                <button
                                    type="button"
                                    wire:key="client-{{ $client->id }}"
                                    wire:click="selectClient({{ $client->id }})"
                                    class="flex items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition hover:bg-zinc-50 dark:hover:bg-white/5"
                                >
                                    <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $client->name }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $client->phone }}</span>
                                </button>
                            @empty
                                <p class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">Sin resultados.</p>
                            @endforelse
                        </div>
                    @endif
                @endif

                <flux:button class="mt-3" size="sm" variant="outline" icon="user-plus" wire:click="openClientForm">
                    Nuevo cliente
                </flux:button>
            </section>

            {{-- Datos del pago --}}
            <section class="card-raise flex flex-col gap-4 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Datos del pago</h2>

                <div>
                    <flux:label class="mb-2">Método de pago</flux:label>
                    <div class="flex flex-wrap gap-1 rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
                        @foreach ($this->paymentMethods() as $value => $label)
                            <button
                                type="button"
                                wire:key="pm-{{ $value }}"
                                wire:click="selectPaymentMethod('{{ $value }}')"
                                class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $payment_method === $value ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <flux:label class="mb-2">Tipo de pago</flux:label>
                    <div class="flex gap-1 rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
                        <button
                            type="button"
                            wire:click="selectPaymentType('contado')"
                            class="flex-1 rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $payment_type === 'contado' ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                        >
                            Contado
                        </button>
                        <button
                            type="button"
                            wire:click="selectPaymentType('credito')"
                            class="flex-1 rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $payment_type === 'credito' ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                        >
                            Crédito
                        </button>
                    </div>
                </div>

                {{-- .live para mantener la misma cadencia que los buscadores y evitar el bug de
                     campos que pierden lo escrito por un re-render. --}}
                <flux:input wire:model.live.debounce.500ms="date" type="datetime-local" label="Fecha de la venta" />
            </section>

            {{-- Comprobante --}}
            <section class="card-raise rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Comprobante de pago</h2>

                @if ($payment_proof)
                    <div class="mt-3 flex items-center gap-3 rounded-xl border border-gray-200/60 bg-white p-3 dark:border-white/10 dark:bg-panel-raise">
                        @if ($proofUrl = $this->proofPreviewUrl())
                            <img src="{{ $proofUrl }}" alt="Comprobante" class="size-16 shrink-0 rounded-lg object-cover" />
                        @else
                            <div class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
                                <flux:icon name="document" class="size-6" />
                            </div>
                        @endif
                        <div class="min-w-0 flex-1 text-xs text-zinc-500 dark:text-zinc-400">Vista previa lista para confirmar.</div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeProof">Quitar</flux:button>
                    </div>
                @else
                    <label class="mt-3 flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-6 text-center transition hover:border-agape dark:border-white/15 dark:bg-white/5 dark:hover:border-agape-dark">
                        <flux:icon name="arrow-up-tray" class="size-6 text-zinc-400" />
                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Sube una imagen del comprobante</span>
                        <span class="text-xs text-zinc-400">PNG o JPG, hasta 5 MB</span>
                        <input type="file" wire:model="payment_proof" accept="image/*" class="sr-only" />
                        <span wire:loading wire:target="payment_proof" class="text-xs font-semibold text-agape dark:text-agape-dark">Subiendo...</span>
                    </label>
                @endif

                <flux:error name="payment_proof" />
            </section>

            {{-- Resumen y confirmación --}}
            <section class="card-raise flex flex-col gap-4 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
                <h2 class="text-sm font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Resumen</h2>

                <div class="flex items-end justify-between">
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">Total a pagar</span>
                    <span class="text-4xl font-bold tnum text-zinc-900 dark:text-white">${{ number_format((float) $this->summary['total'], 2) }}</span>
                </div>

                <div class="flex items-center justify-between rounded-xl bg-profit-bg px-4 py-3 dark:bg-emerald-500/15">
                    <span class="text-sm font-semibold text-profit-text dark:text-emerald-300">Ganancia estimada</span>
                    <span class="font-bold tnum text-profit-text dark:text-emerald-300">
                        +${{ number_format((float) $this->summary['profit'], 2) }} ({{ number_format($this->summary['percentage'], 0) }}%)
                    </span>
                </div>

                <form wire:submit="confirmSale">
                    <flux:button
                        type="submit"
                        variant="primary"
                        class="w-full"
                        wire:loading.attr="disabled"
                        wire:target="confirmSale"
                    >
                        <span wire:loading.remove wire:target="confirmSale">Confirmar y Descontar</span>
                        <span wire:loading wire:target="confirmSale" class="inline-flex items-center gap-2">
                            <flux:icon.loading class="size-4" /> Procesando...
                        </span>
                    </flux:button>
                </form>
            </section>
        </div>
    </div>

    {{-- Modal: alta rápida de cliente. Todo el formulario vive en el componente hijo aislado. --}}
    <flux:modal name="client-form" class="w-full max-w-md">
        <livewire:pages::pos.client-form wire:key="pos-client-form" />
    </flux:modal>
</div>
