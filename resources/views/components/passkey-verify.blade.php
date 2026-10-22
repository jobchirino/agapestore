@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Entrar con Huella / Llave de acceso'),
    'loadingLabel' => __('Autenticando...'),
    'separator' => __('O continúa con tu correo'),
])

@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        isSecure: true,
        hostMismatch: false,
        loading: false,
        error: null,
        cancelled: false,
        updateSupport() {
            this.isSecure = window.isSecureContext;
            this.hostMismatch = window.location.hostname === '127.0.0.1';

            try {
                this.supported = Boolean(window.Passkeys?.isSupported());
            } catch (e) {
                this.supported = false;
            }
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            this.cancelled = false;

            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/dashboard');
            } catch (e) {
                this.cancelled = e?.constructor?.name === 'UserCancelledError' || e?.name === 'NotAllowedError';
                this.error = this.cancelled
                    ? 'Inicio con huella cancelado.'
                    : 'No se pudo completar con la llave de acceso. Inténtalo de nuevo.';
            } finally {
                this.loading = false;
            }
        },
    }"
>
    {{-- Avisos de contexto: host equivocado, sin HTTPS o navegador sin soporte. --}}
    <template x-if="hostMismatch">
        <div class="rounded-xl border border-amber-200 bg-warn-bg p-3 text-sm text-warn-text dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300">
            Estás entrando por <strong>127.0.0.1</strong>. Abre
            <a href="{{ rtrim(config('app.url'), '/').'/login' }}" class="font-semibold underline">
                {{ rtrim(config('app.url'), '/').'/login' }}
            </a>
            para usar la huella: la llave está atada al dominio configurado.
        </div>
    </template>

    <template x-if="! hostMismatch && ! isSecure">
        <div class="rounded-xl border border-amber-200 bg-warn-bg p-3 text-sm text-warn-text dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300">
            Las llaves de acceso requieren HTTPS o <strong>localhost</strong>.
        </div>
    </template>

    <template x-if="! hostMismatch && isSecure && ! supported">
        <div class="rounded-xl border border-gray-200/60 bg-white p-3 text-sm text-zinc-500 dark:border-white/10 dark:bg-panel dark:text-zinc-400">
            Este navegador no admite llaves de acceso. Usa Chrome, Safari o Edge actualizados.
        </div>
    </template>

    <template x-if="supported && ! hostMismatch && isSecure">
        <div>
            <div class="grid gap-2">
                <flux:button
                    variant="outline"
                    icon="finger-print"
                    class="w-full border-agape/40 text-agape hover:border-agape hover:bg-agape-soft dark:border-agape-dark/40 dark:text-agape-dark dark:hover:border-agape-dark dark:hover:bg-agape-dark/10"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                >
                    <span x-show="! loading">{{ $label }}</span>
                    <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                </flux:button>
                <p x-show="error" x-text="error" x-cloak
                   class="text-center text-sm"
                   :class="cancelled ? 'text-zinc-500 dark:text-zinc-400' : 'text-red-600 dark:text-red-400'"></p>
            </div>

            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-white px-2 text-zinc-500 dark:bg-panel dark:text-zinc-400">
                        {{ $separator }}
                    </span>
                </div>
            </div>
        </div>
    </template>
</div>
