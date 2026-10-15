@props(['src' => null, 'name' => '?', 'size' => 'sm'])

@php
    $box = ['xs' => 'size-6 text-[10px]', 'sm' => 'size-8 text-xs', 'md' => 'size-10 text-sm', 'lg' => 'size-12 text-base'][$size] ?? 'size-8 text-xs';
    $initials = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
@endphp

{{-- Social platforms' image CDNs often reject requests that carry a Referer, so send none; fall back to initials. --}}
<span x-data="{ failed: false }" {{ $attributes->class("relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-200 font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200 {$box}") }}>
    <span x-show="failed || {{ $src ? 'false' : 'true' }}" @if ($src) x-cloak @endif>{{ $initials }}</span>
    @if ($src)
        <img src="{{ $src }}" alt="{{ $name }}" loading="lazy" referrerpolicy="no-referrer"
            x-show="! failed" x-on:error="failed = true" class="absolute inset-0 size-full object-cover" />
    @endif
</span>
