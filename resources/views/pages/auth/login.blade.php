<x-layouts::auth :title="__('Iniciar sesión')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Inicia sesión en tu cuenta')" :description="__('Introduce tu correo y contraseña para continuar.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- @chisel-passkeys --}}
        <x-passkey-verify />
        {{-- @end-chisel-passkeys --}}

        {{-- Login clásico (Fortify): no es Livewire, así que el estado de carga usa Alpine. --}}
        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="flex flex-col gap-6"
            x-data="{ sending: false }"
            x-on:submit="sending = true"
        >
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Correo electrónico')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Contraseña')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Contraseña')"
                    viewable
                />

            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Recordarme')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button
                    variant="primary"
                    type="submit"
                    class="w-full"
                    data-test="login-button"
                    x-bind:disabled="sending"
                    x-bind:class="sending && 'opacity-75'"
                >
                    <span x-show="! sending">{{ __('Iniciar sesión') }}</span>
                    <span x-show="sending" x-cloak class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading class="size-4" />
                        {{ __('Entrando...') }}
                    </span>
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
