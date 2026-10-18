<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[\Livewire\Attributes\Url]
    public string $kind = 'email';

    public function create(string $template = 'blank'): void
    {
        $user = auth()->user();
        $sender = $user->mailbox?->fromName() ?? $user->smsAccount?->sender_id ?? $user->publicProfile?->name() ?? $user->name;
        $campaign = $user->emailCampaigns()->create(\App\Support\CampaignTemplates::make($template, $sender));

        $this->redirectRoute('email.campaign', $campaign, navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();
        $sent = $user->emailCampaigns()->where('sent', '>', 0);

        return [
            'campaigns' => $user->emailCampaigns()->latest()->paginate(15),
            'subscribers' => $user->contacts()->subscribed()->count(),
            'smsSubscribers' => $user->contacts()->smsSubscribed()->count(),
            'hasMailbox' => $user->mailbox !== null,
            'hasSms' => \App\Services\Sms\EgoSms::accountFor($user) !== null,
            'totals' => [
                'sent' => (int) (clone $sent)->sum('sent'),
                'opens' => (int) (clone $sent)->sum('opens'),
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.email-tabs')

    @unless ($hasMailbox)
        <flux:callout icon="envelope" color="sky">
            <flux:callout.heading>Connect your mailbox to start sending</flux:callout.heading>
            <flux:callout.text>Use your Gmail with an app password. It takes two minutes. <flux:callout.link :href="route('email.settings')" wire:navigate>Connect now</flux:callout.link></flux:callout.text>
        </flux:callout>
    @endunless

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ([['Email subscribers', number_format($subscribers), 'envelope'], ['SMS subscribers', number_format($smsSubscribers), 'device-phone-mobile'], ['Messages sent', number_format($totals['sent']), 'paper-airplane'], ['Email open rate', $totals['sent'] ? round(100 * $totals['opens'] / $totals['sent']).'%' : '—', 'eye']] as [$label, $value, $icon])
            <div class="min-w-0 rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400"><flux:icon :name="$icon" variant="mini" /> {{ $label }}</div>
                <div class="mt-1 truncate text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">Start a new campaign</flux:heading>
            <flux:radio.group wire:model.live="kind" variant="segmented" size="sm">
                <flux:radio value="email" label="Email" icon="envelope" />
                <flux:radio value="sms" label="SMS" icon="device-phone-mobile" />
            </flux:radio.group>
        </div>
        @if ($kind === 'sms' && ! $hasSms)
            <flux:text class="text-sm text-amber-600">SMS isn’t set up yet. <a href="{{ route('email.settings') }}" wire:navigate class="underline">Connect EgoSMS</a></flux:text>
        @endif
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-5">
            @foreach ($kind === 'sms' ? \App\Support\CampaignTemplates::SMS : \App\Support\CampaignTemplates::EMAIL as $key => $template)
                <button type="button" wire:key="t-{{ $key }}" wire:click="create('{{ $key }}')" class="flex flex-col items-start gap-1 rounded-xl border border-zinc-200 p-4 text-left hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                    <flux:icon :name="$template[1]" class="size-5 text-zinc-500" />
                    <span class="text-sm font-medium">{{ $template[0] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">Your campaigns</flux:heading>
        <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($campaigns as $campaign)
                <a href="{{ route('email.campaign', $campaign) }}" wire:navigate wire:key="ec-{{ $campaign->id }}" class="flex flex-wrap items-center gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-900">
                    <flux:icon :name="$campaign->isSms() ? 'device-phone-mobile' : 'envelope'" variant="mini" class="text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $campaign->subject ?: ($campaign->isSms() ? \Illuminate\Support\Str::limit($campaign->body, 60) : '') ?: 'Untitled campaign' }}</div>
                        <div class="text-xs text-zinc-500">
                            @if ($campaign->status === 'scheduled')
                                Scheduled for {{ auth()->user()->localTime($campaign->scheduled_at) }}
                            @elseif ($campaign->started_at)
                                Sent {{ auth()->user()->localTime($campaign->started_at, 'M j, Y') }} · {{ number_format($campaign->sent) }}/{{ number_format($campaign->recipients) }} delivered
                                @if (! $campaign->isSms() && $campaign->openRate() !== null) · {{ $campaign->openRate() }}% opened @endif
                            @else
                                Edited {{ $campaign->updated_at->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <flux:badge size="sm" :color="$campaign->statusColor()" class="capitalize">{{ $campaign->status }}</flux:badge>
                </a>
            @empty
                <div class="p-8 text-center text-sm text-zinc-500">No campaigns yet. Pick a template above to write your first one.</div>
            @endforelse
        </div>
        {{ $campaigns->links() }}
    </section>
</div>
