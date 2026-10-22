<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans text-zinc-900 antialiased dark:bg-ink dark:text-zinc-100">
        {{-- collapsible (sin valor) habilita el plegado en escritorio además del móvil.
             Flux guarda el estado en localStorage, así que sobrevive a recargas y a wire:navigate.
             El ancho (w-64 <-> w-14) lo maneja Flux; aquí solo agregamos la transición. --}}
        {{-- El "!" es necesario: Flux agrega su propio `transition-transform` (posición posterior en el
             CSS) que anularía la propiedad width y el plegado saltaría sin animarse. --}}
        <flux:sidebar sticky collapsible class="glass-pane transition-[width,transform]! duration-300 ease-out">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('inventory') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
                <flux:sidebar.collapse class="hidden lg:flex" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Boutique')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Inicio') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="archive-box" :href="route('inventory')" :current="request()->routeIs('inventory')" wire:navigate>
                        {{ __('Inventario') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="shopping-cart" :href="route('pos')" :current="request()->routeIs('pos')" wire:navigate>
                        {{ __('Registrar Compra') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="clock" :href="route('sales.history')" :current="request()->routeIs('sales.history')" wire:navigate>
                        {{ __('Historial de Ventas') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="users" :href="route('clients')" :current="request()->routeIs('clients')" wire:navigate>
                        {{ __('Clientes') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Modo visual. Plegado, el grupo de tres botones no cabe en la barra, así que se
                 muestra un solo botón que rota Claro -> Oscuro -> Sistema. --}}
            <flux:sidebar.nav x-data="{
                cycle() {
                    const order = ['light', 'dark', 'system']
                    const next = (order.indexOf($flux.appearance) + 1) % order.length
                    $flux.appearance = order[next]
                },
            }">
                <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-[0.06em] text-zinc-500 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-400">
                    {{ __('Modo visual') }}
                </div>

                <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="w-full px-2 in-data-flux-sidebar-collapsed-desktop:hidden">
                    <flux:radio value="light" icon="sun" />
                    <flux:radio value="dark" icon="moon" />
                    <flux:radio value="system" icon="computer-desktop" />
                </flux:radio.group>

                <button
                    type="button"
                    x-on:click="cycle()"
                    title="{{ __('Cambiar modo visual') }}"
                    aria-label="{{ __('Cambiar modo visual') }}"
                    class="hidden h-10 w-10 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-800/5 hover:text-zinc-800 in-data-flux-sidebar-collapsed-desktop:flex dark:text-zinc-400 dark:hover:bg-white/15 dark:hover:text-white"
                >
                    <flux:icon name="sun" class="size-5" x-show="$flux.appearance === 'light'" />
                    <flux:icon name="moon" class="size-5" x-show="$flux.appearance === 'dark'" />
                    <flux:icon name="computer-desktop" class="size-5" x-show="$flux.appearance === 'system'" />
                </button>
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
