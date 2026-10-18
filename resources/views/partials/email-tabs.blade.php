<div class="flex flex-col gap-4">
    <div>
        <flux:heading size="xl">Email &amp; SMS marketing</flux:heading>
        <flux:text>Build your own audience: collect emails and phone numbers, then send newsletters, offers and SMS. Your contacts are private to you.</flux:text>
    </div>
    <nav class="-mx-4 flex gap-1 overflow-x-auto border-b border-zinc-200 px-4 sm:mx-0 sm:px-0 dark:border-zinc-700">
        @foreach ([['email.campaigns', 'paper-airplane', 'Campaigns', 'email.campaign*'], ['email.contacts', 'users', 'Contacts', 'email.contacts'], ['email.settings', 'cog-6-tooth', 'Settings & sign-up form', 'email.settings']] as [$route, $icon, $label, $pattern])
            <a href="{{ route($route) }}" wire:navigate @class([
                '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => request()->routeIs($pattern),
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => ! request()->routeIs($pattern),
            ])>
                <flux:icon :name="$icon" variant="mini" /> {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
