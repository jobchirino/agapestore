<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivatizePaymentProofsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_public_proofs_to_the_private_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('payment-proofs/venta.jpg', 'fake image');
        Storage::disk('public')->put('images/producto.jpg', 'product image');

        $this->artisan('agape:privatize-proofs')->assertSuccessful();

        Storage::disk('local')->assertExists('payment-proofs/venta.jpg');
        Storage::disk('public')->assertMissing('payment-proofs/venta.jpg');
        Storage::disk('public')->assertExists('images/producto.jpg');
    }

    public function test_it_is_idempotent(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('payment-proofs/venta.jpg', 'fake image');

        $this->artisan('agape:privatize-proofs')->assertSuccessful();
        $this->artisan('agape:privatize-proofs')->assertSuccessful();

        Storage::disk('local')->assertExists('payment-proofs/venta.jpg');
        Storage::disk('public')->assertMissing('payment-proofs/venta.jpg');
    }
}
