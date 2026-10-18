<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        $validated = Validator::make($data, $this->rules())->validate();

        return Category::create($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $validated = Validator::make($data, $this->rules($category, sometimes: true))->validate();

        $category->update($validated);

        return $category;
    }

    /**
     * Products are soft-deleted, so their rows still hold the foreign key: check trashed ones too.
     */
    public function deleteCategory(Category $category): void
    {
        if ($category->products()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'category' => "La categoría {$category->name} tiene productos asociados y no se puede eliminar.",
            ]);
        }

        $category->delete();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?Category $category = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        $unique = Rule::unique('categories', 'name');

        if ($category !== null) {
            $unique->ignore($category->getKey());
        }

        return [
            'name' => [...$prefix, 'required', 'string', 'max:100', $unique],
            'description' => [...$prefix, 'nullable', 'string'],
        ];
    }
}
