<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => null,
            'user_id' => User::factory(),
            'total' => fake()->randomFloat(2, 5, 200),
            'date' => now(),
            'payment_method' => fake()->randomElement(['pago_movil', 'zelle', 'efectivo', 'binance', 'punto']),
            'payment_type' => 'contado',
            'payment_proof_path' => null,
        ];
    }
}
