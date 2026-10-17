<div class="divide-y divide-zinc-200 dark:divide-zinc-700">
    @forelse ($lessons as $lesson)
        <div wire:key="lesson-{{ $lesson->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
            @if ($lesson->isVideo())
                <img src="{{ $lesson->thumbnailUrl() }}" alt="" class="h-10 w-16 shrink-0 rounded object-cover" referrerpolicy="no-referrer" loading="lazy">
            @else
                <span class="flex h-10 w-16 shrink-0 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-800"><flux:icon name="document-text" class="size-5 text-zinc-400" /></span>
            @endif
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium">{{ $lesson->title }}</div>
                <div class="flex flex-wrap gap-2 text-xs text-zinc-500">
                    <span>{{ $lesson->isVideo() ? 'Video' : 'Reading' }}{{ $lesson->isVideo() && filled($lesson->content) ? ' + notes' : '' }}</span>
                    @if ($lesson->duration_minutes)<span>· {{ $lesson->duration_minutes }} min</span>@endif
                    @if ($lesson->is_preview)<flux:badge size="sm" color="green">Free preview</flux:badge>@endif
                </div>
            </div>
            <div class="flex shrink-0 gap-1">
                <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveLesson({{ $lesson->id }}, -1)" aria-label="Move up" />
                <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveLesson({{ $lesson->id }}, 1)" aria-label="Move down" />
                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="editLesson({{ $lesson->id }})">Edit</flux:button>
                <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteLesson({{ $lesson->id }})" wire:confirm="Delete “{{ $lesson->title }}”? Student progress on it is removed too." />
            </div>
        </div>
    @empty
        <div class="px-4 py-6 text-center text-sm text-zinc-500">No lessons here yet.</div>
    @endforelse
</div>
