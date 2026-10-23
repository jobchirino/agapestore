@props([
    'amount' => 0,
    'currency' => null,
    'size' => 'sm',
    'tone' => null,
])

@php
    // Tailwind v4 no permite pisar utilidades con `!`; el tamaño y el color se resuelven aquí.
    $rate = app(\App\Services\BcvRateService::class)->activeRate($currency);
    $sizeClass = $size === 'xs' ? 'text-[10px]' : 'text-[11px]';
    $toneClass = $tone ?? 'text-zinc-500 dark:text-zinc-400';
@endphp

@if ($rate !== null)
    <span {{ $attributes->class(['block font-medium leading-tight tabular-nums', $sizeClass, $toneClass]) }}>
        {{ \App\Support\Money::bs((float) $amount * $rate) }}
    </span>
@endif
