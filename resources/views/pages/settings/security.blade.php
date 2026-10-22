<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Seguridad')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManagePasskeys = false;

    public function mount(): void
    {
        $this->canManagePasskeys = Features::canManagePasskeys();
    }

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ], [
                'current_password.required' => 'Escribe tu contraseña actual.',
                'current_password.current_password' => 'La contraseña actual no es correcta.',
                'password.required' => 'Escribe la nueva contraseña.',
                'password.confirmed' => 'La confirmación de la contraseña no coincide.',
                'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
                'password.letters' => 'La contraseña debe incluir al menos una letra.',
                'password.numbers' => 'La contraseña debe incluir al menos un número.',
                'password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
                'password.uncompromised' => 'Esa contraseña apareció en una filtración; elige otra.',
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: 'Contraseña actualizada.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Seguridad') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Cambiar contraseña')" :subheading="__('Usa una contraseña larga y difícil de adivinar')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Contraseña actual')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('Nueva contraseña')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirmar contraseña')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Guardar') }}
                </flux:button>
            </div>
        </form>

        @if ($canManagePasskeys)
            <section class="mt-12">
                <flux:heading>{{ __('Llaves de acceso') }}</flux:heading>
                <flux:subheading>{{ __('Entra con huella o Face ID desde tus dispositivos, sin contraseña') }}</flux:subheading>

                <flux:button class="mt-4" variant="outline" icon="finger-print" wire:click="$dispatch('open-passkeys')">
                    {{ __('Gestionar llaves de acceso') }}
                </flux:button>
            </section>
        @endif
    </x-pages::settings.layout>

    {{-- Gestor de llaves: se abre por evento desde esta pantalla. --}}
    <livewire:pages::passkeys.manager />
</section>
