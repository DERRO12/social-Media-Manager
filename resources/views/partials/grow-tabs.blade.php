<div class="flex flex-col gap-4">
    <div>
        <flux:heading size="xl">Grow on Google</flux:heading>
        <flux:text>Show up when people search: your Google Business Profile, your website’s SEO, and a public page for your content.</flux:text>
    </div>
    <nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700">
        @foreach ([['grow.business', 'map-pin', 'Business Profile'], ['grow.seo', 'magnifying-glass', 'SEO checker'], ['grow.page', 'globe-alt', 'Public page']] as [$route, $icon, $label])
            <a href="{{ route($route) }}" wire:navigate @class([
                '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => request()->routeIs($route),
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => ! request()->routeIs($route),
            ])>
                <flux:icon :name="$icon" variant="mini" /> {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
