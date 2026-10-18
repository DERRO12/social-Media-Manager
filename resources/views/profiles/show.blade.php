@php
    use App\Support\ProfileSchema;

    $name = $profile->name();
    $avatar = $profile->avatarUrl();
    $sameAs = ProfileSchema::sameAs($profile);
    $business = $profile->business();
    $mapUrl = $business ? ProfileSchema::mapUrl($business, $profile) : null;
    $shareImage = $profile->avatar_path ? $avatar : ($photos->first()?->url() ?? $avatar);
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ProfilePage',
        'name' => $profile->searchTitle(),
        'url' => $profile->url(),
        'dateModified' => $profile->updated_at?->toIso8601String(),
        'mainEntity' => ProfileSchema::entity($profile),
    ];
    if ($photos->isNotEmpty()) {
        $jsonLd['image'] = $photos->take(10)->map(fn ($p) => ProfileSchema::photo($p, $profile))->map(fn ($p) => array_diff_key($p, ['@context' => 1]))->values()->all();
    }
@endphp

<x-layouts.public :title="$profile->searchTitle()" :description="$profile->searchDescription()">
    <x-slot:head>
        <link rel="canonical" href="{{ $profile->url() }}">
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
        <meta property="og:type" content="profile">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ $profile->searchTitle() }}">
        <meta property="og:description" content="{{ $profile->searchDescription() }}">
        <meta property="og:url" content="{{ $profile->url() }}">
        @if ($shareImage)<meta property="og:image" content="{{ $shareImage }}">@endif
        <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
        <script type="application/ld+json">{!! ProfileSchema::json($jsonLd) !!}</script>
    </x-slot:head>

    @unless ($profile->is_public)
        <div class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">Preview: this page is off, so only you can see it. Turn it on in Grow on Google → Public page.</div>
    @endunless

    <section class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40">
        <div class="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 py-10 text-center sm:px-6 sm:py-12">
            <x-avatar size="lg" :src="$avatar" :name="$name" class="!size-24 !text-2xl ring-4 ring-white dark:ring-zinc-900" />
            <div>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $name }}</h1>
                @if ($profile->headline)<p class="mt-2 text-base text-zinc-600 sm:text-lg dark:text-zinc-400">{{ $profile->headline }}</p>@endif
                @if ($business?->primary_category)<p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $business->primary_category }}</p>@endif
                @if ($profile->location)<p class="mt-1 flex items-center justify-center gap-1 text-sm text-zinc-500"><flux:icon name="map-pin" variant="micro" /> {{ $profile->location }}</p>@endif
            </div>
            @if ($profile->bio)
                <p class="max-w-2xl whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $profile->bio }}</p>
            @endif
            <div class="flex flex-wrap justify-center gap-2">
                @if ($business?->phone)
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}" class="inline-flex items-center gap-1.5 rounded-full bg-zinc-900 px-4 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-zinc-900"><flux:icon name="phone" variant="micro" /> Call</a>
                @endif
                @if ($business?->booking_url)
                    <a href="{{ $business->booking_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full bg-zinc-900 px-4 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-zinc-900"><flux:icon name="calendar" variant="micro" /> Book</a>
                @endif
                @if ($mapUrl)
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full border border-zinc-300 px-4 py-1.5 text-sm dark:border-zinc-700"><flux:icon name="map" variant="micro" /> Directions</a>
                @endif
                @if ($profile->website)
                    <a href="{{ $profile->website }}" target="_blank" rel="noopener" class="rounded-full border border-zinc-300 px-4 py-1.5 text-sm dark:border-zinc-700">Website</a>
                @endif
                @foreach ($sameAs as $link)
                    <a href="{{ $link }}" target="_blank" rel="noopener me" class="rounded-full border border-zinc-300 px-4 py-1.5 text-sm dark:border-zinc-700">{{ parse_url($link, PHP_URL_HOST) === 'www.youtube.com' ? 'YouTube' : ucfirst(str_replace(['www.', '.com'], '', parse_url($link, PHP_URL_HOST))) }}</a>
                @endforeach
            </div>
        </div>
    </section>

    @if ($business)
        <section class="mx-auto max-w-6xl px-4 pt-10 sm:px-6" aria-labelledby="about-business">
            <h2 id="about-business" class="text-xl font-semibold">About the business</h2>
            <div class="mt-5 grid gap-5 md:grid-cols-3">
                @if ($business->services)
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                        <h3 class="font-medium">Services</h3>
                        <ul class="mt-3 space-y-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                            @foreach ($business->services as $service)
                                <li class="flex gap-2"><flux:icon name="check" variant="micro" class="mt-0.5 shrink-0 text-green-600" /> {{ $service }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (collect($business->hours ?? [])->contains(fn ($h) => $h['open'] ?? false))
                    <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                        <h3 class="font-medium">Opening hours</h3>
                        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            @foreach (\App\Models\BusinessProfile::DAYS as $day)
                                @php($h = $business->hours[$day] ?? null)
                                <dt class="capitalize text-zinc-500">{{ $day }}</dt>
                                <dd>{{ ($h['open'] ?? false) ? ($h['from'] ?? '').' – '.($h['to'] ?? '') : 'Closed' }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endif
                <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-800">
                    <h3 class="font-medium">Contact</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        @if ($business->address || $business->city)
                            <div><dt class="text-zinc-500">Address</dt><dd>{{ collect([$business->address, $business->city])->filter()->implode(', ') }}</dd></div>
                        @endif
                        @if ($business->service_area)
                            <div><dt class="text-zinc-500">Areas served</dt><dd>{{ $business->service_area }}</dd></div>
                        @endif
                        @if ($business->phone)
                            <div><dt class="text-zinc-500">Phone</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}" class="underline">{{ $business->phone }}</a></dd></div>
                        @endif
                    </dl>
                    @if ($business->reviewUrl())
                        <a href="{{ $business->reviewUrl() }}" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium underline"><flux:icon name="star" variant="micro" /> Review us on Google</a>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($photos->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 pt-10 sm:px-6" aria-labelledby="photos">
            <h2 id="photos" class="text-xl font-semibold">Photos</h2>
            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($photos as $photo)
                    <a href="{{ $photo->pageUrl($profile) }}" class="group">
                        <figure>
                            <div class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-900">
                                <img src="{{ $photo->thumbUrl() }}" alt="{{ $photo->altText() }}" loading="lazy"
                                    @if ($photo->width && $photo->height) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif
                                    class="size-full object-cover transition group-hover:scale-105">
                            </div>
                            <figcaption class="mt-2 line-clamp-2 text-sm font-medium group-hover:underline">{{ $photo->title }}</figcaption>
                        </figure>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($profile->newsletter_enabled)
        <section id="subscribe" class="mx-auto max-w-6xl scroll-mt-20 px-4 pt-10 sm:px-6">
            <div class="rounded-2xl bg-linear-to-br from-indigo-50 via-fuchsia-50 to-orange-50 p-6 sm:p-8 dark:from-indigo-950/40 dark:via-fuchsia-950/30 dark:to-orange-950/30">
                <h2 class="text-xl font-semibold">Join {{ $name }}’s mailing list</h2>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $profile->newsletter_text ?: 'Get news, offers and new content by email. Unsubscribe any time.' }}</p>
                @if (session('newsletter'))
                    <p class="mt-4 font-medium text-green-700 dark:text-green-400" role="status">{{ session('newsletter') }}</p>
                @else
                    <form method="POST" action="{{ route('newsletter.subscribe', $profile) }}#subscribe" class="mt-4 flex flex-col gap-3 sm:max-w-xl">
                        @csrf
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <input name="name" value="{{ old('name') }}" placeholder="Your name" autocomplete="name" class="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <input name="email" type="email" required value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" class="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        </div>
                        {{-- Left empty by people; bots fill it in. --}}
                        <input name="company" tabindex="-1" autocomplete="off" aria-hidden="true" class="hidden">
                        <label class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <input type="checkbox" name="consent" value="1" required class="mt-0.5"> I agree to receive emails from {{ $name }}.
                        </label>
                        @if ($errors->newsletter->any())
                            <p class="text-sm text-red-600">{{ $errors->newsletter->first() }}</p>
                        @endif
                        <button class="self-start rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">Subscribe</button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    <section class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-12">
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
