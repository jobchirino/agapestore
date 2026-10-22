<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClosedAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_hides_registration_and_password_reset_links(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Boutique')
            ->assertDontSee('Forgot your password?')
            ->assertDontSee('Sign up');
    }

    public function test_login_page_is_fully_in_spanish(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee('Correo electrónico')
            ->assertSee('Contraseña')
            ->assertSee('Recordarme')
            ->assertDontSee('Remember me')
            ->assertDontSee('Email address')
            ->assertDontSee('Sign in with a passkey');
    }

    public function test_public_registration_and_password_reset_routes_are_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();

        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.reset'));
    }
}
