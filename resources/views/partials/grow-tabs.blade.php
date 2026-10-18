<div class="flex flex-col gap-4">
    <div>
        <flux:heading size="xl">Grow on Google</flux:heading>
        <flux:text>Show up when people search: your Google Business Profile, your website’s SEO, and a public page for your content and photos.</flux:text>
    </div>
    <nav class="-mx-4 flex gap-1 overflow-x-auto border-b border-zinc-200 px-4 sm:mx-0 sm:px-0 dark:border-zinc-700">
        @foreach ([['grow.business', 'map-pin', 'Business Profile'], ['grow.page', 'globe-alt', 'Public page'], ['grow.photos', 'photo', 'Photos'], ['grow.seo', 'magnifying-glass', 'SEO checker']] as [$route, $icon, $label])
            <a href="{{ route($route) }}" wire:navigate @class([
                '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => request()->routeIs($route),
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => ! request()->routeIs($route),
            ])>
                <flux:icon :name="$icon" variant="mini" /> {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
