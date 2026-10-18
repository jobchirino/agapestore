<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans text-zinc-900 antialiased dark:bg-ink dark:text-zinc-100">
        <flux:sidebar sticky collapsible="mobile" class="glass-pane w-64">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('inventory') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Boutique')" class="grid">
                    <flux:sidebar.item icon="archive-box" :href="route('inventory')" :current="request()->routeIs('inventory')" wire:navigate>
                        {{ __('Inventario') }}
                    </flux:sidebar.item>

                    @foreach ([
                        ['icon' => 'shopping-cart', 'label' => __('Registrar Compra')],
                        ['icon' => 'clock', 'label' => __('Historial de Ventas')],
                        ['icon' => 'users', 'label' => __('Clientes')],
                    ] as $soon)
                        <div
                            class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-400 dark:text-zinc-500"
                            title="{{ __('Disponible en una fase próxima') }}"
                            aria-disabled="true"
                        >
                            <flux:icon :name="$soon['icon']" class="size-5" />
                            <span class="flex-1">{{ $soon['label'] }}</span>
                            <span class="rounded-md bg-zinc-900/5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                                {{ __('Pronto') }}
                            </span>
                        </div>
                    @endforeach
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 dark:text-zinc-400">
                    {{ __('Modo visual') }}
                </div>
                <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="w-full px-2">
                    <flux:radio value="light" icon="sun" />
                    <flux:radio value="dark" icon="moon" />
                    <flux:radio value="system" icon="computer-desktop" />
                </flux:radio.group>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile header -->
        <flux:header class="glass-pane lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                        />

                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Ajustes') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Cerrar sesión') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
