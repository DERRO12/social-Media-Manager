<?php

use App\Models\Course;
use App\Support\HelpVideos;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $videos = HelpVideos::all();

        return [
            'topics' => collect(HelpVideos::TOPICS)->filter(fn ($t, $key) => isset($videos[$key])),
            'videos' => $videos,
            'guide' => Course::published()->where('slug', 'complete-guide-to-the-platform')->first(),
            'marketing' => Course::published()->where('slug', 'email-and-sms-marketing')->first(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Help &amp; tutorials</flux:heading>
        <flux:text>Short videos showing how to do everything, step by step.</flux:text>
    </div>

    @if ($guide || $marketing)
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (array_filter([$guide, $marketing]) as $course)
                <a href="{{ route('academy.course', $course) }}" wire:navigate class="flex items-center gap-3 rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                    <flux:icon name="academic-cap" class="size-6 text-indigo-500" />
                    <div>
                        <div class="font-medium">{{ $course->title }}</div>
                        <div class="text-sm text-zinc-500">Free course with a certificate</div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($topics->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:icon name="play-circle" class="mx-auto size-8 text-zinc-400" />
            <flux:text class="mt-2">Tutorial videos are coming soon. Meanwhile, the Academy courses explain every feature step by step.</flux:text>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($topics as $key => [$title, $about, $route])
                <div wire:key="v-{{ $key }}" x-data="{ play: false }" class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="relative aspect-video bg-black">
                        <template x-if="play">
                            <iframe src="{{ \App\Support\YouTube::embedUrl($videos[$key]) }}&autoplay=1" title="{{ $title }}" class="size-full"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        </template>
                        <button type="button" x-show="! play" x-on:click="play = true" class="group absolute inset-0" aria-label="Play: {{ $title }}">
                            <img src="{{ \App\Support\YouTube::thumbnail($videos[$key]) }}" alt="" class="size-full object-cover opacity-90 group-hover:opacity-100" loading="lazy">
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="flex size-14 items-center justify-center rounded-full bg-red-600 text-white shadow-lg"><flux:icon name="play" variant="solid" class="size-6" /></span>
                            </span>
                        </button>
                    </div>
                    <div class="p-4">
                        <div class="font-medium">{{ $title }}</div>
                        <div class="text-sm text-zinc-500">{{ $about }}</div>
                        @if (Route::has($route))
                            <a href="{{ route($route) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-sm font-medium underline">Go there <flux:icon name="arrow-right" variant="micro" /></a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
