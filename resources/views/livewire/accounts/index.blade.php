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
        $user = auth()->user();

        return [
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => [
                'platform' => $p,
                'configured' => $social->driver($p)->configured(),
            ]),
            'accounts' => $user->socialAccounts()->withCount('contentItems')->orderBy('platform')->get(),
            'slots' => $user->remainingAccountSlots(),
            'max' => $user->currentPlan()?->max_social_accounts,
        ];
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Connected accounts</flux:heading>
            <flux:text>Connect each social account once. You can then post to it, see its content and answer its comments here.</flux:text>
        </div>
        @if ($max !== null)
            <flux:badge :color="$slots === 0 ? 'red' : 'zinc'">{{ $accounts->count() }} / {{ $max }} accounts used</flux:badge>
        @endif
    </div>

    {{-- How it works --}}
    <div class="rounded-xl bg-zinc-50 p-5 dark:bg-zinc-900">
        <flux:heading class="mb-3">How connecting works</flux:heading>
        <ol class="grid gap-3 text-sm sm:grid-cols-3">
            <li class="flex gap-3">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-xs font-semibold text-white dark:bg-zinc-200 dark:text-zinc-900">1</span>
                <span>Click <strong>Connect</strong> on a platform below.</span>
            </li>
            <li class="flex gap-3">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-xs font-semibold text-white dark:bg-zinc-200 dark:text-zinc-900">2</span>
                <span>Log in <strong>on that platform’s own page</strong> and allow access. We never see or store your password.</span>
            </li>
            <li class="flex gap-3">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-xs font-semibold text-white dark:bg-zinc-200 dark:text-zinc-900">3</span>
                <span>You’re sent back here. Your posts, stats and comments start importing automatically.</span>
            </li>
        </ol>
        <flux:text class="mt-3 text-xs">You can disconnect at any time here, or remove access from the platform’s own security settings.</flux:text>
    </div>

    @if ($slots === 0)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="You’ve connected all the accounts your plan allows.">
            <x-slot name="actions">
                <flux:button size="sm" :href="route('billing')" wire:navigate>Upgrade plan</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($platforms as ['platform' => $platform, 'configured' => $configured])
            <div class="flex flex-col rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ $platform->label() }}</flux:heading>
                    <x-platform-badge :platform="$platform" />
                </div>
                <flux:text class="mt-2 text-sm">{{ $platform->description() }}</flux:text>
                <div class="mt-3 flex-1">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">You need</div>
                    <ul class="mt-1 list-disc space-y-1 ps-4 text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($platform->requirements() as $req)
                            <li>{{ $req }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="mt-4">
                    @if (! $configured)
                        <flux:button size="sm" disabled>Coming soon</flux:button>
                    @elseif ($slots === 0)
                        <flux:button size="sm" disabled>Plan limit reached</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" icon="plus" :href="route('accounts.connect', $platform)">Connect {{ $platform->label() }}</flux:button>
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
                                        <x-avatar size="sm" :src="$account->avatar_url" :name="$account->name" />
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
                                <td class="px-4 py-3 text-zinc-500">{{ $account->last_synced_at?->diffForHumans() ?? 'Importing…' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        @if ($account->last_error)
                                            {{-- Reconnecting refreshes the login and doesn't count against the plan limit. --}}
                                            <flux:button size="sm" variant="primary" icon="arrow-path-rounded-square" :href="route('accounts.connect', ['platform' => $account->platform, 'reconnect' => 1])">Reconnect</flux:button>
                                        @endif
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
