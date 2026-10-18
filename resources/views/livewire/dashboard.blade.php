<?php

use App\Enums\PostStatus;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $user = auth()->user();
        $content = $user->contentItems();

        return [
            'stats' => [
                ['label' => 'Connected accounts', 'value' => $user->socialAccounts()->count(), 'icon' => 'link'],
                ['label' => 'Scheduled posts', 'value' => $user->posts()->where('status', PostStatus::Scheduled)->count(), 'icon' => 'calendar-days'],
                ['label' => 'Total views', 'value' => (int) $content->clone()->sum('views'), 'icon' => 'eye'],
                ['label' => 'Total likes', 'value' => (int) $content->clone()->sum('likes'), 'icon' => 'heart'],
                ['label' => 'Total comments', 'value' => (int) $content->clone()->sum('comments_count'), 'icon' => 'chat-bubble-left'],
                ['label' => 'Unread comments', 'value' => $user->comments()->unread()->count(), 'icon' => 'inbox'],
            ],
            'platforms' => $user->socialAccounts()
                ->withCount('contentItems')
                ->withSum('contentItems as views', 'views')
                ->withSum('contentItems as likes', 'likes')
                ->get(),
            'upcoming' => $user->posts()->with('targets.account')
                ->where('status', PostStatus::Scheduled)
                ->orderBy('scheduled_at')->limit(5)->get(),
            'recentComments' => $user->comments()->with('account', 'contentItem')
                ->where('is_own', false)
                ->latest('commented_at')->limit(6)->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Welcome back, {{ auth()->user()->name }}</flux:heading>
            <flux:text>Everything across your social accounts in one place.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('compose')" wire:navigate>Create post</flux:button>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($stats as $stat)
            <div class="min-w-0 rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                <div class="flex min-w-0 items-center gap-2 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400">
                    <flux:icon :name="$stat['icon']" variant="mini" />
                    {{ $stat['label'] }}
                </div>
                <div class="mt-1 truncate text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ \App\Support\Metric::short($stat['value']) }}</div>
            </div>
        @endforeach
    </div>

    @if ($platforms->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:heading size="lg">Connect your first social account</flux:heading>
            <flux:text class="mt-2">Link YouTube, Facebook, Instagram, TikTok, LinkedIn or X to start posting and see all your content here.</flux:text>
            <flux:button class="mt-4" variant="primary" :href="route('accounts')" wire:navigate>Connect accounts</flux:button>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg" class="mb-4">Accounts</flux:heading>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($platforms as $account)
                        <div class="flex items-center gap-3 py-3">
                            <x-avatar size="sm" :src="$account->avatar_url" :name="$account->name" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $account->name }}</div>
                                <x-platform-badge :platform="$account->platform" />
                            </div>
                            <div class="text-right text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                                @if ($account->last_error)
                                    <a href="{{ route('accounts') }}" wire:navigate class="text-red-600 hover:underline dark:text-red-400">Needs attention</a>
                                @elseif (! $account->last_synced_at)
                                    <div class="flex items-center gap-1"><flux:icon.loading variant="micro" /> Importing…</div>
                                @else
                                    <div>{{ $account->content_items_count }} {{ Str::plural('post', $account->content_items_count) }}</div>
                                    <div>{{ \App\Support\Metric::short((int) $account->views) }} views · {{ \App\Support\Metric::short((int) $account->likes) }} likes</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading size="lg">Latest comments</flux:heading>
                    <flux:link :href="route('inbox')" wire:navigate>Open inbox</flux:link>
                </div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($recentComments as $comment)
                        <div class="flex gap-3 py-3">
                            <x-avatar size="xs" :src="$comment->author_avatar_url" :name="$comment->author_name ?? '?'" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium">{{ $comment->author_name }}</span>
                                    <x-platform-badge :platform="$comment->account->platform" />
                                    @unless ($comment->read_at)
                                        <span class="size-2 rounded-full bg-blue-500"></span>
                                    @endunless
                                </div>
                                <p class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $comment->body }}</p>
                            </div>
                        </div>
                    @empty
                        <flux:text>No comments yet.</flux:text>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">Coming up</flux:heading>
                <flux:link :href="route('posts')" wire:navigate>All posts</flux:link>
            </div>
            @forelse ($upcoming as $post)
                <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 py-3 last:border-0 dark:border-zinc-700">
                    <div class="w-44 text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ auth()->user()->localTime($post->scheduled_at) }}</div>
                    <div class="min-w-0 flex-1 truncate">{{ $post->title ?: Str::limit($post->caption, 80) }}</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($post->targets as $target)
                            <x-platform-badge :platform="$target->account->platform" />
                        @endforeach
                    </div>
                </div>
            @empty
                <flux:text>Nothing scheduled.</flux:text>
            @endforelse
        </div>
    @endif
</div>
