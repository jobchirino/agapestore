<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans text-zinc-900 antialiased dark:bg-ink dark:text-zinc-100">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="w-full max-w-sm">
                <a href="{{ route('home') }}" class="mb-6 flex flex-col items-center gap-2 text-center" wire:navigate>
                    {{-- Placa blanca también en oscuro: el logo tiene trazos negros. --}}
                    <span class="flex size-16 items-center justify-center overflow-hidden rounded-2xl border border-gray-200/60 bg-white dark:border-white/20 dark:ring-1 dark:ring-white/10">
                        <img
                            src="{{ asset('images/logo-agapestore.png') }}"
                            alt="{{ config('app.name', 'Ágape Store') }}"
                            class="size-full object-cover"
                        />
                    </span>
                    <span class="font-display text-2xl font-medium tracking-tight text-zinc-900 dark:text-white">
                        {{ config('app.name', 'Ágape Store') }}
                    </span>
                    <span class="text-[11px] font-bold uppercase tracking-[0.06em] text-agape dark:text-agape-dark">
                        Boutique
                    </span>
                </a>

                <div class="card-raise rounded-2xl border border-gray-200/60 bg-white p-6 md:p-8 dark:border-white/10 dark:bg-panel">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
