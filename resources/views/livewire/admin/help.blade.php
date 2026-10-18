<?php

use App\Support\HelpVideos;
use App\Support\YouTube;
use Livewire\Volt\Component;

new class extends Component {
    /** @var array<string, string> topic => link as typed */
    public array $links = [];

    public function mount(): void
    {
        foreach (HelpVideos::TOPICS as $topic => $_) {
            $id = HelpVideos::get($topic);
            $this->links[$topic] = $id ? 'https://youtu.be/'.$id : '';
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $ids = [];

        foreach (HelpVideos::TOPICS as $topic => [$title]) {
            $value = trim((string) ($this->links[$topic] ?? ''));
            if ($value === '') {
                continue;
            }

            $id = YouTube::videoId($value);
            if (! $id) {
                $this->addError("links.{$topic}", 'That isn’t a YouTube video link.');

                continue;
            }
            $ids[$topic] = $id;
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        HelpVideos::save($ids);
        $this->dispatch('saved');
    }
}; ?>

<div class="flex max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl">Help videos</flux:heading>
        <flux:text>
            Paste a YouTube link for each how-to. Clients see a “Watch how” button on that page, and all videos on their Help page.
            Unlisted YouTube videos work too. Leave a topic empty to hide its button.
        </flux:text>
    </div>

    <form wire:submit="save" class="flex flex-col gap-4">
        <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach (\App\Support\HelpVideos::TOPICS as $topic => [$title, $about, $route])
                @php($id = \App\Support\YouTube::videoId($links[$topic] ?? ''))
                <div wire:key="h-{{ $topic }}" class="grid gap-3 p-4 sm:grid-cols-[1fr_280px] sm:items-center">
                    <div class="flex items-center gap-3">
                        <div class="aspect-video w-24 shrink-0 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                            @if ($id)<img src="{{ \App\Support\YouTube::thumbnail($id) }}" alt="" class="size-full object-cover" loading="lazy">@endif
                        </div>
                        <div class="min-w-0">
                            <div class="font-medium">{{ $title }}</div>
                            <div class="text-sm text-zinc-500">{{ $about }}</div>
                        </div>
                    </div>
                    <div>
                        <flux:input wire:model.live.debounce.500ms="links.{{ $topic }}" placeholder="https://youtu.be/…" size="sm" :aria-label="'YouTube link for '.$title" />
                        <flux:error name="links.{{ $topic }}" />
                    </div>
                </div>
            @endforeach
        </div>
        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save videos</flux:button>
            <x-action-message on="saved">Saved.</x-action-message>
        </div>
    </form>

    <flux:callout icon="light-bulb" color="sky">
        <flux:callout.heading>Making the videos</flux:callout.heading>
        <flux:callout.text>
            Record your screen on a phone or computer (Windows: Xbox Game Bar with Win + Alt + R; Mac: Shift + Cmd + 5; phones: screen recorder),
            talk through each step, and upload to YouTube as “Unlisted”. One or two minutes per topic is best. You can also add the same videos to
            lessons in Admin → Courses.
        </flux:callout.text>
    </flux:callout>
</div>
