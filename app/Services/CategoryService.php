<?php

namespace App\Services;

use App\Models\Category;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        $validated = Validator::make($data, $this->rules(), $this->messages())->validate();

        return Category::create($validated);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $validated = Validator::make($data, $this->rules($category, sometimes: true), $this->messages())->validate();

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
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
        ];
    }

    /**
     * The unique check compares the normalized slug, so "Bisutería", "bisuteria" and "BISUTERIA"
     * are the same category. The display name is stored exactly as typed.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(?Category $category = null, bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        return [
            'name' => [
                ...$prefix,
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('El nombre de la categoría no es válido.');

                        return;
                    }

                    $exists = Category::query()
                        ->where('slug', $slug)
                        ->when($category !== null, fn ($query) => $query->whereKeyNot($category->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail('Ya existe una categoría con ese nombre.');
                    }
                },
            ],
            'description' => [...$prefix, 'nullable', 'string'],
        ];
    }
}
