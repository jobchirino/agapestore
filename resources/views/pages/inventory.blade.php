<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use App\Services\ProductService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

// Un componente Livewire = una clase PHP (estado y acciones) + una vista Blade (HTML).
// Las propiedades públicas son el "estado": cada cambio se sincroniza con el servidor.
new #[Title('Inventario')] class extends Component {
    use WithFileUploads, WithPagination;

    // Filtros: viven en la URL (#[Url]) para que la página recuerde la búsqueda al recargar.
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'categoria')]
    public ?int $categoryId = null;

    #[Url(as: 'orden')]
    public string $sort = 'recent';

    #[Url(as: 'criticos')]
    public bool $criticalOnly = false;

    // Formulario de producto. Los nombres coinciden con las columnas de la BD para que
    // los errores de validación del servicio se muestren solos junto a cada campo.
    public ?int $editingId = null;
    public string $name = '';
    public ?int $category_id = null;
    public string $detal_price = '';
    public string $major_price = '';
    public int $total_stock = 0;
    public ?string $currentImage = null;
    public $image = null;

    // Ajuste de stock.
    public ?int $adjustingId = null;
    public string $adjustKind = 'in';
    public int $adjustQuantity = 1;

    // Nueva categoría.
    public string $categoryName = '';
    public string $categoryDescription = '';

    // Cualquier cambio de filtro vuelve a la página 1, si no podrías quedar en una página vacía.
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedCriticalOnly(): void
    {
        $this->resetPage();
    }

    /**
     * Computed: se calcula una vez por petición y se usa como si fuera una propiedad.
     * Analogía: como un valor derivado de useMemo en React o un getter.
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->with('category')
            ->search($this->search)
            ->byCategory($this->categoryId)
            ->when($this->criticalOnly, fn ($query) => $query->where('total_stock', '<=', 3))
            ->when($this->sort === 'profit', fn ($query) => $query->orderByRaw('(detal_price - major_price) DESC'))
            ->when($this->sort === 'stock', fn ($query) => $query->orderBy('total_stock'))
            ->when($this->sort === 'recent', fn ($query) => $query->latest())
            ->paginate(10);
    }

    #[Computed]
    public function categories()
    {
        return Category::query()->withCount('products')->orderBy('name')->get();
    }

    /**
     * KPIs calculados en la base de datos, no en PHP, para no cargar todos los productos.
     *
     * @return array{total: int, sale_value: float, cost_value: float, critical: int, avg_markup: float}
     */
    #[Computed]
    public function kpis(): array
    {
        $totals = Product::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(detal_price * total_stock), 0) AS sale_value')
            ->selectRaw('COALESCE(SUM(major_price * total_stock), 0) AS cost_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN total_stock <= 3 THEN 1 ELSE 0 END), 0) AS critical')
            ->first();

        $avgMarkup = Product::query()
            ->where('major_price', '>', 0)
            // 100.0 fuerza división real: SQLite guarda "10" como INTEGER y dividir enteros truncaría el resultado.
            ->selectRaw('AVG((detal_price - major_price) * 100.0 / major_price) AS avg_markup')
            ->value('avg_markup');

        return [
            'total' => (int) $totals->total,
            'sale_value' => (float) $totals->sale_value,
            'cost_value' => (float) $totals->cost_value,
            'critical' => (int) $totals->critical,
            'avg_markup' => round((float) $avgMarkup, 2),
        ];
    }

    /**
     * Vista previa de ganancia mientras el usuario escribe en el modal.
     *
     * @return array{amount: float, percentage: float}
     */
    #[Computed]
    public function preview(): array
    {
        $detal = (float) $this->detal_price;
        $major = (float) $this->major_price;

        return [
            'amount' => round($detal - $major, 2),
            'percentage' => $major > 0 ? round(($detal - $major) / $major * 100, 0) : 0.0,
        ];
    }

    #[Computed]
    public function adjusting(): ?Product
    {
        return $this->adjustingId ? Product::query()->find($this->adjustingId) : null;
    }

    public function selectCategory(?int $id): void
    {
        $this->categoryId = $id;
    }

    public function openCreate(): void
    {
        $this->resetProductForm();
        Flux::modal('product-form')->show();
    }

    public function openEdit(int $id): void
    {
        $product = Product::query()->findOrFail($id);

        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->detal_price = (string) $product->detal_price;
        $this->major_price = (string) $product->major_price;
        $this->total_stock = $product->total_stock;
        $this->currentImage = $product->img_route;
        $this->image = null;
        $this->resetErrorBag();

        Flux::modal('product-form')->show();
    }

    /**
     * Crea o edita. Los datos pasan por ProductService (la regla de negocio vive ahí, no en el componente).
     */
    public function saveProduct(ProductService $products): void
    {
        $data = [
            'name' => $this->name,
            'category_id' => $this->category_id,
            'detal_price' => $this->detal_price,
            'major_price' => $this->major_price,
        ];

        try {
            if ($this->editingId) {
                // El stock no se edita aquí: solo cambia con el ajuste de stock.
                $products->updateProduct(Product::query()->findOrFail($this->editingId), $data, $this->image);
                $message = 'Producto actualizado.';
            } else {
                $data['total_stock'] = $this->total_stock;
                $products->createProduct($data, $this->image);
                $message = 'Producto creado.';
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        Flux::modal('product-form')->close();
        Flux::toast(variant: 'success', text: $message);
        $this->resetProductForm();
    }

    public function openAdjust(int $id): void
    {
        $this->adjustingId = $id;
        $this->adjustKind = 'in';
        $this->adjustQuantity = 1;
        $this->resetErrorBag();

        Flux::modal('adjust-stock')->show();
    }

    public function saveAdjustment(ProductService $products): void
    {
        $product = $this->adjusting;

        if ($product === null) {
            return;
        }

        $quantity = max(1, $this->adjustQuantity);
        $change = $this->adjustKind === 'out' ? -$quantity : $quantity;

        try {
            $products->adjustStock($product, $change);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        Flux::modal('adjust-stock')->close();
        Flux::toast(variant: 'success', text: 'Stock actualizado.');
    }

    public function openCategory(): void
    {
        $this->categoryName = '';
        $this->categoryDescription = '';
        $this->resetErrorBag();

        Flux::modal('category-form')->show();
    }

    public function saveCategory(CategoryService $categories): void
    {
        try {
            $categories->createCategory([
                'name' => $this->categoryName,
                'description' => $this->categoryDescription,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        Flux::modal('category-form')->close();
        Flux::toast(variant: 'success', text: 'Categoría creada.');
    }

    public function deleteProduct(int $id, ProductService $products): void
    {
        $products->deleteProduct(Product::query()->findOrFail($id));

        Flux::toast(variant: 'success', text: 'Producto eliminado.');
    }

    private function resetProductForm(): void
    {
        $this->reset(['editingId', 'name', 'category_id', 'detal_price', 'major_price', 'total_stock', 'currentImage', 'image']);
        $this->resetErrorBag();
    }

    /**
     * Estado del stock para la píldora de la tabla.
     *
     * @return array{label: string, dot: string, pill: string}
     */
    public function stockStatus(int $stock): array
    {
        return match (true) {
            $stock === 0 => ['label' => '0 uds - Agotado', 'dot' => 'bg-rose-500', 'pill' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'],
            $stock <= 3 => ['label' => "{$stock} uds - Poco stock", 'dot' => 'bg-amber-500', 'pill' => 'bg-warn-bg text-warn-text dark:bg-amber-500/15 dark:text-amber-300'],
            default => ['label' => "{$stock} uds", 'dot' => 'bg-emerald-500', 'pill' => 'bg-profit-bg text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300'],
        };
    }
}; ?>

<div class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8">

    {{-- Encabezado de la sección --}}
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">• CONTROL DE STOCK</p>
            <h1 class="font-display text-[32px] leading-10 font-medium tracking-tight text-zinc-900 dark:text-white">Inventario de Productos</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Existencias, costos y márgenes de cada producto.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="outline" icon="tag" wire:click="openCategory">Categorías</flux:button>
            <flux:button variant="outline" icon="plus" wire:click="openCreate">Nuevo Producto</flux:button>
            {{-- El módulo de compras aún no existe: el botón queda visible pero deshabilitado. --}}
            <flux:button variant="primary" icon="shopping-cart" disabled title="Disponible en una fase próxima">Registrar Compra</flux:button>
        </div>
    </header>

    {{-- KPIs --}}
    <section class="grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-5">
        <div class="card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Total productos</p>
            <p class="text-[44px] leading-none font-bold tnum text-zinc-900 dark:text-white">{{ $this->kpis['total'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">productos activos en inventario</p>
        </div>

        <div class="card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Valor del inventario</p>
            <p class="text-[44px] leading-none font-bold tnum text-zinc-900 dark:text-white">${{ number_format($this->kpis['sale_value'], 2) }}</p>
            <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                <span class="tnum">Costo base ${{ number_format($this->kpis['cost_value'], 2) }}</span>
                <span class="rounded-md bg-profit-bg px-2 py-0.5 text-xs font-bold tnum text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300">
                    +{{ number_format($this->kpis['avg_markup'], 0) }}% margen promedio
                </span>
            </div>
        </div>

        <div class="card-raise flex flex-col gap-3 rounded-2xl border border-gray-200/60 bg-white p-5 dark:border-white/10 dark:bg-panel">
            <p class="text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">Alertas de stock</p>
            <div class="flex items-center justify-between gap-3">
                <p class="text-[44px] leading-none font-bold tnum text-zinc-900 dark:text-white">{{ $this->kpis['critical'] }}</p>
                <flux:button size="sm" :variant="$criticalOnly ? 'primary' : 'outline'" wire:click="$toggle('criticalOnly')">
                    {{ $criticalOnly ? 'Ver todas' : 'Ver críticas' }}
                </flux:button>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">con 3 unidades o menos</p>
        </div>
    </section>

    {{-- Filtros: segmented control de categorías + buscador + orden --}}
    <section class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex items-center gap-2">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-full bg-zinc-900/5 p-1 dark:bg-white/10">
                <button
                    type="button"
                    wire:click="selectCategory(null)"
                    wire:loading.attr="disabled"
                    wire:target="selectCategory"
                    wire:key="cat-all"
                    class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold tnum transition disabled:opacity-50 {{ $categoryId === null ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                >
                    Todos <span class="ml-1 opacity-60">{{ $this->kpis['total'] }}</span>
                </button>

                @foreach ($this->categories as $category)
                    <button
                        type="button"
                        wire:click="selectCategory({{ $category->id }})"
                        wire:loading.attr="disabled"
                        wire:target="selectCategory"
                        wire:key="cat-{{ $category->id }}"
                        class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold tnum transition disabled:opacity-50 {{ $categoryId === $category->id ? 'bg-white text-zinc-900 shadow-sm dark:bg-panel-raise dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                    >
                        {{ $category->name }} <span class="ml-1 opacity-60">{{ $category->products_count }}</span>
                    </button>
                @endforeach
            </div>

            <flux:icon.loading wire:loading wire:target="selectCategory" class="size-5 shrink-0 text-agape dark:text-agape-dark" />
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <flux:icon.loading wire:loading wire:target="search,sort" class="size-5 shrink-0 text-agape dark:text-agape-dark" />
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Buscar por nombre..."
                class="sm:w-72"
            />
            <flux:select wire:model.live="sort" class="sm:w-48">
                <flux:select.option value="recent">Recientes</flux:select.option>
                <flux:select.option value="profit">Mayor ganancia</flux:select.option>
                <flux:select.option value="stock">Menor stock</flux:select.option>
            </flux:select>
        </div>
    </section>

    {{-- Tabla (se atenúa mientras el servidor responde, útil con baja conexión) --}}

    {{-- Tabla --}}
    <section
        wire:loading.class="opacity-60"
        wire:target="selectCategory,search,sort,criticalOnly,gotoPage,previousPage,nextPage"
        class="card-raise overflow-hidden rounded-2xl border border-gray-200/60 bg-white transition-opacity duration-200 dark:border-white/10 dark:bg-panel"
    >
        <div class="overflow-x-auto">
            <table class="w-full min-w-[960px] text-left text-sm">
                <thead class="border-b border-gray-100 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-3 font-bold">Producto</th>
                        <th class="px-5 py-3 font-bold">Categoría</th>
                        <th class="px-5 py-3 text-right font-bold">Costo mayor</th>
                        <th class="px-5 py-3 text-right font-bold">Precio detal</th>
                        <th class="px-5 py-3 text-right font-bold">Margen ganancia</th>
                        <th class="px-5 py-3 font-bold">Stock disponible</th>
                        <th class="px-5 py-3 text-right font-bold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->products as $product)
                        @php($status = $this->stockStatus($product->total_stock))
                        <tr wire:key="product-{{ $product->id }}" class="h-16 transition hover:bg-zinc-50 dark:hover:bg-white/5">
                            <td class="px-5 py-2">
                                <div class="flex items-center gap-3">
                                    @if ($product->img_route)
                                        <img src="{{ asset('storage/'.$product->img_route) }}" alt="{{ $product->name }}" class="size-11 shrink-0 rounded-lg object-cover" />
                                    @else
                                        <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
                                            <flux:icon name="photo" class="size-5" />
                                        </div>
                                    @endif
                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $product->name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-2">
                                <span class="rounded-md bg-agape-soft px-2 py-1 text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:bg-agape-dark/15 dark:text-agape-dark">
                                    {{ $product->category->name }}
                                </span>
                            </td>
                            <td class="px-5 py-2 text-right tnum text-zinc-600 dark:text-zinc-300">${{ number_format((float) $product->major_price, 2) }}</td>
                            <td class="px-5 py-2 text-right tnum font-bold text-zinc-900 dark:text-white">${{ number_format((float) $product->detal_price, 2) }}</td>
                            <td class="px-5 py-2 text-right">
                                <span class="inline-block rounded-md bg-profit-bg px-2 py-1 text-xs font-bold tnum text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300">
                                    +${{ number_format((float) $product->profitMarginAmount(), 2) }} ({{ number_format($product->profitMarginPercentage(), 0) }}%)
                                </span>
                            </td>
                            <td class="px-5 py-2">
                                <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-semibold tnum {{ $status['pill'] }}">
                                    <span class="size-1.5 rounded-full {{ $status['dot'] }}"></span>
                                    {{ $status['label'] }}
                                </span>
                            </td>
                            <td class="px-5 py-2">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="arrows-right-left" wire:click="openAdjust({{ $product->id }})">Ajustar</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $product->id }})" aria-label="Editar" />
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="deleteProduct({{ $product->id }})"
                                        wire:confirm="¿Eliminar {{ $product->name }}? Las ventas anteriores conservarán su historial."
                                        aria-label="Eliminar"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No hay productos que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="flex justify-center">
        {{ $this->products->links() }}
    </div>

    {{-- Modal: crear / editar producto --}}
    <flux:modal name="product-form" class="w-full max-w-lg">
        <form wire:submit="saveProduct" class="flex flex-col gap-5">
            <div>
                <flux:heading size="xl" class="font-display">{{ $editingId ? 'Editar producto' : 'Nuevo producto' }}</flux:heading>
                <flux:subheading>El costo mayor es lo que pagas al proveedor. El precio detal es lo que cobras.</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Nombre" placeholder="Ej. Crema hidratante 50 ml" />

            <flux:select wire:model="category_id" label="Categoría" placeholder="Selecciona una categoría">
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id" wire:key="form-cat-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live.debounce.300ms="major_price" type="number" step="0.01" min="0" label="Costo mayor" prefix="$" />
                <flux:input wire:model.live.debounce.300ms="detal_price" type="number" step="0.01" min="0" label="Precio detal" prefix="$" />
            </div>

            <div class="flex items-center justify-between rounded-xl bg-profit-bg px-4 py-3 text-profit-text dark:bg-emerald-500/15 dark:text-emerald-300">
                <span class="text-sm font-semibold">Ganancia por unidad</span>
                <span class="font-bold tnum">+${{ number_format($this->preview['amount'], 2) }} ({{ number_format($this->preview['percentage'], 0) }}%)</span>
            </div>

            @unless ($editingId)
                <flux:input wire:model="total_stock" type="number" min="0" label="Stock inicial" />
            @endunless

            <div class="flex flex-col gap-2">
                <flux:label>Imagen del producto</flux:label>
                <div class="flex items-center gap-4">
                    <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-zinc-100 dark:bg-white/5">
                        @if ($image)
                            <img src="{{ $image->temporaryUrl() }}" alt="Vista previa" class="size-full object-cover" />
                        @elseif ($currentImage)
                            <img src="{{ asset('storage/'.$currentImage) }}" alt="Imagen actual" class="size-full object-cover" />
                        @else
                            <flux:icon name="photo" class="size-6 text-zinc-400" />
                        @endif
                    </div>
                    <flux:input type="file" wire:model="image" accept="image/*" class="flex-1" />
                </div>
                <flux:error name="image" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? 'Guardar cambios' : 'Crear producto' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: ajustar stock --}}
    <flux:modal name="adjust-stock" class="w-full max-w-md">
        <form wire:submit="saveAdjustment" class="flex flex-col gap-5">
            <div>
                <flux:heading size="xl" class="font-display">Ajustar stock</flux:heading>
                <flux:subheading>
                    {{ $this->adjusting?->name }} · stock actual <span class="font-semibold tnum">{{ $this->adjusting?->total_stock }}</span> uds
                </flux:subheading>
            </div>

            <flux:radio.group wire:model.live="adjustKind" variant="segmented">
                <flux:radio value="in" icon="arrow-down-tray">Entrada</flux:radio>
                <flux:radio value="out" icon="arrow-up-tray">Salida</flux:radio>
            </flux:radio.group>

            <flux:input wire:model.live="adjustQuantity" type="number" min="1" label="Cantidad" />

            @if ($this->adjusting)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nuevo stock:
                    <span class="font-bold tnum text-zinc-900 dark:text-white">
                        {{ $this->adjustKind === 'out' ? $this->adjusting->total_stock - max(1, $adjustQuantity) : $this->adjusting->total_stock + max(1, $adjustQuantity) }}
                    </span> uds
                </p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Confirmar ajuste</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: nueva categoría --}}
    <flux:modal name="category-form" class="w-full max-w-md">
        <form wire:submit="saveCategory" class="flex flex-col gap-5">
            <div>
                <flux:heading size="xl" class="font-display">Nueva categoría</flux:heading>
                <flux:subheading>Agrupa tus productos para filtrarlos rápido.</flux:subheading>
            </div>

            <flux:input wire:model="categoryName" label="Nombre" placeholder="Ej. Accesorios" />
            <flux:textarea wire:model="categoryDescription" label="Descripción (opcional)" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Crear categoría</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
