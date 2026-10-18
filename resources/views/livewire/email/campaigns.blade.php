<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /** Ready-made starting points: [label, subject, body]. */
    public const TEMPLATES = [
        'news' => ['Monthly news', 'What’s new at {sender} this month', "Hi {first_name},\n\nHere’s what’s new this month:\n\n## Something new\nTell people about a new product, service or result.\n\n## A tip for you\nShare one useful tip your customers will love.\n\n## This month’s offer\nGive a reason to act now, with a clear deadline.\n\n[Book or order now](https://)\n\nThanks for being part of our community!"],
        'offer' => ['Special offer', '{first_name}, 20% off this week only', "Hi {first_name},\n\nAs a thank-you for being on our list, you get **20% off** until Sunday.\n\nJust show this email or use the code **THANKYOU20**.\n\n[Claim your offer](https://)\n\nSee you soon!"],
        'video' => ['New video', 'New video: (your video title)', "Hi {first_name},\n\nI just posted a new video you might like:\n\n**(Your video title)**\n\nIn it I show (what people will learn or see).\n\n[Watch it here](https://)\n\nReply and tell me what you think!"],
        'blank' => ['Blank', '', ''],
    ];

    public function create(string $template = 'blank'): void
    {
        [, $subject, $body] = self::TEMPLATES[$template] ?? self::TEMPLATES['blank'];
        $sender = auth()->user()->mailbox?->fromName() ?? auth()->user()->name;

        $campaign = auth()->user()->emailCampaigns()->create([
            'subject' => str_replace('{sender}', $sender, $subject),
            'body' => $body,
        ]);

        $this->redirectRoute('email.campaign', $campaign, navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();
        $sent = $user->emailCampaigns()->where('sent', '>', 0);

        return [
            'campaigns' => $user->emailCampaigns()->latest()->paginate(15),
            'subscribers' => $user->contacts()->subscribed()->count(),
            'hasMailbox' => $user->mailbox !== null,
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
        @foreach ([['Subscribers', number_format($subscribers), 'users'], ['Campaigns', number_format($campaigns->total()), 'paper-airplane'], ['Emails sent', number_format($totals['sent']), 'envelope'], ['Open rate', $totals['sent'] ? round(100 * $totals['opens'] / $totals['sent']).'%' : '—', 'eye']] as [$label, $value, $icon])
            <div class="min-w-0 rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-xs text-zinc-500 sm:text-sm dark:text-zinc-400"><flux:icon :name="$icon" variant="mini" /> {{ $label }}</div>
                <div class="mt-1 truncate text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">Start a new campaign</flux:heading>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($this::TEMPLATES as $key => [$label])
                <button type="button" wire:click="create('{{ $key }}')" class="flex flex-col items-start gap-1 rounded-xl border border-zinc-200 p-4 text-left hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                    <flux:icon :name="$key === 'blank' ? 'document' : 'document-text'" class="size-5 text-zinc-500" />
                    <span class="text-sm font-medium">{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">Your campaigns</flux:heading>
        <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($campaigns as $campaign)
                <a href="{{ route('email.campaign', $campaign) }}" wire:navigate wire:key="ec-{{ $campaign->id }}" class="flex flex-wrap items-center gap-3 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-900">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $campaign->subject ?: 'Untitled campaign' }}</div>
                        <div class="text-xs text-zinc-500">
                            @if ($campaign->status === 'scheduled')
                                Scheduled for {{ auth()->user()->localTime($campaign->scheduled_at) }}
                            @elseif ($campaign->started_at)
                                Sent {{ auth()->user()->localTime($campaign->started_at, 'M j, Y') }} · {{ number_format($campaign->sent) }}/{{ number_format($campaign->recipients) }} delivered
                                @if ($campaign->openRate() !== null) · {{ $campaign->openRate() }}% opened @endif
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
