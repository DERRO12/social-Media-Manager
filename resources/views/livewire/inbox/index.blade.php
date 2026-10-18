<?php

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Models\Comment;
use App\Services\Social\Contracts\PlatformDriver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
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

    /** Mark a whole conversation (the comment and its replies) as read. */
    public function markRead(int $id): void
    {
        $thread = auth()->user()->comments()->findOrFail($id);

        Comment::where('content_item_id', $thread->content_item_id)
            ->where(fn ($q) => $q->where('id', $thread->id)->orWhere('parent_platform_comment_id', $thread->platform_comment_id))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        Comment::whereIn('social_account_id', auth()->user()->socialAccounts()->pluck('id'))
            ->when($this->platform, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('platform', $this->platform)))
            ->when($this->item, fn ($q) => $q->where('content_item_id', $this->item))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Check the platforms right now instead of waiting for the next automatic sync. */
    public function refresh(): void
    {
        $failed = [];

        foreach (auth()->user()->socialAccounts as $account) {
            if (! $account->driver()->supports(PlatformDriver::COMMENTS)) {
                continue;
            }

            SyncSocialAccount::dispatchSync($account);

            if ($account->fresh()->last_error) {
                $failed[] = $account->name;
            }
        }

        session()->flash($failed ? 'error' : 'status', $failed
            ? 'Some accounts could not be checked: '.implode(', ', $failed).'. See Connected accounts.'
            : 'Up to date.');

        $this->redirectRoute('inbox', ['filter' => $this->filter, 'platform' => $this->platform ?: null, 'item' => $this->item], navigate: true);
    }

    public function startReply(int $id): void
    {
        $this->replyingTo = $id;
        $this->reply = '';
        $this->resetErrorBag();
    }

    /** Fill the reply box with an AI-written reply in the business's voice (they can edit it before sending). */
    public function suggestReply(\App\Services\Ai\Writer $writer): void
    {
        $comment = auth()->user()->comments()->with('contentItem')->findOrFail($this->replyingTo);

        try {
            $this->reply = $writer->reply(auth()->user(), $comment->body, $comment->is_own ? null : $comment->author_name, $comment->contentItem?->title);
            $this->resetErrorBag('reply');
        } catch (RuntimeException $e) {
            $this->addError('reply', $e->getMessage());
        }
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

        $this->markRead($comment->parent_platform_comment_id
            ? Comment::where('content_item_id', $comment->content_item_id)->where('platform_comment_id', $comment->parent_platform_comment_id)->value('id') ?? $comment->id
            : $comment->id);
        $this->reset('replyingTo', 'reply');
    }

    /** Conversations: top-level comments, newest activity (comment or latest reply) first. */
    private function threads()
    {
        $lastActivity = '(select max(r.commented_at) from comments r where r.content_item_id = comments.content_item_id and r.parent_platform_comment_id = comments.platform_comment_id)';
        $unreadReply = fn ($q) => $q->from('comments as u')
            ->whereColumn('u.content_item_id', 'comments.content_item_id')
            ->whereColumn('u.parent_platform_comment_id', 'comments.platform_comment_id')
            ->whereNull('u.read_at')
            ->where('u.is_own', false);

        return auth()->user()->comments()
            ->whereNull('comments.parent_platform_comment_id')
            ->when($this->filter === 'unread', fn ($q) => $q->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('comments.read_at')->where('comments.is_own', false))
                ->orWhereExists($unreadReply)))
            ->when($this->platform, fn ($q) => $q->where('social_accounts.platform', $this->platform))
            ->when($this->item, fn ($q) => $q->where('comments.content_item_id', $this->item))
            ->orderByDesc(DB::raw("coalesce({$lastActivity}, comments.commented_at)"));
    }

    public function with(): array
    {
        $user = auth()->user();
        $threads = $this->threads()->with('account', 'contentItem', 'replies')->paginate(15);

        return [
            'threads' => $threads,
            'aiEnabled' => app(\App\Services\Ai\Writer::class)->enabled(),
            'filteredItem' => $this->item ? $user->contentItems()->find($this->item) : null,
            'unreadCount' => $user->comments()->unread()->count(),
            'lastChecked' => $user->socialAccounts()->max('last_synced_at'),
            'syncMinutes' => config('app.sync_every_minutes'),
            'readOnly' => $user->socialAccounts->reject(fn ($a) => $a->driver()->supports(PlatformDriver::COMMENTS))->pluck('platform')->unique(),
        ];
    }
}; ?>

{{-- Re-reads the inbox every 20 seconds while it's open (paused while you're writing a reply). --}}
<div class="flex flex-col gap-6" @if (! $replyingTo) wire:poll.20s.visible @endif>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Inbox</flux:heading>
            <flux:text>
                Comments and replies from every platform. {{ $unreadCount }} unread.
                <span class="inline-flex items-center gap-1 text-xs">
                    <span class="size-2 animate-pulse rounded-full bg-green-500"></span>
                    Live · checked {{ $lastChecked ? \Illuminate\Support\Carbon::parse($lastChecked)->diffForHumans() : 'not yet' }}, automatically every {{ $syncMinutes }} min
                </span>
            </flux:text>
            <x-help-video topic="inbox" class="mt-2" />
        </div>
        <div class="flex gap-2">
            <flux:button icon="arrow-path" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                <span wire:loading.remove wire:target="refresh">Check now</span>
                <span wire:loading wire:target="refresh">Checking…</span>
            </flux:button>
            <flux:button icon="check" wire:click="markAllRead">Mark all as read</flux:button>
        </div>
    </div>

    @if ($readOnly->isNotEmpty())
        <flux:callout variant="secondary" icon="information-circle"
            :heading="'Comments from '.$readOnly->map->label()->implode(', ').' can’t be shown.'">
            <flux:callout.text>
                {{ $readOnly->contains(\App\Enums\Platform::X) ? 'X only allows reading replies on its paid API plans. ' : '' }}
                {{ $readOnly->contains(\App\Enums\Platform::TikTok) ? 'TikTok doesn’t share comment text with apps. ' : '' }}
                {{ $readOnly->contains(\App\Enums\Platform::LinkedIn) ? 'LinkedIn needs Community Management approval. ' : '' }}
                Their like and comment counts still update under All content where available.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:radio.group wire:model.live="filter" variant="segmented">
            <flux:radio value="unread" label="Unread" />
            <flux:radio value="all" label="All" />
        </flux:radio.group>
        <flux:select wire:model.live="platform" class="min-w-0 max-sm:flex-1 sm:w-44">
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
        @forelse ($threads as $thread)
            @php($canReply = $thread->account->driver()->supports(PlatformDriver::REPLY))
            @php($threadUnread = (! $thread->read_at && ! $thread->is_own) || $thread->replies->contains(fn ($r) => ! $r->read_at && ! $r->is_own))
            <div wire:key="thread-{{ $thread->id }}" @class([
                'rounded-xl border p-4',
                'border-blue-300 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/20' => $threadUnread,
                'border-zinc-200 dark:border-zinc-700' => ! $threadUnread,
            ])>
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                    <x-platform-badge :platform="$thread->account->platform" />
                    <span class="truncate">
                        on <a href="{{ $thread->contentItem->permalink }}" target="_blank" rel="noopener" class="underline">{{ Str::limit($thread->contentItem->title ?: $thread->contentItem->caption ?: 'post', 60) }}</a>
                        · {{ $thread->account->name }}
                    </span>
                    @if ($thread->replies->isNotEmpty())
                        <span>· {{ $thread->replies->count() }} {{ Str::plural('reply', $thread->replies->count()) }}</span>
                    @endif
                    @if ($threadUnread)
                        <flux:button size="xs" variant="ghost" icon="check" class="ms-auto" wire:click="markRead({{ $thread->id }})">Mark read</flux:button>
                    @endif
                </div>

                @include('partials.inbox-comment', ['comment' => $thread, 'canReply' => $canReply, 'isReply' => false])

                @if ($thread->replies->isNotEmpty())
                    <div class="ms-5 mt-3 flex flex-col gap-3 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
                        @foreach ($thread->replies as $reply)
                            @include('partials.inbox-comment', ['comment' => $reply, 'canReply' => $canReply, 'isReply' => true])
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>{{ $filter === 'unread' ? 'You’re all caught up.' : 'No comments yet.' }}</flux:text>
            </div>
        @endforelse
    </div>

    {{ $threads->links() }}
</div>
