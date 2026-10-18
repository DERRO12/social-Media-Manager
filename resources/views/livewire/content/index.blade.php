<?php

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $platform = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = 'published_at';

    public function syncAll(): void
    {
        auth()->user()->socialAccounts->each(fn ($account) => SyncSocialAccount::dispatch($account));

        session()->flash('status', 'Refreshing content from all accounts…');
        $this->redirectRoute('content', navigate: true);
    }

    public function updated($property): void
    {
        if (in_array($property, ['platform', 'search', 'sort'])) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $sort = in_array($this->sort, ['published_at', 'views', 'likes', 'comments_count']) ? $this->sort : 'published_at';

        return [
            'items' => auth()->user()->contentItems()
                ->with('account')
                ->when($this->platform, fn ($q) => $q->where('social_accounts.platform', $this->platform))
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('content_items.title', 'like', "%{$this->search}%")
                    ->orWhere('content_items.caption', 'like', "%{$this->search}%")))
                ->orderByDesc("content_items.{$sort}")
                ->paginate(24),
        ];
    }
}; ?>

{{-- Re-reads views, likes and comment counts every minute while the page is open. --}}
<div class="flex flex-col gap-6" wire:poll.60s.visible>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">All content</flux:heading>
            <flux:text>Every video and post from your connected accounts, including ones you posted outside this app.</flux:text>
        </div>
        <flux:button icon="arrow-path" wire:click="syncAll">Refresh all</flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search captions…" class="w-full sm:max-w-xs" />
        <flux:select wire:model.live="platform" class="min-w-0 max-sm:flex-1 sm:w-44">
            <option value="">All platforms</option>
            @foreach (Platform::cases() as $p)
                <option value="{{ $p->value }}">{{ $p->label() }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="sort" class="min-w-0 max-sm:flex-1 sm:w-44">
            <option value="published_at">Newest</option>
            <option value="views">Most views</option>
            <option value="likes">Most likes</option>
            <option value="comments_count">Most comments</option>
        </flux:select>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($items as $item)
            <div wire:key="item-{{ $item->id }}" class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <a href="{{ $item->permalink }}" target="_blank" rel="noopener" class="block aspect-video bg-zinc-100 dark:bg-zinc-900">
                    @if ($item->thumbnail_url)
                        <img src="{{ $item->thumbnail_url }}" alt="" loading="lazy" class="size-full object-cover" referrerpolicy="no-referrer" />
                    @else
                        <div class="flex size-full items-center justify-center text-zinc-400">
                            <flux:icon name="{{ $item->type === 'post' ? 'document-text' : 'film' }}" class="size-10" />
                        </div>
                    @endif
                </a>
                <div class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <x-platform-badge :platform="$item->account->platform" />
                        <span class="text-xs text-zinc-500">{{ $item->published_at?->diffForHumans() }}</span>
                    </div>
                    <div class="line-clamp-2 text-sm font-medium">{{ $item->title ?: $item->caption ?: 'Untitled' }}</div>
                    <div class="truncate text-xs text-zinc-500">{{ $item->account->name }}</div>
                    <div class="mt-auto flex items-center gap-4 pt-2 text-sm tabular-nums text-zinc-600 dark:text-zinc-300">
                        @if (! is_null($item->views))
                            <span class="flex items-center gap-1" title="Views"><flux:icon name="eye" variant="micro" /> {{ \App\Support\Metric::short($item->views) }}</span>
                        @endif
                        <span class="flex items-center gap-1" title="Likes"><flux:icon name="heart" variant="micro" /> {{ \App\Support\Metric::short((int) $item->likes) }}</span>
                        <a href="{{ route('inbox', ['item' => $item->id, 'filter' => 'all']) }}" wire:navigate class="flex items-center gap-1 hover:underline" title="Comments">
                            <flux:icon name="chat-bubble-left" variant="micro" /> {{ \App\Support\Metric::short((int) $item->comments_count) }}
                        </a>
                        @if (! is_null($item->shares))
                            <span class="flex items-center gap-1" title="Shares"><flux:icon name="arrow-path-rounded-square" variant="micro" /> {{ \App\Support\Metric::short($item->shares) }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>No content yet. Connect an account or press “Refresh all”.</flux:text>
            </div>
        @endforelse
    </div>

    {{ $items->links() }}
</div>
