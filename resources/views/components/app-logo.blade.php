@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg border border-gray-200/60 bg-white dark:border-white/20 dark:ring-1 dark:ring-white/10">
            <img src="{{ asset('images/logo-agapestore.png') }}" alt="Ágape Store" class="size-full object-cover" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg border border-gray-200/60 bg-white dark:border-white/20 dark:ring-1 dark:ring-white/10">
            <img src="{{ asset('images/logo-agapestore.png') }}" alt="Ágape Store" class="size-full object-cover" />
        </x-slot>
    </flux:brand>
@endif
