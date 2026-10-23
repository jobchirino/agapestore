@props([
    'compact' => false,
])

<div {{ $attributes->class([
    'rounded-xl bg-rose-50 px-3 py-2 font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    $compact ? 'text-[10px] leading-snug' : 'text-xs',
]) }}>
    {{ \App\Services\BcvRateService::FAILURE_MESSAGE }}
    <a href="https://www.bcv.org.ve" target="_blank" rel="noopener" class="font-semibold underline">bcv.org.ve</a>.
</div>
