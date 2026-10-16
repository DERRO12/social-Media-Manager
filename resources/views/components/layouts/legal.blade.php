@props(['title', 'updated'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title.' — '.config('app.name')])
</head>
<body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <header class="border-b border-zinc-200 dark:border-zinc-800">
        <nav class="mx-auto flex h-16 max-w-3xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white">
                    <x-app-logo-icon class="size-5" />
                </span>
                {{ config('app.name') }}
            </a>
            <div class="flex gap-5 text-sm text-zinc-600 dark:text-zinc-400">
                <a href="{{ route('privacy') }}" @class(['font-medium text-zinc-900 dark:text-white' => request()->routeIs('privacy')])>Privacy</a>
                <a href="{{ route('terms') }}" @class(['font-medium text-zinc-900 dark:text-white' => request()->routeIs('terms')])>Terms</a>
            </div>
        </nav>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $title }}</h1>
        <p class="mt-2 text-sm text-zinc-500">Last updated: {{ $updated }}</p>

        <div class="mt-8 leading-relaxed text-zinc-700 dark:text-zinc-300
            [&_a]:font-medium [&_a]:text-indigo-600 [&_a]:underline dark:[&_a]:text-indigo-400
            [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white
            [&_h3]:mt-6 [&_h3]:font-semibold [&_h3]:text-zinc-900 dark:[&_h3]:text-white
            [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:ps-6 [&_strong]:text-zinc-900 dark:[&_strong]:text-white">
            {{ $slot }}
        </div>
    </main>

    <footer class="border-t border-zinc-200 py-8 text-center text-sm text-zinc-500 dark:border-zinc-800">
        © {{ now()->year }} {{ config('app.name') }} ·
        <a href="{{ route('privacy') }}" class="hover:underline">Privacy</a> ·
        <a href="{{ route('terms') }}" class="hover:underline">Terms</a> ·
        <a href="{{ route('home') }}" class="hover:underline">Home</a>
    </footer>
</body>
</html>
