<?php

use App\Enums\Platform;
use App\Services\Social\Contracts\PlatformDriver;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use App\Models\Comment;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $filter = 'unread';

    #[Url]
    public string $platform = '';

    #[Url]
    public ?int $item = null;

    public ?int $replyingTo = null;
    public string $reply = '';

    public function updated($property): void
    {
        if (in_array($property, ['filter', 'platform', 'item'])) {
            $this->resetPage();
        }
    }

    public function markRead(int $id): void
    {
        auth()->user()->comments()->findOrFail($id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        Comment::whereIn('id', $this->query()->whereNull('comments.read_at')->pluck('comments.id'))
            ->update(['read_at' => now()]);
    }

    public function refresh(): void
    {
        auth()->user()->socialAccounts->each(function ($account) {
            if ($account->driver()->supports(PlatformDriver::COMMENTS)) {
                \App\Jobs\SyncSocialAccount::dispatch($account);
            }
        });

        session()->flash('status', 'Checking all platforms for new comments…');
        $this->redirectRoute('inbox', navigate: true);
    }

    public function startReply(int $id): void
    {
        $this->replyingTo = $id;
        $this->reply = '';
        $this->resetErrorBag();
    }

    public function sendReply(): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:2000']]);

        $comment = auth()->user()->comments()->with('account', 'contentItem')->findOrFail($this->replyingTo);

        try {
            $data = $comment->account->driver()->reply($comment->account, $comment, $this->reply);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('reply', $e->getMessage());

            return;
        }

        $comment->contentItem->comments()->updateOrCreate(
            ['platform_comment_id' => $data->platformCommentId],
            ['social_account_id' => $comment->social_account_id, 'read_at' => now(), ...$data->toAttributes()],
        );

        $comment->update(['read_at' => now()]);
        $this->reset('replyingTo', 'reply');
    }

    private function query()
    {
        return auth()->user()->comments()
            ->where('comments.is_own', false)
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('comments.read_at'))
            ->when($this->platform, fn ($q) => $q->where('social_accounts.platform', $this->platform))
            ->when($this->item, fn ($q) => $q->where('comments.content_item_id', $this->item));
    }

    public function with(): array
    {
        $comments = $this->query()
            ->with('account', 'contentItem')
            ->latest('comments.commented_at')
            ->paginate(20);

        // Our own replies, shown under each comment.
        $replies = auth()->user()->comments()
            ->where('comments.is_own', true)
            ->whereIn('comments.parent_platform_comment_id', $comments->pluck('platform_comment_id'))
            ->get()
            ->groupBy('parent_platform_comment_id');

        return [
            'comments' => $comments,
            'replies' => $replies,
            'filteredItem' => $this->item ? auth()->user()->contentItems()->find($this->item) : null,
            'unreadCount' => auth()->user()->comments()->unread()->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Inbox</flux:heading>
            <flux:text>Comments from every platform in one feed. {{ $unreadCount }} unread.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button icon="arrow-path" wire:click="refresh">Refresh</flux:button>
            <flux:button icon="check" wire:click="markAllRead">Mark all as read</flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:radio.group wire:model.live="filter" variant="segmented">
            <flux:radio value="unread" label="Unread" />
            <flux:radio value="all" label="All" />
        </flux:radio.group>
        <flux:select wire:model.live="platform" class="w-44">
            <option value="">All platforms</option>
            @foreach (Platform::cases() as $p)
                <option value="{{ $p->value }}">{{ $p->label() }}</option>
            @endforeach
        </flux:select>
        @if ($filteredItem)
            <flux:badge>
                On: {{ Str::limit($filteredItem->title ?: $filteredItem->caption, 40) }}
                <flux:badge.close wire:click="$set('item', null)" />
            </flux:badge>
        @endif
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($comments as $comment)
            <div wire:key="comment-{{ $comment->id }}" @class([
                'rounded-xl border p-4',
                'border-blue-300 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20' => ! $comment->read_at,
                'border-zinc-200 dark:border-zinc-700' => $comment->read_at,
            ])>
                <div class="flex gap-3">
                    <flux:avatar size="sm" :src="$comment->author_avatar_url" :name="$comment->author_name ?? '?'" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium">{{ $comment->author_name ?? 'Unknown' }}</span>
                            @if ($comment->author_handle && $comment->author_handle !== $comment->author_name)
                                <span class="text-zinc-500">{{ $comment->author_handle }}</span>
                            @endif
                            <x-platform-badge :platform="$comment->account->platform" />
                            <span class="text-zinc-500">· {{ $comment->commented_at?->diffForHumans() }}</span>
                        </div>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $comment->body }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500">
                            <span class="flex items-center gap-1"><flux:icon name="heart" variant="micro" /> {{ $comment->likes }}</span>
                            <span class="truncate">
                                on <a href="{{ $comment->contentItem->permalink }}" target="_blank" rel="noopener" class="underline">{{ Str::limit($comment->contentItem->title ?: $comment->contentItem->caption ?: 'post', 50) }}</a>
                                · {{ $comment->account->name }}
                            </span>
                        </div>

                        @foreach ($replies->get($comment->platform_comment_id, []) as $own)
                            <div class="mt-3 rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-800">
                                <span class="font-medium">You:</span> {{ $own->body }}
                            </div>
                        @endforeach

                        @if ($replyingTo === $comment->id)
                            <form wire:submit="sendReply" class="mt-3 flex flex-col gap-2">
                                <flux:textarea wire:model="reply" rows="2" placeholder="Write a reply…" autofocus />
                                <flux:error name="reply" />
                                <div class="flex gap-2">
                                    <flux:button size="sm" type="submit" variant="primary" wire:loading.attr="disabled" wire:target="sendReply">Send reply</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="$set('replyingTo', null)">Cancel</flux:button>
                                </div>
                            </form>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col gap-2">
                        @if ($comment->account->driver()->supports(PlatformDriver::REPLY) && $replyingTo !== $comment->id)
                            <flux:button size="sm" icon="arrow-uturn-left" wire:click="startReply({{ $comment->id }})">Reply</flux:button>
                        @endif
                        @unless ($comment->read_at)
                            <flux:button size="sm" variant="ghost" icon="check" wire:click="markRead({{ $comment->id }})">Read</flux:button>
                        @endunless
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>{{ $filter === 'unread' ? 'You’re all caught up.' : 'No comments yet.' }}</flux:text>
            </div>
        @endforelse
    </div>

    {{ $comments->links() }}
</div>
