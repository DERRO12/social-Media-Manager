@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    @include('partials.head', ['title' => $title ? $title.' — '.config('app.name') : config('app.name')])
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
</head>
<body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-zinc-200/70 bg-white/80 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80">
        <nav class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white">
                    <x-app-logo-icon class="size-5" />
                </span>
                {{ config('app.name') }}
            </a>
            <div class="hidden items-center gap-8 text-sm text-zinc-600 md:flex dark:text-zinc-400">
                <a href="{{ route('home') }}#features" class="hover:text-zinc-900 dark:hover:text-white">Features</a>
                <a href="{{ route('courses.index') }}" @class(['hover:text-zinc-900 dark:hover:text-white', 'font-medium text-zinc-900 dark:text-white' => request()->routeIs('courses.*')])>Courses</a>
                <a href="{{ route('home') }}#pricing" class="hover:text-zinc-900 dark:hover:text-white">Pricing</a>
            </div>
            <div class="hidden items-center gap-3 md:flex">
                @auth
                    <a href="{{ route('academy') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">My Academy</a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300">Log in</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">Start free trial</a>
                    @endif
                @endauth
            </div>
            <button type="button" class="rounded-lg p-2 md:hidden" x-on:click="open = ! open" aria-label="Menu">
                <flux:icon name="bars-3" x-show="! open" />
                <flux:icon name="x-mark" x-show="open" x-cloak />
            </button>
        </nav>
        <div x-show="open" x-cloak class="flex flex-col gap-3 border-t border-zinc-200 px-4 py-4 text-sm md:hidden dark:border-zinc-800">
            <a href="{{ route('home') }}#features">Features</a>
            <a href="{{ route('courses.index') }}">Courses</a>
            <a href="{{ route('home') }}#pricing">Pricing</a>
            @auth
                <a href="{{ route('academy') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-center font-medium text-white dark:bg-white dark:text-zinc-900">My Academy</a>
            @else
                <a href="{{ route('login') }}">Log in</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-center font-medium text-white dark:bg-white dark:text-zinc-900">Start free trial</a>
                @endif
            @endauth
        </div>
    </header>

    <main>{{ $slot }}</main>

    <footer class="border-t border-zinc-200 py-10 dark:border-zinc-800">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-zinc-500 sm:flex-row sm:px-6">
            <div class="flex items-center gap-2"><x-app-logo-icon class="size-4" /> © {{ now()->year }} {{ config('app.name') }}</div>
            <div class="flex gap-6">
                <a href="{{ route('courses.index') }}" class="hover:text-zinc-900 dark:hover:text-white">Courses</a>
                <a href="{{ route('privacy') }}" class="hover:text-zinc-900 dark:hover:text-white">Privacy</a>
                <a href="{{ route('terms') }}" class="hover:text-zinc-900 dark:hover:text-white">Terms</a>
            </div>
        </div>
    </footer>

    @livewireScripts
    @fluxScripts
</body>
</html>
