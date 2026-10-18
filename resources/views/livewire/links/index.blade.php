<?php

use App\Enums\Platform;
use App\Models\ShortLink;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $url = '';
    public string $title = '';
    public string $place = '';
    public string $campaign = '';
    public ?int $qrFor = null;

    public function create(): void
    {
        $this->url = trim($this->url);
        if ($this->url !== '' && ! preg_match('#^https?://#i', $this->url)) {
            $this->url = 'https://'.$this->url;
        }

        $this->validate([
            'url' => ['required', 'url:http,https', 'max:2000'],
            'title' => ['nullable', 'string', 'max:120'],
            'place' => ['nullable', 'in:'.implode(',', array_keys(ShortLink::PLACES))],
            'campaign' => ['nullable', 'string', 'max:100'],
        ]);

        // Pointing a short link at another short link would loop.
        if (str_starts_with($this->url, url('/go/'))) {
            $this->addError('url', 'That is already a short link.');

            return;
        }

        if (auth()->user()->shortLinks()->count() >= 500) {
            $this->addError('url', 'You can have up to 500 links. Delete old ones to add more.');

            return;
        }

        [$source, $medium] = ShortLink::PLACES[$this->place] ?? [null, null];

        $link = auth()->user()->shortLinks()->create([
            'url' => $this->url,
            'title' => $this->title ?: (parse_url($this->url, PHP_URL_HOST) ?: null),
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $this->campaign ? Str::slug($this->campaign) : null,
        ]);

        $this->reset('url', 'title', 'campaign');
        $this->qrFor = $link->id;
        $this->resetPage();
    }

    public function showQr(int $id): void
    {
        $this->qrFor = auth()->user()->shortLinks()->findOrFail($id)->id;
    }

    public function delete(int $id): void
    {
        auth()->user()->shortLinks()->findOrFail($id)->delete();
        $this->qrFor === $id && $this->qrFor = null;
    }

    public function with(): array
    {
        $user = auth()->user();
        $links = $user->shortLinks()->latest()->paginate(20);
        $since = now()->subDays(13)->startOfDay();

        // Clicks per day for the last 14 days, for the little charts.
        $daily = \App\Models\ShortLinkClick::whereIn('short_link_id', $links->pluck('id'))->where('date', '>=', $since)->get()
            ->groupBy('short_link_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->date->toDateString())->map->clicks);

        return [
            'links' => $links,
            'daily' => $daily,
            'days' => collect(range(13, 0))->map(fn ($n) => now()->subDays($n)->toDateString()),
            'totalClicks' => (int) $user->shortLinks()->sum('clicks'),
            'qrLink' => $this->qrFor ? $user->shortLinks()->find($this->qrFor) : null,
            'pageUrl' => $user->publicProfile?->is_public ? $user->publicProfile->url() : null,
            'hasReviewLink' => (bool) ($user->businessProfile?->reviewUrl()
                ?? $user->socialAccounts()->where('platform', Platform::GoogleBusiness)->get()->pluck('meta.review_url')->filter()->first()),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Links &amp; QR codes</flux:heading>
        <flux:text>Short links that count every click, so you know which post, bio or flyer brings people. Each one has a QR code to print.</flux:text>
        <x-help-video topic="links_qr" class="mt-2" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="flex min-w-0 flex-col gap-6">
            <form wire:submit="create" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">Make a short link</flux:heading>
                <flux:input wire:model="url" label="Link to" placeholder="https://your-shop.com/product or wa.me/2567…" required />
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="title" label="Name (for you)" placeholder="December offer" />
                    <flux:select wire:model="place" label="Where you’ll share it">
                        <option value="">Anywhere</option>
                        @foreach (array_keys(ShortLink::PLACES) as $label)
                            <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="campaign" label="Campaign (optional)" placeholder="december-sale" />
                </div>
                <flux:text class="text-xs">“Where” and “Campaign” are added as UTM tags, so Google Analytics on your website shows where visitors came from.</flux:text>
                <div><flux:button type="submit" variant="primary" icon="link">Create short link</flux:button></div>
            </form>

            <section class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Your links</flux:heading>
                    <flux:text class="text-sm">{{ number_format($totalClicks) }} clicks in total</flux:text>
                </div>
                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($links as $link)
                        @php($series = $days->map(fn ($d) => (int) ($daily[$link->id][$d] ?? 0)))
                        @php($max = max(1, $series->max()))
                        <div wire:key="l-{{ $link->id }}" class="flex flex-wrap items-center gap-3 p-4">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $link->title ?: $link->url }}</div>
                                <div x-data="{ copied: false }" class="flex items-center gap-2 text-sm">
                                    <a href="{{ $link->shortUrl() }}" target="_blank" rel="noopener" class="truncate text-indigo-600 hover:underline dark:text-indigo-400">{{ preg_replace('#^https?://#', '', $link->shortUrl()) }}</a>
                                    <button type="button" class="text-xs text-zinc-500 hover:text-zinc-800" x-on:click="navigator.clipboard.writeText(@js($link->shortUrl())); copied = true; setTimeout(() => copied = false, 1500)">
                                        <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied!</span>
                                    </button>
                                </div>
                                <div class="truncate text-xs text-zinc-500" title="{{ $link->destination() }}">→ {{ $link->url }}@if ($link->utm_source) · {{ $link->utm_source }}@endif</div>
                            </div>
                            <div class="flex h-8 items-end gap-0.5" title="Clicks per day, last 14 days" aria-hidden="true">
                                @foreach ($series as $n)
                                    <span class="w-1.5 rounded-sm {{ $n ? 'bg-indigo-500' : 'bg-zinc-200 dark:bg-zinc-700' }}" style="height: {{ max(8, (int) round(100 * $n / $max)) }}%"></span>
                                @endforeach
                            </div>
                            <div class="w-16 text-right">
                                <div class="text-lg font-semibold tabular-nums">{{ number_format($link->clicks) }}</div>
                                <div class="text-xs text-zinc-500">clicks</div>
                            </div>
                            <div class="flex gap-1">
                                <flux:button size="sm" icon="qr-code" wire:click="showQr({{ $link->id }})" aria-label="QR code" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $link->id }})" wire:confirm="Delete this link? People who still use it will see “not found”." aria-label="Delete" />
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-sm text-zinc-500">No links yet. Make one above, then share it in your bio, posts, WhatsApp status or on a printed flyer.</div>
                    @endforelse
                </div>
                {{ $links->links() }}
            </section>
        </div>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
            @if ($qrLink)
                <div class="flex flex-col items-center gap-3 rounded-xl border border-zinc-200 p-5 text-center dark:border-zinc-700">
                    <flux:heading>QR code: {{ $qrLink->title }}</flux:heading>
                    <img src="{{ route('qr', ['type' => 'link', 'link' => $qrLink->id]) }}" alt="QR code for {{ $qrLink->shortUrl() }}" class="w-48 rounded-lg bg-white p-2">
                    <flux:button size="sm" icon="arrow-down-tray" :href="route('qr', ['type' => 'link', 'link' => $qrLink->id, 'download' => 1])">Download (SVG, prints sharp)</flux:button>
                    <flux:text class="text-xs">Scans are counted as clicks.</flux:text>
                </div>
            @endif

            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Ready-made QR codes</flux:heading>
                <flux:text class="text-sm">Print them on flyers, receipts, menus, posters and your shop window.</flux:text>
                @if ($pageUrl)
                    <div class="flex items-center gap-3">
                        <img src="{{ route('qr', ['type' => 'page']) }}" alt="QR code for your public page" class="size-16 rounded bg-white p-1">
                        <div class="text-sm"><div class="font-medium">Your public page</div><a href="{{ route('qr', ['type' => 'page', 'download' => 1]) }}" class="text-xs underline">Download</a></div>
                    </div>
                @else
                    <flux:text class="text-sm">Turn on your <a href="{{ route('grow.page') }}" wire:navigate class="underline">public page</a> to get its QR code.</flux:text>
                @endif
                @if ($hasReviewLink)
                    <div class="flex items-center gap-3">
                        <img src="{{ route('qr', ['type' => 'review']) }}" alt="QR code for your Google review link" class="size-16 rounded bg-white p-1">
                        <div class="text-sm"><div class="font-medium">“Review us on Google”</div><a href="{{ route('qr', ['type' => 'review', 'download' => 1]) }}" class="text-xs underline">Download</a></div>
                    </div>
                @else
                    <flux:text class="text-sm">Add your Place ID in <a href="{{ route('grow.business') }}" wire:navigate class="underline">Business Profile</a> to get a Google review QR code: the easiest way to get more reviews.</flux:text>
                @endif
            </div>
        </aside>
    </div>
</div>
