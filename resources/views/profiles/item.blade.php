@php
    $name = $profile->name();
    $title = $item->title ?: \Illuminate\Support\Str::limit((string) $item->caption, 70) ?: 'Post by '.$name;
    $description = \Illuminate\Support\Str::limit(trim((string) $item->caption) ?: $title, 155);
    $url = route('profiles.item', [$profile, $item, \App\Http\Controllers\PublicProfileController::slug($item)]);
    $isVideo = $item->type === 'video' || $embedUrl || in_array($item->account->platform, [\App\Enums\Platform::YouTube, \App\Enums\Platform::TikTok], true);

    // VideoObject is what lets search engines show this as a video result (needs name, thumbnail and upload date).
    $jsonLd = $isVideo && $item->thumbnail_url && $item->published_at
        ? array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $title,
            'description' => $description,
            'thumbnailUrl' => [$item->thumbnail_url],
            'uploadDate' => $item->published_at->toIso8601String(),
            'embedUrl' => $embedUrl,
            'url' => $item->permalink,
            'author' => ['@type' => 'Person', 'name' => $name, 'url' => $profile->url()],
            'interactionStatistic' => $item->views !== null ? [
                '@type' => 'InteractionCounter',
                'interactionType' => ['@type' => 'WatchAction'],
                'userInteractionCount' => (int) $item->views,
            ] : null,
        ])
        : array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'SocialMediaPosting',
            'headline' => $title,
            'articleBody' => $item->caption,
            'datePublished' => $item->published_at?->toIso8601String(),
            'image' => $item->thumbnail_url,
            'url' => $item->permalink,
            'author' => ['@type' => 'Person', 'name' => $name, 'url' => $profile->url()],
        ]);
@endphp

<x-layouts.public :title="$title.' | '.$name" :description="$description">
    <x-slot:head>
        <link rel="canonical" href="{{ $url }}">
        <meta property="og:type" content="{{ $isVideo ? 'video.other' : 'article' }}">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $url }}">
        @if ($item->thumbnail_url)<meta property="og:image" content="{{ $item->thumbnail_url }}">@endif
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
    </x-slot:head>

    <article class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <a href="{{ $profile->url() }}" class="text-sm text-zinc-500 hover:underline">← {{ $name }}</a>

        <div class="mt-4 overflow-hidden rounded-2xl bg-black shadow-lg">
            @if ($embedUrl)
                <div class="aspect-video">
                    <iframe src="{{ $embedUrl }}" title="{{ $title }}" class="size-full" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
                </div>
            @elseif ($item->thumbnail_url)
                <a href="{{ $item->permalink }}" target="_blank" rel="noopener" class="relative block">
                    <img src="{{ $item->thumbnail_url }}" alt="{{ $title }}" class="max-h-[70vh] w-full object-contain" referrerpolicy="no-referrer">
                    @if ($isVideo)
                        <span class="absolute inset-0 flex items-center justify-center">
                            <span class="flex size-16 items-center justify-center rounded-full bg-white/90 text-zinc-900"><flux:icon name="play" variant="solid" class="size-7" /></span>
                        </span>
                    @endif
                </a>
            @endif
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2 text-sm text-zinc-500">
            <x-platform-badge :platform="$item->account->platform" />
            @if ($item->published_at)<span>{{ $item->published_at->format('F j, Y') }}</span>@endif
            @if ($item->views !== null)<span>· {{ \App\Support\Metric::short($item->views) }} views</span>@endif
            @if ($item->likes !== null)<span>· {{ \App\Support\Metric::short($item->likes) }} likes</span>@endif
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $title }}</h1>
        @if ($item->caption && $item->caption !== $item->title)
            <p class="mt-4 whitespace-pre-line leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $item->caption }}</p>
        @endif
        <a href="{{ $item->permalink }}" target="_blank" rel="noopener" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">
            Watch on {{ $item->account->platform->label() }} <flux:icon name="arrow-top-right-on-square" variant="mini" />
        </a>

        @if ($more->isNotEmpty())
            <section class="mt-12">
                <h2 class="text-lg font-semibold">More from {{ $name }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    @foreach ($more as $other)
                        <a href="{{ route('profiles.item', [$profile, $other, \App\Http\Controllers\PublicProfileController::slug($other)]) }}" class="group">
                            <div class="aspect-video overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-900">
                                @if ($other->thumbnail_url)
                                    <img src="{{ $other->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover">
                                @endif
                            </div>
                            <div class="mt-2 line-clamp-2 text-sm font-medium group-hover:underline">{{ $other->title ?: \Illuminate\Support\Str::limit($other->caption, 60) }}</div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </article>
</x-layouts.public>
