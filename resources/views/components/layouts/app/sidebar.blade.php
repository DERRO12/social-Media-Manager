<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" href="#"></x-app-logo>
            </a>

            @php($unread = auth()->user()->comments()->unread()->count())

            <flux:navlist variant="outline">
                <flux:navlist.group heading="Workspace" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                    <flux:navlist.item icon="pencil-square" :href="route('compose')" :current="request()->routeIs('compose')" wire:navigate>Create post</flux:navlist.item>
                    <flux:navlist.item icon="calendar-days" :href="route('posts')" :current="request()->routeIs('posts')" wire:navigate>Scheduled &amp; sent</flux:navlist.item>
                    <flux:navlist.item icon="film" :href="route('content')" :current="request()->routeIs('content')" wire:navigate>All content</flux:navlist.item>
                    <flux:navlist.item icon="chat-bubble-left-right" :href="route('inbox')" :current="request()->routeIs('inbox')" :badge="$unread ?: null" wire:navigate>Inbox</flux:navlist.item>
                    <flux:navlist.item icon="fire" :href="route('trending')" :current="request()->routeIs('trending')" wire:navigate>Trending</flux:navlist.item>
                    <flux:navlist.item icon="academic-cap" :href="route('academy')" :current="request()->routeIs('academy*')" wire:navigate>Academy</flux:navlist.item>
                    <flux:navlist.item icon="magnifying-glass-circle" :href="route('grow.business')" :current="request()->routeIs('grow*')" wire:navigate>Grow on Google</flux:navlist.item>
                    <flux:navlist.item icon="link" :href="route('accounts')" :current="request()->routeIs('accounts')" wire:navigate>Connected accounts</flux:navlist.item>
                    @unless (auth()->user()->isAdmin())
                        <flux:navlist.item icon="credit-card" :href="route('billing')" :current="request()->routeIs('billing')" wire:navigate>Plan &amp; billing</flux:navlist.item>
                    @endunless
                </flux:navlist.group>

                @if (auth()->user()->isAdmin())
                    <flux:navlist.group heading="Admin" class="grid">
                        <flux:navlist.item icon="chart-bar" :href="route('admin.overview')" :current="request()->routeIs('admin.overview')" wire:navigate>Overview</flux:navlist.item>
                        <flux:navlist.item icon="users" :href="route('admin.clients')" :current="request()->routeIs('admin.clients', 'admin.client')" wire:navigate>Clients</flux:navlist.item>
                        <flux:navlist.item icon="currency-dollar" :href="route('admin.plans')" :current="request()->routeIs('admin.plans')" wire:navigate>Plans</flux:navlist.item>
                        @php($pendingPayments = \App\Models\PaymentClaim::pending()->count())
                        <flux:navlist.item icon="banknotes" :href="route('admin.payments')" :current="request()->routeIs('admin.payments')" :badge="$pendingPayments ?: null" wire:navigate>Payments</flux:navlist.item>
                        <flux:navlist.item icon="book-open" :href="route('admin.courses')" :current="request()->routeIs('admin.courses', 'admin.course', 'admin.certificates')" wire:navigate>Courses</flux:navlist.item>
                        <flux:navlist.item icon="cog-6-tooth" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>Settings</flux:navlist.item>
                    </flux:navlist.group>
                @endif
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/90 !px-3 backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/90">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="Open menu" />

            <a href="{{ route('dashboard') }}" class="ml-1 flex min-w-0 items-center" wire:navigate>
                <x-app-logo class="size-7" href="#"></x-app-logo>
            </a>

            <flux:spacer />

            <a href="{{ route('inbox') }}" class="relative mr-1 rounded-lg p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Inbox" wire:navigate>
                <flux:icon.bell variant="outline" class="size-5" />
                @if ($unread)
                    <span class="absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold leading-4 text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                @endif
            </a>

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <!-- Phone tab bar: the five places people go most, one thumb away -->
        @php($tabs = [
            ['dashboard', 'home', 'Home', 'dashboard'],
            ['compose', 'plus-circle', 'Create', 'compose'],
            ['inbox', 'chat-bubble-left-right', 'Inbox', 'inbox'],
            ['content', 'film', 'Content', 'content'],
            ['academy', 'academic-cap', 'Learn', 'academy*'],
        ])
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95" aria-label="Main">
            <div class="mx-auto grid max-w-md grid-cols-5">
                @foreach ($tabs as [$route, $icon, $label, $pattern])
                    @php($active = request()->routeIs($pattern))
                    <a href="{{ route($route) }}" wire:navigate @class([
                        'relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
                        'text-zinc-900 dark:text-white' => $active,
                        'text-zinc-500 dark:text-zinc-400' => ! $active,
                    ]) @if ($active) aria-current="page" @endif>
                        @if ($route === 'compose')
                            <span class="-mt-1 flex size-8 items-center justify-center rounded-full bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white shadow">
                                <flux:icon.plus variant="mini" />
                            </span>
                        @else
                            <flux:icon :name="$icon" :variant="$active ? 'solid' : 'outline'" class="size-6" />
                        @endif
                        {{ $label }}
                        @if ($route === 'inbox' && $unread)
                            <span class="absolute left-1/2 top-1 ml-2 min-w-4 rounded-full bg-rose-500 px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </nav>

        @fluxScripts
    </body>
</html>
