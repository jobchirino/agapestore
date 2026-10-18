<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@negocio.com'],
            ['name' => 'Administrador', 'password' => 'password', 'email_verified_at' => now()],
        );

        $categories = collect([
            'Perfumes',
            'Carteras',
            'Bisutería',
            'Skincare',
            'Otros',
        ])->mapWithKeys(fn (string $name): array => [
            $name => Category::query()->firstOrCreate(['name' => $name]),
        ]);

        // detal_price = precio de venta al público; major_price = costo unitario al mayor (margen = detal - major).
        $products = [
            ['category' => 'Perfumes', 'name' => 'Perfume Floral 50ml', 'detal_price' => 25.00, 'major_price' => 18.50, 'total_stock' => 20],
            ['category' => 'Carteras', 'name' => 'Cartera de Cuero Negra', 'detal_price' => 40.00, 'major_price' => 30.00, 'total_stock' => 12],
            ['category' => 'Bisutería', 'name' => 'Collar Dorado Minimalista', 'detal_price' => 8.00, 'major_price' => 5.00, 'total_stock' => 50],
            ['category' => 'Skincare', 'name' => 'Crema Hidratante Facial', 'detal_price' => 15.50, 'major_price' => 11.00, 'total_stock' => 30],
            ['category' => 'Otros', 'name' => 'Cepillo de Cabello', 'detal_price' => 6.00, 'major_price' => 4.00, 'total_stock' => 25],
        ];

        foreach ($products as $product) {
            Product::query()->firstOrCreate(
                ['name' => $product['name']],
                [
                    'category_id' => $categories[$product['category']]->id,
                    'detal_price' => $product['detal_price'],
                    'major_price' => $product['major_price'],
                    'total_stock' => $product['total_stock'],
                ],
            );
        }
    }
}
