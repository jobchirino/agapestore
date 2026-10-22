<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;
use Tests\TestCase;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkeys_redirect_to_the_dashboard_without_password_confirmation(): void
    {
        $this->assertSame('/dashboard', config('passkeys.redirect'));
        $this->assertSame([], config('passkeys.management_middleware'));
    }

    public function test_login_options_are_generated_and_stored_in_the_session(): void
    {
        $this->getJson(route('passkey.login-options'))
            ->assertOk()
            ->assertJsonStructure(['options']);

        $this->assertNotNull(session('passkey.verification_options'));
    }

    public function test_invalid_passkey_login_payload_is_rejected_without_authenticating(): void
    {
        $this->postJson(route('passkey.login'), [])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_registration_options_require_authentication(): void
    {
        $this->getJson(route('passkey.registration-options'))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->assertJsonStructure(['options']);
    }

    public function test_manager_loads_passkeys_only_when_opened(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user, 'iPhone de la dueña');

        $this->actingAs($user);

        Livewire::test('pages::passkeys.manager')
            ->assertSet('passkeys', [])
            ->dispatch('open-passkeys')
            ->assertSee('iPhone de la dueña');
    }

    public function test_manager_deletes_a_passkey_with_confirmation(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'Mac de Prueba');

        $this->actingAs($user);

        Livewire::test('pages::passkeys.manager')
            ->dispatch('open-passkeys')
            ->assertSee('Mac de Prueba')
            ->assertSee('Sin uso todavía')
            ->call('askDelete', $passkey->id)
            ->assertSee('¿Seguro que quieres eliminar')
            ->call('confirmDelete')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    private function createPasskey(User $user, string $name): Passkey
    {
        // user_id no es fillable en el paquete: se crea por la relación.
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => 'credential-'.str()->random(12),
            'credential' => ['aaguid' => null],
        ]);
    }
}
