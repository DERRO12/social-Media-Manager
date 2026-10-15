<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\ContentItem;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'stats' => [
                ['label' => 'Clients', 'value' => User::clients()->count()],
                ['label' => 'New this month', 'value' => User::clients()->where('created_at', '>=', now()->startOfMonth())->count()],
                ['label' => 'Suspended', 'value' => User::clients()->whereNotNull('suspended_at')->count()],
                ['label' => 'Connected accounts', 'value' => SocialAccount::count()],
                ['label' => 'Posts published', 'value' => Post::where('status', PostStatus::Published)->count()],
                ['label' => 'Scheduled posts', 'value' => Post::where('status', PostStatus::Scheduled)->count()],
            ],
            'byPlatform' => SocialAccount::selectRaw('platform, count(*) as total')->groupBy('platform')->pluck('total', 'platform'),
            'failures' => PostTarget::with('account.user', 'post')
                ->where('status', TargetStatus::Failed)
                ->latest('updated_at')->limit(10)->get(),
            'syncErrors' => SocialAccount::with('user')->whereNotNull('last_error')->latest('updated_at')->limit(10)->get(),
            'totalViews' => (int) ContentItem::sum('views'),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Admin overview</flux:heading>
            <flux:text>Every client on the platform. {{ \App\Support\Metric::short($totalViews) }} total views tracked.</flux:text>
        </div>
        <flux:button variant="primary" icon="users" :href="route('admin.clients')" wire:navigate>Manage clients</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($stats as $stat)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $stat['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold tabular-nums">{{ \App\Support\Metric::full($stat['value']) }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg" class="mb-4">Connected accounts by platform</flux:heading>
        <div class="flex flex-wrap gap-3">
            @foreach (Platform::cases() as $platform)
                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <x-platform-badge :platform="$platform" />
                    <span class="font-semibold tabular-nums">{{ $byPlatform[$platform->value] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg" class="mb-4">Recent failed posts</flux:heading>
            @forelse ($failures as $target)
                <div class="border-b border-zinc-200 py-3 text-sm last:border-0 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <x-platform-badge :platform="$target->account->platform" />
                        <span class="font-medium">{{ $target->account->user->name }}</span>
                        <span class="text-zinc-500">· {{ $target->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ Str::limit($target->error, 200) }}</div>
                </div>
            @empty
                <flux:text>No failures. 🎉</flux:text>
            @endforelse
        </div>

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg" class="mb-4">Accounts with sync problems</flux:heading>
            @forelse ($syncErrors as $account)
                <div class="border-b border-zinc-200 py-3 text-sm last:border-0 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <x-platform-badge :platform="$account->platform" />
                        <span class="font-medium">{{ $account->name }}</span>
                        <span class="text-zinc-500">· {{ $account->user->name }}</span>
                    </div>
                    <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ Str::limit($account->last_error, 200) }}</div>
                </div>
            @empty
                <flux:text>All accounts syncing fine.</flux:text>
            @endforelse
        </div>
    </div>
</div>
