<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SaleProofDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $sale = Sale::factory()->create();

        $this->get(route('sales.proof', $sale))->assertRedirect(route('login'));
    }

    public function test_it_downloads_the_sale_proof(): void
    {
        $user = User::factory()->create();
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'payment_proof_path' => 'payment-proofs/venta.jpg',
        ]);
        Storage::disk('local')->put('payment-proofs/venta.jpg', 'fake image');

        $this->actingAs($user)
            ->get(route('sales.proof', $sale))
            ->assertOk()
            ->assertDownload("comprobante-venta-{$sale->id}-{$sale->date->format('Y-m-d')}.jpg");
    }

    public function test_it_downloads_a_payment_proof(): void
    {
        $user = User::factory()->create();
        $sale = $this->creditSale($user);
        $payment = $this->payment($sale, $user, 'payment-proofs/abono.jpg');
        Storage::disk('local')->put('payment-proofs/abono.jpg', 'fake image');

        $this->actingAs($user)
            ->get(route('sales.payments.proof', [$sale, $payment]))
            ->assertOk()
            ->assertDownload("comprobante-abono-{$payment->id}-{$payment->date->format('Y-m-d')}.jpg");
    }

    public function test_a_payment_from_another_sale_returns_not_found(): void
    {
        $user = User::factory()->create();
        $sale = $this->creditSale($user);
        $other = $this->creditSale($user);
        $payment = $this->payment($other, $user, 'payment-proofs/otro.jpg');
        Storage::disk('local')->put('payment-proofs/otro.jpg', 'fake image');

        $this->actingAs($user)
            ->get(route('sales.payments.proof', [$sale, $payment]))
            ->assertNotFound();
    }

    public function test_a_missing_file_returns_not_found(): void
    {
        $user = User::factory()->create();
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'payment_proof_path' => 'payment-proofs/missing.jpg',
        ]);

        $this->actingAs($user)
            ->get(route('sales.proof', $sale))
            ->assertNotFound();
    }

    public function test_a_path_outside_payment_proofs_is_rejected(): void
    {
        $user = User::factory()->create();
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'payment_proof_path' => 'avatars/secret.jpg',
        ]);
        Storage::disk('local')->put('avatars/secret.jpg', 'secret');

        $this->actingAs($user)
            ->get(route('sales.proof', $sale))
            ->assertNotFound();
    }

    public function test_guests_cannot_preview_proofs(): void
    {
        $sale = Sale::factory()->create([
            'payment_proof_path' => 'payment-proofs/venta.jpg',
        ]);

        $this->get(route('sales.proof.show', $sale))->assertRedirect(route('login'));
    }

    public function test_it_previews_the_sale_proof_inline(): void
    {
        $user = User::factory()->create();
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'payment_proof_path' => 'payment-proofs/venta.jpg',
        ]);
        Storage::disk('local')->put('payment-proofs/venta.jpg', 'fake image');

        $response = $this->actingAs($user)->get(route('sales.proof.show', $sale));

        $response->assertOk()->assertHeaderContains('content-disposition', 'inline');
        $this->assertSame('fake image', $response->streamedContent());
    }

    public function test_it_previews_a_payment_proof_inline(): void
    {
        $user = User::factory()->create();
        $sale = $this->creditSale($user);
        $payment = $this->payment($sale, $user, 'payment-proofs/abono.jpg');
        Storage::disk('local')->put('payment-proofs/abono.jpg', 'fake image');

        $response = $this->actingAs($user)->get(route('sales.payments.proof.show', [$sale, $payment]));

        $response->assertOk()->assertHeaderContains('content-disposition', 'inline');
        $this->assertSame('fake image', $response->streamedContent());
    }

    public function test_the_detail_offers_download_links_for_the_sale_and_payments(): void
    {
        $user = User::factory()->create();
        $sale = $this->creditSale($user);
        $sale->update(['payment_proof_path' => 'payment-proofs/venta.jpg']);
        $payment = $this->payment($sale, $user, 'payment-proofs/abono.jpg');
        Storage::disk('local')->put('payment-proofs/venta.jpg', 'fake');
        Storage::disk('local')->put('payment-proofs/abono.jpg', 'fake');

        $this->actingAs($user);

        Livewire::test('pages::sales.detail', ['saleId' => $sale->id])
            ->assertSee(route('sales.proof', $sale), false)
            ->assertSee(route('sales.payments.proof', [$sale, $payment]), false)
            ->assertSee(route('sales.proof.show', $sale), false)
            ->assertSee(route('sales.payments.proof.show', [$sale, $payment]), false);
    }

    private function creditSale(User $user): Sale
    {
        return Sale::factory()->create([
            'user_id' => $user->id,
            'payment_type' => 'credito',
            'total' => '100.00',
            'initial_payment' => '20.00',
        ]);
    }

    private function payment(Sale $sale, User $user, string $path): SalePayment
    {
        return SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => '10.00',
            'date' => now(),
            'payment_method' => 'efectivo',
            'payment_proof_path' => $path,
            'user_id' => $user->id,
        ]);
    }
}
