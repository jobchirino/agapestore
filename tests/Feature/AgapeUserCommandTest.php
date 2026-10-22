<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgapeUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_with_a_hashed_password(): void
    {
        $this->artisan('agape:user', ['email' => 'duena@agape.com', 'password' => 'secreto123'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'duena@agape.com')->firstOrFail();

        $this->assertSame('Ágape Admin', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame('secreto123', $user->password);
        $this->assertTrue(Hash::check('secreto123', $user->password));
    }

    public function test_it_updates_an_existing_user_and_hashes_the_new_password(): void
    {
        User::factory()->create([
            'email' => 'duena@agape.com',
            'password' => 'vieja12345',
        ]);

        $this->artisan('agape:user', [
            'email' => 'duena@agape.com',
            'password' => 'nueva12345',
            '--name' => 'Dueña de la tienda',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'duena@agape.com')->firstOrFail();

        $this->assertSame('Dueña de la tienda', $user->name);
        $this->assertTrue(Hash::check('nueva12345', $user->password));
    }

    public function test_it_rejects_invalid_emails_and_short_passwords(): void
    {
        $this->artisan('agape:user', ['email' => 'no-es-correo', 'password' => 'secreto123'])
            ->assertFailed();

        $this->artisan('agape:user', ['email' => 'ok@agape.com', 'password' => 'corta'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
