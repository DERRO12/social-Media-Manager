{{-- "Watch how" button for a how-to video the admin added (Admin → Help videos). Shows nothing if there's no video. --}}
@props(['topic'])

@php($videoId = \App\Support\HelpVideos::get($topic))

@if ($videoId)
    @php([$title] = \App\Support\HelpVideos::TOPICS[$topic])
    <div x-data="{ open: false }" {{ $attributes->class('w-full') }}>
        <button type="button" x-on:click="open = ! open"
            class="inline-flex items-center gap-2 rounded-full bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-950/70">
            <flux:icon name="play-circle" variant="mini" />
            <span x-text="open ? 'Hide video' : @js('Watch how: '.$title)"></span>
        </button>
        <template x-if="open">
            <div class="mt-3 aspect-video w-full max-w-2xl overflow-hidden rounded-xl bg-black">
                <iframe src="{{ \App\Support\YouTube::embedUrl($videoId) }}&autoplay=1" title="{{ $title }}" class="size-full"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
        </template>
    </div>
@endif
