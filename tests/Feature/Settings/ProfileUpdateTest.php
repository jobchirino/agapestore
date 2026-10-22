<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_only_the_name_editor(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Perfil')
            ->assertDontSee('Eliminar cuenta')
            ->assertDontSee('delete-user');
    }

    public function test_name_can_be_updated_without_touching_the_email(): void
    {
        $user = User::factory()->create(['name' => 'Nombre Viejo', 'email' => 'fijo@agape.com']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Nombre Nuevo')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('Nombre Nuevo', $user->name);
        $this->assertSame('fijo@agape.com', $user->email);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.profile')
            ->set('name', '')
            ->call('updateProfileInformation')
            ->assertHasErrors(['name']);
    }
}
