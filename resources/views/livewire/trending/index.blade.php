<?php

use App\Services\Social\TrendingService;
use App\Support\Metric;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    #[Url]
    public string $country = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $length = '';

    public function mount(TrendingService $trending): void
    {
        if (! array_key_exists($this->country, TrendingService::COUNTRIES)) {
            $this->country = $trending->defaultCountry(auth()->user());
        }
    }

    public function with(): array
    {
        $result = app(TrendingService::class)->youtube(auth()->user(), $this->country, $this->category);

        $videos = collect($result['videos'])->filter(fn ($v) => match ($this->length) {
            'short' => $v->isShortForm(),
            'long' => ! $v->isShortForm(),
            default => true,
        })->values();

        return [
            'videos' => $videos,
            'available' => $result['available'],
            'error' => $result['error'],
            'external' => TrendingService::externalSources(),
            'categories' => TrendingService::YOUTUBE_CATEGORIES,
            'countries' => TrendingService::COUNTRIES,
        ];
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Trending</flux:heading>
            <flux:text>What’s popular right now, to inspire your next post.</flux:text>
        </div>
        <flux:select wire:model.live="country" class="w-56" aria-label="Country">
            @foreach ($countries as $code => $name)
                <option value="{{ $code }}">{{ $name }}</option>
            @endforeach
        </flux:select>
    </div>

    {{-- YouTube: live data --}}
    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <x-platform-badge :platform="\App\Enums\Platform::YouTube" />
            <flux:heading size="lg">
                {{ $category === '10' ? 'Trending music' : 'Trending videos' }} in {{ $countries[$country] }}
            </flux:heading>
            <flux:text class="text-sm">Updated every 30 minutes</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($categories as $id => $label)
                <button type="button" wire:click="$set('category', '{{ $id }}')" wire:key="cat-{{ $id ?: 'all' }}" @class([
                    'rounded-full border px-3 py-1 text-sm transition',
                    'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => (string) $category === (string) $id,
                    'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500' => (string) $category !== (string) $id,
                ])>
                    @if ($id === '10') 🎵 @endif{{ $label }}
                </button>
            @endforeach
            <span class="mx-1 hidden w-px bg-zinc-200 sm:block dark:bg-zinc-700"></span>
            <flux:radio.group wire:model.live="length" variant="segmented" size="sm">
                <flux:radio value="" label="Any length" />
                <flux:radio value="short" label="Short (≤ 3 min)" />
                <flux:radio value="long" label="Long" />
            </flux:radio.group>
        </div>

        @if ($category === '10')
            <flux:text class="text-sm">YouTube’s trending music chart is the closest thing to “trending sounds”: these are the songs people are watching most right now in {{ $countries[$country] }}.</flux:text>
        @endif

        <div wire:loading.delay class="text-sm text-zinc-500">Loading…</div>

        @if (! $available)
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                <flux:heading>Connect a YouTube channel to see trends</flux:heading>
                <flux:text class="mt-1">Trending videos are loaded through your YouTube connection.</flux:text>
                <flux:button class="mt-4" variant="primary" :href="route('accounts')" wire:navigate>Connect YouTube</flux:button>
            </div>
        @elseif ($error)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="$error" />
        @elseif ($videos->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                <flux:text>YouTube has no trending chart for this category in {{ $countries[$country] }}. Try “All” or another category.</flux:text>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" wire:loading.class="opacity-50">
                @foreach ($videos as $i => $video)
                    <a href="{{ $video->url }}" target="_blank" rel="noopener" wire:key="yt-{{ $video->id }}"
                        class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 transition hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-700">
                        <div class="relative aspect-video bg-zinc-100 dark:bg-zinc-900">
                            @if ($video->thumbnailUrl)
                                <img src="{{ $video->thumbnailUrl }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover" />
                            @endif
                            <span class="absolute left-2 top-2 rounded-md bg-black/75 px-1.5 py-0.5 text-xs font-semibold text-white">#{{ $i + 1 }}</span>
                            @if ($video->duration())
                                <span class="absolute bottom-2 right-2 rounded-md bg-black/75 px-1.5 py-0.5 text-xs font-medium text-white">{{ $video->duration() }}</span>
                            @endif
                        </div>
                        <div class="flex flex-1 flex-col gap-1 p-3">
                            <div class="line-clamp-2 text-sm font-medium group-hover:underline">{{ $video->title }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $video->channel }}</div>
                            <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-2 text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                @if (! is_null($video->views))
                                    <span class="flex items-center gap-1"><flux:icon name="eye" variant="micro" /> {{ Metric::short($video->views) }}</span>
                                @endif
                                @if (! is_null($video->likes))
                                    <span class="flex items-center gap-1"><flux:icon name="heart" variant="micro" /> {{ Metric::short($video->likes) }}</span>
                                @endif
                                @if (! is_null($video->comments))
                                    <span class="flex items-center gap-1"><flux:icon name="chat-bubble-left" variant="micro" /> {{ Metric::short($video->comments) }}</span>
                                @endif
                                <span class="ms-auto text-zinc-500">{{ $video->publishedAt?->diffForHumans() }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Platforms without a trends API --}}
    <section class="flex flex-col gap-4">
        <div>
            <flux:heading size="lg">Trends on other platforms</flux:heading>
            <flux:text class="text-sm">These platforms don’t let apps read their trends, so they open on the platform’s own official page.</flux:text>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($external as $source)
                <div class="flex flex-col rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <x-platform-badge :platform="$source['platform']" />
                        <flux:heading>{{ $source['title'] }}</flux:heading>
                    </div>
                    <flux:text class="mt-2 flex-1 text-sm">{{ $source['text'] }}</flux:text>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($source['links'] as [$label, $url])
                            <flux:button size="sm" icon-trailing="arrow-top-right-on-square" :href="$url" target="_blank" rel="noopener">{{ $label }}</flux:button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
