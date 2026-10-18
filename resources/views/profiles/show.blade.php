@php
    $name = $profile->name();
    $description = $profile->headline ?: \Illuminate\Support\Str::limit((string) $profile->bio, 155);
    $avatar = $profile->user->socialAccounts->firstWhere('avatar_url', '!=', null)?->avatar_url;
    $sameAs = $profile->user->socialAccounts->map(fn ($a) => match ($a->platform) {
        \App\Enums\Platform::YouTube => 'https://www.youtube.com/channel/'.$a->platform_user_id,
        \App\Enums\Platform::TikTok => $a->username ? 'https://www.tiktok.com/@'.ltrim($a->username, '@') : null,
        \App\Enums\Platform::Instagram => $a->username ? 'https://www.instagram.com/'.ltrim($a->username, '@') : null,
        \App\Enums\Platform::X => $a->username ? 'https://x.com/'.ltrim($a->username, '@') : null,
        \App\Enums\Platform::Facebook => 'https://www.facebook.com/'.$a->platform_user_id,
        default => null,
    })->filter()->values();
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ProfilePage',
        'name' => $name,
        'url' => $profile->url(),
        'mainEntity' => array_filter([
            '@type' => 'Person',
            'name' => $name,
            'description' => $profile->headline ?: $profile->bio,
            'image' => $avatar,
            'url' => $profile->website,
            'sameAs' => $sameAs->all() ?: null,
        ]),
    ];
@endphp

<x-layouts.public :title="$name.($profile->headline ? ': '.$profile->headline : '')" :description="$description">
    <x-slot:head>
        <link rel="canonical" href="{{ $profile->url() }}">
        <meta property="og:type" content="profile">
        <meta property="og:title" content="{{ $name }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $profile->url() }}">
        @if ($avatar)<meta property="og:image" content="{{ $avatar }}">@endif
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
    </x-slot:head>

    @unless ($profile->is_public)
        <div class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">Preview: this page is off, so only you can see it. Turn it on in Grow on Google → Public page.</div>
    @endunless

    <section class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40">
        <div class="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 py-12 text-center sm:px-6">
            <x-avatar size="lg" :src="$avatar" :name="$name" class="!size-24 !text-2xl ring-4 ring-white dark:ring-zinc-900" />
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">{{ $name }}</h1>
                @if ($profile->headline)<p class="mt-2 text-lg text-zinc-600 dark:text-zinc-400">{{ $profile->headline }}</p>@endif
                @if ($profile->location)<p class="mt-1 flex items-center justify-center gap-1 text-sm text-zinc-500"><flux:icon name="map-pin" variant="micro" /> {{ $profile->location }}</p>@endif
            </div>
            @if ($profile->bio)
                <p class="max-w-2xl whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $profile->bio }}</p>
            @endif
            <div class="flex flex-wrap justify-center gap-2">
                @if ($profile->website)
                    <a href="{{ $profile->website }}" target="_blank" rel="noopener" class="rounded-full bg-zinc-900 px-4 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Website</a>
                @endif
                @foreach ($sameAs as $link)
                    <a href="{{ $link }}" target="_blank" rel="noopener me" class="rounded-full border border-zinc-300 px-4 py-1.5 text-sm dark:border-zinc-700">{{ parse_url($link, PHP_URL_HOST) === 'www.youtube.com' ? 'YouTube' : ucfirst(str_replace(['www.', '.com'], '', parse_url($link, PHP_URL_HOST))) }}</a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <h2 class="text-xl font-semibold">Latest videos and posts</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($items as $item)
                <a href="{{ route('profiles.item', [$profile, $item, \App\Http\Controllers\PublicProfileController::slug($item)]) }}"
                    class="group overflow-hidden rounded-2xl border border-zinc-200 transition hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-800">
                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-900">
                        @if ($item->thumbnail_url)
                            <img src="{{ $item->thumbnail_url }}" alt="{{ $item->title ?: \Illuminate\Support\Str::limit($item->caption, 80) }}" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover">
                        @endif
                    </div>
                    <div class="p-4">
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <x-platform-badge :platform="$item->account->platform" />
                            @if ($item->published_at)<span>{{ $item->published_at->toFormattedDateString() }}</span>@endif
                        </div>
                        <h3 class="mt-2 line-clamp-2 font-medium group-hover:underline">{{ $item->title ?: \Illuminate\Support\Str::limit($item->caption, 90) ?: 'Post' }}</h3>
                    </div>
                </a>
            @empty
                <p class="col-span-full text-zinc-500">No posts yet.</p>
            @endforelse
        </div>
        <div class="mt-8">{{ $items->links() }}</div>
    </section>
</x-layouts.public>
