@php
    use App\Support\ProfileSchema;

    $name = $profile->name();
    $url = $photo->pageUrl($profile);
    $description = \Illuminate\Support\Str::limit(trim((string) $photo->caption) ?: $photo->altText().($photo->location ? ' – '.$photo->location : ''), 155);
    $graph = [
        '@context' => 'https://schema.org',
        '@graph' => [
            array_diff_key(ProfileSchema::photo($photo, $profile), ['@context' => 1]),
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $name, 'item' => $profile->url()],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $photo->title, 'item' => $url],
                ],
            ],
        ],
    ];
@endphp

<x-layouts.public :title="$photo->title.' | '.$name" :description="$description">
    <x-slot:head>
        <link rel="canonical" href="{{ $url }}">
        <meta name="robots" content="index, follow, max-image-preview:large">
        @if ($photo->keywordList())<meta name="keywords" content="{{ implode(', ', $photo->keywordList()) }}">@endif
        <meta property="og:type" content="article">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ $photo->title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $url }}">
        <meta property="og:image" content="{{ $photo->url() }}">
        @if ($photo->width && $photo->height)
            <meta property="og:image:width" content="{{ $photo->width }}">
            <meta property="og:image:height" content="{{ $photo->height }}">
        @endif
        <meta property="og:image:alt" content="{{ $photo->altText() }}">
        <meta name="twitter:card" content="summary_large_image">
        <link rel="preload" as="image" href="{{ $photo->url() }}">
        <script type="application/ld+json">{!! ProfileSchema::json($graph) !!}</script>
    </x-slot:head>

    <article class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        <nav class="text-sm text-zinc-500" aria-label="Breadcrumb">
            <a href="{{ $profile->url() }}" class="hover:underline">{{ $name }}</a> <span aria-hidden="true">›</span> Photos
        </nav>

        <figure class="mt-4">
            <div class="overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-900">
                <img src="{{ $photo->url() }}" alt="{{ $photo->altText() }}" fetchpriority="high"
                    @if ($photo->width && $photo->height) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif
                    class="mx-auto max-h-[80vh] w-auto object-contain">
            </div>
            <figcaption class="mt-5">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $photo->title }}</h1>
                @if ($photo->caption)
                    <p class="mt-3 whitespace-pre-line leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $photo->caption }}</p>
                @endif
            </figcaption>
        </figure>

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-zinc-500">
            <span>By <a href="{{ $profile->url() }}" class="font-medium text-zinc-800 underline dark:text-zinc-200">{{ $name }}</a></span>
            @if ($photo->location)<span class="inline-flex items-center gap-1"><flux:icon name="map-pin" variant="micro" /> {{ $photo->location }}</span>@endif
            <time datetime="{{ $photo->created_at->toDateString() }}">{{ $photo->created_at->format('F j, Y') }}</time>
        </div>
        @if ($photo->keywordList())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($photo->keywordList() as $keyword)
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $keyword }}</span>
                @endforeach
            </div>
        @endif

        @if ($business = $profile->business())
            <div class="mt-6 flex flex-wrap gap-2">
                @if ($business->phone)
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}" class="inline-flex items-center gap-1.5 rounded-xl bg-zinc-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-900"><flux:icon name="phone" variant="micro" /> Call {{ $name }}</a>
                @endif
                @if ($business->booking_url)
                    <a href="{{ $business->booking_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-300 px-4 py-2 text-sm dark:border-zinc-700"><flux:icon name="calendar" variant="micro" /> Book</a>
                @endif
            </div>
        @endif

        @if ($more->isNotEmpty())
            <section class="mt-12">
                <h2 class="text-lg font-semibold">More photos from {{ $name }}</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($more as $other)
                        <a href="{{ $other->pageUrl($profile) }}" class="group">
                            <div class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-900">
                                <img src="{{ $other->thumbUrl() }}" alt="{{ $other->altText() }}" loading="lazy" class="size-full object-cover transition group-hover:scale-105">
                            </div>
                            <div class="mt-2 line-clamp-2 text-sm font-medium group-hover:underline">{{ $other->title }}</div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </article>
</x-layouts.public>
