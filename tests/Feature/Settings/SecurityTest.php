<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_shows_password_and_passkey_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Cambiar contraseña')
            ->assertSee('Llaves de acceso')
            ->assertSee('Gestionar llaves de acceso')
            ->assertDontSee('Two-factor')
            ->assertDontSee('2FA');
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_correct_current_password_is_required(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);
    }
}
