<?php

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Services\Social\SocialManager;
use Livewire\Volt\Component;

new class extends Component {
    public function sync(int $id): void
    {
        SyncSocialAccount::dispatch(auth()->user()->socialAccounts()->findOrFail($id));

        session()->flash('status', 'Sync started. New content and comments will appear shortly.');
        $this->redirectRoute('accounts', navigate: true);
    }

    public function disconnect(int $id): void
    {
        $account = auth()->user()->socialAccounts()->findOrFail($id);
        $account->delete();

        session()->flash('status', "Disconnected {$account->name}.");
        $this->redirectRoute('accounts', navigate: true);
    }

    public function with(): array
    {
        $social = app(SocialManager::class);

        return [
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => [
                'platform' => $p,
                'configured' => $social->driver($p)->configured(),
            ]),
            'accounts' => auth()->user()->socialAccounts()->withCount('contentItems')->orderBy('platform')->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl">Connected accounts</flux:heading>
        <flux:text>Connect a platform once; you can then post to it, see its content and answer its comments here.</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($platforms as ['platform' => $platform, 'configured' => $configured])
            <div class="flex flex-col rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ $platform->label() }}</flux:heading>
                    <x-platform-badge :platform="$platform" />
                </div>
                <flux:text class="mt-2 flex-1 text-sm">{{ $platform->description() }}</flux:text>
                <div class="mt-4">
                    @if ($configured)
                        <flux:button size="sm" icon="plus" :href="route('accounts.connect', $platform)">Connect {{ $platform->label() }}</flux:button>
                    @else
                        <flux:button size="sm" disabled>Not set up by admin yet</flux:button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Your accounts</flux:heading>

        @if ($accounts->isEmpty())
            <flux:text>No accounts connected yet.</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">Platform</th>
                            <th class="px-4 py-3 font-medium">Content</th>
                            <th class="px-4 py-3 font-medium">Last synced</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($accounts as $account)
                            <tr wire:key="account-{{ $account->id }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <flux:avatar size="sm" :src="$account->avatar_url" :name="$account->name" />
                                        <div>
                                            <div class="font-medium">{{ $account->name }}</div>
                                            @if ($account->username)
                                                <div class="text-zinc-500">{{ $account->username }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($account->last_error)
                                        <div class="mt-2 max-w-md text-xs text-red-600 dark:text-red-400">{{ $account->last_error }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3"><x-platform-badge :platform="$account->platform" /></td>
                                <td class="px-4 py-3 tabular-nums">{{ $account->content_items_count }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ $account->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" icon="arrow-path" wire:click="sync({{ $account->id }})">Sync</flux:button>
                                        <flux:button size="sm" variant="danger" wire:click="disconnect({{ $account->id }})"
                                            wire:confirm="Disconnect {{ $account->name }}? Its imported content and comments will be removed from this app (nothing is deleted on {{ $account->platform->label() }}).">
                                            Disconnect
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
