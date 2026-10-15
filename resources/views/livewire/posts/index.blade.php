<?php

use App\Enums\PostStatus;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    public function retry(int $id): void
    {
        auth()->user()->posts()->findOrFail($id)->dispatchPublishing();
    }

    public function publishNow(int $id): void
    {
        $post = auth()->user()->posts()->findOrFail($id);
        $post->update(['scheduled_at' => now()]);
        $post->dispatchPublishing();
    }

    public function delete(int $id): void
    {
        $post = auth()->user()->posts()
            ->whereIn('status', [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed])
            ->findOrFail($id);

        $post->delete();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $publishing = auth()->user()->posts()->where('status', PostStatus::Publishing)->exists();

        return [
            'posts' => auth()->user()->posts()
                ->with('targets.account', 'media')
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->latest()
                ->paginate(15),
            'publishing' => $publishing,
        ];
    }
}; ?>

{{-- Refresh every few seconds while something is still publishing. --}}
<div class="flex flex-col gap-6" @if ($publishing) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Scheduled &amp; sent</flux:heading>
            <flux:text>Every post you created here and how it did on each platform.</flux:text>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="status" class="w-44">
                <option value="">All statuses</option>
                @foreach (PostStatus::cases() as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </flux:select>
            <flux:button variant="primary" icon="plus" :href="route('compose')" wire:navigate>Create post</flux:button>
        </div>
    </div>

    <div class="flex flex-col gap-4">
        @forelse ($posts as $post)
            <div wire:key="post-{{ $post->id }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$post->status->color()">{{ $post->status->label() }}</flux:badge>
                            @if ($post->media)
                                <flux:badge size="sm" icon="film">Video</flux:badge>
                            @endif
                        </div>
                        <div class="mt-2 font-medium">{{ $post->title ?: Str::limit($post->caption, 120) ?: 'Untitled' }}</div>
                        <div class="mt-1 text-sm text-zinc-500">
                            @if ($post->published_at)
                                Published {{ auth()->user()->localTime($post->published_at) }}
                            @elseif ($post->scheduled_at)
                                Scheduled for {{ auth()->user()->localTime($post->scheduled_at) }}
                            @else
                                Draft · created {{ $post->created_at->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <div class="flex gap-2">
                        @if (in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled]))
                            <flux:button size="sm" wire:click="publishNow({{ $post->id }})">Publish now</flux:button>
                        @endif
                        @if (in_array($post->status, [PostStatus::Failed, PostStatus::PartiallyFailed]))
                            <flux:button size="sm" icon="arrow-path" wire:click="retry({{ $post->id }})">Retry failed</flux:button>
                        @endif
                        @if (in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed]))
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $post->id }})" wire:confirm="Delete this post?" />
                        @endif
                    </div>
                </div>

                <div class="mt-4 flex flex-col gap-2">
                    @foreach ($post->targets as $target)
                        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-900">
                            <x-platform-badge :platform="$target->account->platform" />
                            <span class="font-medium">{{ $target->account->name }}</span>
                            <flux:badge size="sm" :color="$target->status->color()">{{ ucfirst($target->status->value) }}</flux:badge>
                            @if ($target->permalink)
                                <flux:link :href="$target->permalink" target="_blank" class="text-sm">View post</flux:link>
                            @endif
                            @if ($target->error)
                                <span class="w-full text-xs text-red-600 dark:text-red-400">{{ $target->error }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>No posts yet.</flux:text>
            </div>
        @endforelse
    </div>

    {{ $posts->links() }}
</div>
