@php
    $appName = config('app.name');
    $trialDays = (int) \App\Models\Setting::get('billing.trial_days', 14);
    $plans = \App\Models\Plan::active()->get();
    $courses = \App\Models\Course::published()->withCount('lessons')->with('lessons:id,course_id,duration_minutes')->take(3)->get();
    $user = auth()->user();
    $ctaUrl = $user ? route('dashboard') : (Route::has('register') ? route('register') : route('login'));
    $ctaLabel = $user ? 'Go to your dashboard' : ($trialDays > 0 ? "Start your {$trialDays}-day free trial" : 'Create your account');

    $platforms = [
        ['YouTube', 'bg-red-500'],
        ['Facebook', 'bg-blue-600'],
        ['Instagram', 'bg-pink-500'],
        ['TikTok', 'bg-zinc-900 dark:bg-white'],
        ['LinkedIn', 'bg-sky-600'],
        ['X', 'bg-zinc-700 dark:bg-zinc-300'],
    ];

    $features = [
        ['cloud-arrow-up', 'Upload once, post everywhere', 'Drop in one video, write one caption and publish to every channel at the same time. Tweak captions per platform when you need to.'],
        ['calendar-days', 'Schedule ahead', 'Plan your week in minutes. Posts go out at the right time in your own timezone, even when your laptop is closed.'],
        ['chat-bubble-left-right', 'One inbox for every comment', 'Comments from YouTube, Facebook, Instagram, LinkedIn and X land in one feed. Reply without opening five apps.'],
        ['chart-bar', 'All your content and stats', 'See every video and post with views, likes, comments and shares, including ones you posted outside the app.'],
        ['shield-check', 'Secure connections', 'You log in on each platform’s own page. We never see your passwords, and access tokens are stored encrypted.'],
        ['bolt', 'Always in sync', 'New content, metrics and comments are pulled in automatically, so your dashboard is always up to date.'],
        ['map-pin', 'Google Business Profile builder', 'Prepare a complete profile with a ready-made description, a strength score and a review link, so you show up on Google Maps.'],
        ['magnifying-glass', 'SEO checker', 'Check any page of your website and get a score with plain-language fixes for titles, speed, mobile, images and more.'],
        ['globe-alt', 'Get found on Google', 'Turn on your public page: every video gets its own search-friendly page, listed in our sitemap so Google can find it.'],
    ];

    $faqs = [
        ['Do I have to give you my social media passwords?', 'No. You click “Connect”, log in on the platform’s own page (Google, Facebook, TikTok…) and approve access. We receive a secure access key, never your password, and you can disconnect at any time.'],
        ['Which platforms are supported?', 'YouTube, Facebook Pages, Instagram Business/Creator accounts, TikTok, LinkedIn and X. What each platform allows (for example, TikTok does not share comment text) is shown when you connect.'],
        ['Is there a free trial?', $trialDays > 0 ? "Yes, every new account gets {$trialDays} days free with full access. No card needed to start." : 'Contact us and we will set you up.'],
        ['Can I cancel any time?', 'Yes. Your plan simply stops at the end of the period you paid for. Your connected accounts are never touched.'],
        ['What happens to scheduled posts if my plan ends?', 'They are paused, not lost. Pick a plan again and you can publish them.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    @include('partials.head', ['title' => "{$appName} — Post to every social network from one place"])
    <meta name="description" content="{{ $appName }} lets you publish videos to YouTube, Facebook, Instagram, TikTok, LinkedIn and X at once, schedule posts, and answer every comment from one inbox.">
    <style>
        @keyframes float { 0%, 100% { transform: translateY(0) } 50% { transform: translateY(-8px) } }
        .float-slow { animation: float 6s ease-in-out infinite }
        .float-slower { animation: float 8s ease-in-out infinite }
        @media (prefers-reduced-motion: reduce) { .float-slow, .float-slower { animation: none } }
    </style>
</head>
<body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">

    {{-- Navigation --}}
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-zinc-200/70 bg-white/80 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80">
        <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white">
                    <x-app-logo-icon class="size-5" />
                </span>
                {{ $appName }}
            </a>

            <div class="hidden items-center gap-8 text-sm text-zinc-600 md:flex dark:text-zinc-400">
                <a href="#features" class="hover:text-zinc-900 dark:hover:text-white">Features</a>
                <a href="#how" class="hover:text-zinc-900 dark:hover:text-white">How it works</a>
                <a href="{{ route('courses.index') }}" class="hover:text-zinc-900 dark:hover:text-white">Courses</a>
                @if ($plans->isNotEmpty())
                    <a href="#pricing" class="hover:text-zinc-900 dark:hover:text-white">Pricing</a>
                @endif
                <a href="#faq" class="hover:text-zinc-900 dark:hover:text-white">FAQ</a>
            </div>

            <div class="hidden items-center gap-3 md:flex">
                @auth
                    <a href="{{ $user->isAdmin() ? route('admin.overview') : route('dashboard') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                        {{ $user->isAdmin() ? 'Admin' : 'Dashboard' }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">Log in</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">Start free trial</a>
                    @endif
                @endauth
            </div>

            <button type="button" class="rounded-lg p-2 md:hidden" x-on:click="open = ! open" aria-label="Menu">
                <flux:icon name="bars-3" x-show="! open" />
                <flux:icon name="x-mark" x-show="open" x-cloak />
            </button>
        </nav>

        <div x-show="open" x-cloak x-on:click.outside="open = false" class="border-t border-zinc-200 px-4 py-4 md:hidden dark:border-zinc-800">
            <div class="flex flex-col gap-3 text-sm">
                <a href="#features" x-on:click="open = false">Features</a>
                <a href="#how" x-on:click="open = false">How it works</a>
                <a href="{{ route('courses.index') }}">Courses</a>
                @if ($plans->isNotEmpty())
                    <a href="#pricing" x-on:click="open = false">Pricing</a>
                @endif
                <a href="#faq" x-on:click="open = false">FAQ</a>
                <div class="mt-2 flex gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="flex-1 rounded-lg bg-zinc-900 px-4 py-2 text-center font-medium text-white dark:bg-white dark:text-zinc-900">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-center font-medium dark:border-zinc-700">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="flex-1 rounded-lg bg-zinc-900 px-4 py-2 text-center font-medium text-white dark:bg-white dark:text-zinc-900">Free trial</a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center">
                <div class="h-[36rem] w-[64rem] rounded-full bg-linear-to-tr from-indigo-300/50 via-fuchsia-300/40 to-orange-200/50 blur-3xl dark:from-indigo-600/25 dark:via-fuchsia-600/20 dark:to-orange-500/15"></div>
            </div>

            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-16 sm:px-6 lg:grid-cols-2 lg:pt-24">
                <div>
                    <div class="inline-flex flex-wrap items-center gap-2 rounded-full border border-zinc-200 bg-white/70 px-3 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-300">
                        @foreach ($platforms as [$name, $dot])
                            <span class="inline-flex items-center gap-1"><span class="size-1.5 rounded-full {{ $dot }}"></span>{{ $name }}</span>
                        @endforeach
                    </div>

                    <h1 class="mt-6 text-4xl font-semibold tracking-tight sm:text-5xl lg:text-6xl">
                        Post everywhere.
                        <span class="block bg-linear-to-r from-indigo-600 via-fuchsia-600 to-orange-500 bg-clip-text text-transparent dark:from-indigo-400 dark:via-fuchsia-400 dark:to-orange-300">Reply from one inbox.</span>
                    </h1>

                    <p class="mt-6 max-w-xl text-lg text-zinc-600 dark:text-zinc-400">
                        {{ $appName }} publishes your videos to every social network at once, schedules your posts,
                        and brings every comment and stat into one simple dashboard.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $ctaUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 px-6 py-3 font-medium text-white shadow-lg shadow-zinc-900/10 transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            {{ $ctaLabel }}
                            <flux:icon name="arrow-right" variant="mini" />
                        </a>
                        <a href="#how" class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-6 py-3 font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-900">
                            See how it works
                        </a>
                    </div>
                    @guest
                        @if ($trialDays > 0)
                            <p class="mt-4 text-sm text-zinc-500">No card required · Cancel any time</p>
                        @endif
                    @endguest
                </div>

                {{-- Product preview, built from the real app's look --}}
                <div class="relative mx-auto w-full max-w-lg lg:max-w-none" aria-hidden="true">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-2xl shadow-indigo-500/10 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="mb-4 flex items-center gap-1.5">
                            <span class="size-2.5 rounded-full bg-red-400"></span>
                            <span class="size-2.5 rounded-full bg-amber-400"></span>
                            <span class="size-2.5 rounded-full bg-green-400"></span>
                            <span class="ms-3 text-xs text-zinc-400">{{ $appName }} · Dashboard</span>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach ([['Views', '128.4K', '+12%'], ['Likes', '9.2K', '+8%'], ['Comments', '1.3K', '+21%']] as [$label, $value, $delta])
                                <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                                    <div class="text-xs text-zinc-500">{{ $label }}</div>
                                    <div class="mt-1 text-lg font-semibold">{{ $value }}</div>
                                    <div class="text-xs font-medium text-green-600">{{ $delta }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                            <div class="mb-2 text-xs font-medium text-zinc-500">New post</div>
                            <div class="flex gap-3">
                                <div class="flex aspect-[9/16] w-16 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-indigo-500 to-fuchsia-500 text-white">
                                    <flux:icon name="play" variant="solid" />
                                </div>
                                <div class="flex-1">
                                    <div class="h-2.5 w-11/12 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                                    <div class="mt-2 h-2.5 w-8/12 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                                    <div class="mt-4 flex flex-wrap gap-1.5">
                                        @foreach (array_slice($platforms, 0, 5) as [$name, $dot])
                                            <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium dark:bg-zinc-800">
                                                <span class="size-1.5 rounded-full {{ $dot }}"></span>{{ $name }}
                                            </span>
                                        @endforeach
                                    </div>
                                    <div class="mt-3 inline-flex rounded-md bg-zinc-900 px-3 py-1 text-[11px] font-medium text-white dark:bg-white dark:text-zinc-900">Publish to 5 accounts</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="float-slow absolute -bottom-14 -left-4 w-64 rounded-xl border border-zinc-200 bg-white p-3 shadow-xl sm:-left-10 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="flex size-6 items-center justify-center rounded-full bg-pink-100 font-semibold text-pink-700 dark:bg-pink-900/50 dark:text-pink-200">A</span>
                            <span class="font-medium">@amina.creates</span>
                            <span class="rounded bg-pink-100 px-1.5 py-0.5 text-[10px] font-medium text-pink-700 dark:bg-pink-900/50 dark:text-pink-200">Instagram</span>
                        </div>
                        <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-300">Where can I get the full tutorial? 🔥</p>
                    </div>
                    <div class="float-slower absolute -right-3 -top-6 hidden rounded-xl border border-zinc-200 bg-white px-3 py-2 shadow-xl sm:block dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center gap-2 text-xs font-medium">
                            <flux:icon name="check-circle" variant="mini" class="text-green-500" />
                            Published to YouTube
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="border-t border-zinc-200 bg-zinc-50 py-20 dark:border-zinc-800 dark:bg-zinc-900/40">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">Everything you need to grow on social</h2>
                    <p class="mt-4 text-zinc-600 dark:text-zinc-400">Stop jumping between apps. Create, publish, measure and reply in one place.</p>
                </div>
                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($features as [$icon, $title, $text])
                        <div class="rounded-2xl border border-zinc-200 bg-white p-6 transition hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 to-fuchsia-500 text-white">
                                <flux:icon :name="$icon" variant="outline" class="size-5" />
                            </div>
                            <h3 class="mt-5 font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- How it works --}}
        <section id="how" class="py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">Up and running in 3 minutes</h2>
                    <p class="mt-4 text-zinc-600 dark:text-zinc-400">No technical setup. If you can post on social media, you can use {{ $appName }}.</p>
                </div>
                <ol class="mt-14 grid gap-8 md:grid-cols-3">
                    @foreach ([
                        ['Create your account', $trialDays > 0 ? "Sign up in seconds and get {$trialDays} days free." : 'Sign up in seconds.'],
                        ['Connect your channels', 'Click Connect and log in on each platform’s own secure page. Your existing content imports automatically.'],
                        ['Post and engage', 'Upload once, publish or schedule everywhere, and answer every comment from your inbox.'],
                    ] as $i => [$title, $text])
                        <li class="relative rounded-2xl border border-zinc-200 p-6 dark:border-zinc-800">
                            <span class="text-5xl font-semibold text-zinc-200 dark:text-zinc-800">0{{ $i + 1 }}</span>
                            <h3 class="mt-2 font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Academy --}}
        <section id="academy" class="border-t border-zinc-200 bg-zinc-50 py-20 dark:border-zinc-800 dark:bg-zinc-900/40">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="grid items-end gap-6 md:grid-cols-[1fr_auto]">
                    <div class="max-w-2xl">
                        <span class="text-sm font-medium text-fuchsia-600 dark:text-fuchsia-400">{{ $appName }} Academy</span>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Learn social media marketing. Get certified.</h2>
                        <p class="mt-4 text-zinc-600 dark:text-zinc-400">Short video lessons, practical notes, a final quiz and a certificate you can add to LinkedIn, included with every plan.</p>
                    </div>
                    <a href="{{ route('courses.index') }}" class="inline-flex items-center gap-2 font-medium text-zinc-900 hover:underline dark:text-white">
                        Browse all courses <flux:icon name="arrow-right" variant="mini" />
                    </a>
                </div>

                @if ($courses->isNotEmpty())
                    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($courses as $course)
                            <x-academy.course-card :course="$course" :href="route('courses.show', $course)" />
                        @endforeach
                    </div>
                @else
                    <div class="mt-10 grid gap-6 md:grid-cols-3">
                        @foreach ([['play-circle', 'Video lessons', 'Bite-sized lessons you can watch on any device.'], ['pencil-square', 'Final quizzes', 'Check what you learned, with explanations.'], ['check-badge', 'Certificates', 'Verifiable certificates, one click to add to LinkedIn.']] as [$icon, $title, $text])
                            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                                <div class="flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 to-fuchsia-500 text-white"><flux:icon :name="$icon" class="size-5" /></div>
                                <h3 class="mt-4 font-semibold">{{ $title }}</h3>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- Inbox highlight --}}
        <section class="border-y border-zinc-200 bg-zinc-950 py-20 text-white dark:border-zinc-800">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
                <div>
                    <span class="text-sm font-medium text-fuchsia-300">Unified inbox</span>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Never miss a comment again</h2>
                    <p class="mt-4 text-zinc-400">Every comment from every channel arrives in one feed, newest first. Filter by platform or post, mark as read, and reply right there. Your replies are posted on the original platform.</p>
                    <ul class="mt-6 space-y-3 text-sm text-zinc-300">
                        @foreach (['Unread badge so nothing slips through', 'Reply without leaving the app', 'See which video each comment is on'] as $point)
                            <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-400" /> {{ $point }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="space-y-3" aria-hidden="true">
                    @foreach ([
                        ['K', 'Kwame Mensah', 'YouTube', 'bg-red-500/20 text-red-300', 'This tutorial saved my project, thank you! Part 2 when?', true],
                        ['S', '@sarah.designs', 'Instagram', 'bg-pink-500/20 text-pink-300', 'Love the colours in this one 😍', true],
                        ['J', 'John Okello', 'Facebook', 'bg-blue-500/20 text-blue-300', 'Do you offer training for teams?', false],
                    ] as [$initial, $name, $platform, $badge, $text, $unread])
                        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="flex size-7 items-center justify-center rounded-full bg-white/10 text-xs font-semibold">{{ $initial }}</span>
                                <span class="font-medium">{{ $name }}</span>
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-medium {{ $badge }}">{{ $platform }}</span>
                                @if ($unread)
                                    <span class="ms-auto size-2 rounded-full bg-blue-400"></span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-zinc-300">{{ $text }}</p>
                        </div>
                    @endforeach
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                        <div class="rounded-lg bg-white/10 px-3 py-2 text-sm text-zinc-400">Write a reply…</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Pricing --}}
        @if ($plans->isNotEmpty())
            <section id="pricing" class="py-20">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">Simple pricing</h2>
                        <p class="mt-4 text-zinc-600 dark:text-zinc-400">
                            @if ($trialDays > 0)
                                Every plan starts with a {{ $trialDays }}-day free trial.
                            @endif
                            Upgrade, downgrade or cancel any time.
                        </p>
                    </div>
                    @php($featured = $plans->count() >= 3 ? $plans->values()[1]->id : null)
                    <div @class([
                        'mt-14 grid gap-6',
                        'md:grid-cols-2' => $plans->count() === 2,
                        'md:grid-cols-3' => $plans->count() >= 3,
                        'mx-auto max-w-md' => $plans->count() === 1,
                    ])>
                        @foreach ($plans as $plan)
                            @php($isFeatured = $plan->id === $featured)
                            <div @class([
                                'relative flex flex-col rounded-2xl border p-8',
                                'border-transparent bg-zinc-900 text-white shadow-2xl ring-2 ring-fuchsia-500 dark:bg-zinc-900' => $isFeatured,
                                'border-zinc-200 dark:border-zinc-800' => ! $isFeatured,
                            ])>
                                @if ($isFeatured)
                                    <span class="absolute -top-3 left-8 rounded-full bg-linear-to-r from-indigo-500 to-fuchsia-500 px-3 py-1 text-xs font-medium text-white">Most popular</span>
                                @endif
                                <h3 class="font-semibold">{{ $plan->name }}</h3>
                                @if ($plan->description)
                                    <p @class(['mt-1 text-sm', 'text-zinc-400' => $isFeatured, 'text-zinc-500' => ! $isFeatured])>{{ $plan->description }}</p>
                                @endif
                                <div class="mt-6 flex items-baseline gap-1">
                                    <span class="text-sm font-medium">{{ $plan->currency }}</span>
                                    <span class="text-4xl font-semibold tracking-tight">{{ rtrim(rtrim(number_format((float) $plan->price, 2), '0'), '.') }}</span>
                                    <span @class(['text-sm', 'text-zinc-400' => $isFeatured, 'text-zinc-500' => ! $isFeatured])>/ {{ $plan->interval }}</span>
                                </div>
                                <ul class="mt-8 flex-1 space-y-3 text-sm">
                                    @foreach ([
                                        $plan->limitLabel($plan->max_social_accounts).' social accounts',
                                        $plan->limitLabel($plan->max_posts_per_month).' posts per month',
                                        'Scheduling & multi-platform publishing',
                                        'Unified comment inbox',
                                        'Content analytics',
                                        'Marketing courses & certificates',
                                    ] as $line)
                                        <li class="flex items-center gap-2">
                                            <flux:icon name="check" variant="mini" @class(['text-fuchsia-400' => $isFeatured, 'text-green-600' => ! $isFeatured]) />
                                            {{ $line }}
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="{{ $ctaUrl }}" @class([
                                    'mt-8 rounded-xl px-4 py-3 text-center text-sm font-medium transition',
                                    'bg-white text-zinc-900 hover:bg-zinc-200' => $isFeatured,
                                    'bg-zinc-900 text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200' => ! $isFeatured,
                                ])>
                                    {{ $user ? 'Go to dashboard' : ($trialDays > 0 ? 'Start free trial' : 'Get started') }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- FAQ --}}
        <section id="faq" class="border-t border-zinc-200 bg-zinc-50 py-20 dark:border-zinc-800 dark:bg-zinc-900/40">
            <div class="mx-auto max-w-3xl px-4 sm:px-6">
                <h2 class="text-center text-3xl font-semibold tracking-tight sm:text-4xl">Questions & answers</h2>
                <div class="mt-12 divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                    @foreach ($faqs as [$q, $a])
                        <details class="group p-6">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                                {{ $q }}
                                <flux:icon name="chevron-down" variant="mini" class="shrink-0 transition group-open:rotate-180" />
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Final call to action --}}
        <section class="px-4 py-20 sm:px-6">
            <div class="relative mx-auto max-w-5xl overflow-hidden rounded-3xl bg-linear-to-br from-indigo-600 via-fuchsia-600 to-orange-500 px-6 py-16 text-center text-white">
                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">Ready to save hours every week?</h2>
                <p class="mx-auto mt-4 max-w-xl text-white/80">Publish, schedule and reply across all your social accounts from one place.</p>
                <a href="{{ $ctaUrl }}" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 font-medium text-zinc-900 shadow-lg transition hover:bg-zinc-100">
                    {{ $ctaLabel }}
                    <flux:icon name="arrow-right" variant="mini" />
                </a>
            </div>
        </section>
    </main>

    <footer class="border-t border-zinc-200 py-10 dark:border-zinc-800">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-zinc-500 sm:flex-row sm:px-6">
            <div class="flex items-center gap-2">
                <x-app-logo-icon class="size-4" />
                © {{ now()->year }} {{ $appName }}. All rights reserved.
            </div>
            <div class="flex gap-6">
                @if (Route::has('privacy'))
                    <a href="{{ route('privacy') }}" class="hover:text-zinc-900 dark:hover:text-white">Privacy</a>
                @endif
                @if (Route::has('terms'))
                    <a href="{{ route('terms') }}" class="hover:text-zinc-900 dark:hover:text-white">Terms</a>
                @endif
                <a href="{{ route('login') }}" class="hover:text-zinc-900 dark:hover:text-white">Log in</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="hover:text-zinc-900 dark:hover:text-white">Sign up</a>
                @endif
            </div>
        </div>
    </footer>

    @livewireScripts
    @fluxScripts
</body>
</html>
