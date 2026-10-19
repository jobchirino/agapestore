<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createProduct(array $data, ?UploadedFile $image = null): Product
    {
        $validated = Validator::make($data, $this->rules(), $this->messages())->validate();

        if ($image !== null) {
            $validated['img_route'] = $image->store('products', 'public');
        }

        return Product::create($validated);
    }

    /**
     * Stock is not editable here: use adjustStock() so every change is guarded.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(Product $product, array $data, ?UploadedFile $image = null): Product
    {
        $validated = Validator::make(
            Arr::except($data, ['total_stock']),
            $this->rules(sometimes: true),
            $this->messages(),
        )->validate();

        $oldImage = $product->img_route;

        if ($image !== null) {
            $validated['img_route'] = $image->store('products', 'public');
        }

        $product->update($validated);

        if ($image !== null && $oldImage !== null) {
            Storage::disk('public')->delete($oldImage);
        }

        return $product;
    }

    /**
     * Soft delete: the product stays in past sales (sales_products keeps its foreign key).
     */
    public function deleteProduct(Product $product): void
    {
        $product->delete();
    }

    /**
     * Positive $quantityChange adds stock, negative removes it.
     */
    public function adjustStock(Product $product, int $quantityChange): Product
    {
        return DB::transaction(function () use ($product, $quantityChange): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $newStock = $locked->total_stock + $quantityChange;

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "No se puede dejar el stock de {$locked->name} en negativo. Disponible: {$locked->total_stock}.",
                ]);
            }

            $locked->update(['total_stock' => $newStock]);

            return $locked;
        });
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'category_id.required' => 'Selecciona una categoría.',
            'category_id.integer' => 'La categoría seleccionada no es válida.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',
            'detal_price.required' => 'El precio detal es obligatorio.',
            'detal_price.numeric' => 'El precio detal debe ser un número.',
            'detal_price.min' => 'El precio detal no puede ser negativo.',
            'major_price.required' => 'El costo mayor es obligatorio.',
            'major_price.numeric' => 'El costo mayor debe ser un número.',
            'major_price.min' => 'El costo mayor no puede ser negativo.',
            'total_stock.integer' => 'El stock debe ser un número entero.',
            'total_stock.min' => 'El stock no puede ser negativo.',
        ];
    }

    /**
     * major_price is the wholesale COST (what the owner pays the supplier), not a sale price.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        return [
            'category_id' => [...$prefix, 'required', 'integer', 'exists:categories,id'],
            'name' => [...$prefix, 'required', 'string', 'max:255'],
            'detal_price' => [...$prefix, 'required', 'numeric', 'min:0'],
            'major_price' => [...$prefix, 'required', 'numeric', 'min:0'],
            'total_stock' => [...$prefix, 'integer', 'min:0'],
        ];
    }
}
