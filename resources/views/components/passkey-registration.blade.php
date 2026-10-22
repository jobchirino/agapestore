@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        isSecure: true,
        hostMismatch: false,
        showForm: false,
        name: '',
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
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            if (/iPhone|iPad|iPod/.test(ua)) return 'iPhone / iPad';
            if (/Android/.test(ua)) return 'Android';
            if (/Macintosh|Mac OS X/.test(ua)) return 'Mac (Touch ID)';
            if (/Windows/.test(ua)) return 'Windows';
            if (/Linux/.test(ua)) return 'Linux';

            return 'Este dispositivo';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (! this.name.trim()) return;

            this.loading = true;
            this.error = null;
            this.cancelled = false;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                this.cancelled = e?.constructor?.name === 'UserCancelledError' || e?.name === 'NotAllowedError';
                this.error = this.cancelled
                    ? 'Registro con huella cancelado.'
                    : 'No se pudo vincular la llave. Inténtalo de nuevo.';
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = this.getDefaultPasskeyName();
            this.error = null;
            this.cancelled = false;
        },
    }"
>
    {{-- Avisos de contexto antes de intentar el registro. --}}
    <template x-if="hostMismatch">
        <div class="rounded-lg bg-warn-bg p-3 text-sm text-warn-text dark:bg-amber-500/15 dark:text-amber-300">
            Estás en <strong>127.0.0.1</strong>. Abre
            <a href="{{ rtrim(config('app.url'), '/').'/login' }}" class="font-semibold underline">
                {{ rtrim(config('app.url'), '/').'/login' }}
            </a>
            para vincular llaves de acceso.
        </div>
    </template>

    <template x-if="! hostMismatch && ! isSecure">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Las llaves de acceso requieren HTTPS o localhost.</p>
    </template>

    <template x-if="! hostMismatch && isSecure && ! supported">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Este navegador no admite llaves de acceso. Usa Chrome, Safari o Edge actualizados.
        </p>
    </template>

    <template x-if="supported && ! hostMismatch && isSecure && ! showForm">
        <div>
            <flux:button variant="primary" icon="plus" x-on:click="showForm = true">
                {{ __('Añadir passkey') }}
            </flux:button>
        </div>
    </template>

    <template x-if="supported && ! hostMismatch && isSecure && showForm">
        <div class="space-y-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <flux:input
                label="{{ __('Nombre de la passkey') }}"
                x-model="name"
                placeholder="{{ __('Ej. Mac (Touch ID), iPhone / iPad') }}"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
            />
            <flux:text class="!mt-1">{{ __('Sugerido según tu dispositivo. Puedes cambiarlo antes de vincular.') }}</flux:text>

            <p x-show="error" x-text="error" x-cloak
               class="text-sm"
               :class="cancelled ? 'text-zinc-500 dark:text-zinc-400' : 'text-red-600 dark:text-red-400'"></p>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    x-on:click="register()"
                    x-bind:disabled="loading || ! name.trim()"
                >
                    <span x-show="! loading">{{ __('Vincular este dispositivo') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registrando...') }}</span>
                </flux:button>
                <flux:button variant="ghost" x-on:click="cancel()">
                    {{ __('Cancelar') }}
                </flux:button>
            </div>
        </div>
    </template>
</div>
