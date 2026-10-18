<?php

use App\Services\Seo\SeoAuditor;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    public string $url = '';

    #[Url(as: 'audit')]
    public ?int $auditId = null;

    public function mount(): void
    {
        $this->url = (string) (auth()->user()->businessProfile?->website ?? '');
    }

    public function check(SeoAuditor $auditor): void
    {
        $this->validate(['url' => ['required', 'string', 'max:2000']], ['url.required' => 'Enter your website address.']);

        $key = 'seo-audit:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('url', 'You’ve run a lot of checks. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');

            return;
        }
        RateLimiter::hit($key, 3600);

        try {
            $result = $auditor->audit($this->url);
        } catch (\RuntimeException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }

        $audit = auth()->user()->seoAudits()->create([
            'url' => $result['url'],
            'score' => $result['score'],
            'results' => $result['checks'],
        ]);

        $this->auditId = $audit->id;
    }

    public function with(): array
    {
        $audits = auth()->user()->seoAudits()->latest()->limit(10)->get();

        return [
            'audit' => $this->auditId ? auth()->user()->seoAudits()->find($this->auditId) : $audits->first(),
            'history' => $audits,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.grow-tabs')

    <form wire:submit="check" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">Check a web page</flux:heading>
        <flux:text class="text-sm">We check the basics Google needs: title, description, headings, mobile setup, images, speed, sitemap and more, then tell you exactly what to fix.</flux:text>
        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
            <flux:input wire:model="url" placeholder="https://yourbusiness.com" class="flex-1" />
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled" wire:target="check">
                <span wire:loading.remove wire:target="check">Check my page</span>
                <span wire:loading wire:target="check">Checking…</span>
            </flux:button>
        </div>
        <flux:error name="url" />
    </form>

    @if ($audit)
        @php($checks = collect($audit->results))
        <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
            <div class="flex flex-col items-center gap-2 rounded-xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
                <div @class([
                    'flex size-28 items-center justify-center rounded-full border-8 text-3xl font-semibold tabular-nums',
                    'border-green-500 text-green-600' => $audit->score >= 80,
                    'border-amber-500 text-amber-600' => $audit->score >= 50 && $audit->score < 80,
                    'border-red-500 text-red-600' => $audit->score < 50,
                ])>{{ $audit->score }}</div>
                <div class="font-medium">SEO score</div>
                <div class="break-all text-xs text-zinc-500">{{ $audit->url }}</div>
                <div class="text-xs text-zinc-500">{{ $audit->created_at->diffForHumans() }}</div>
                <div class="mt-2 flex gap-3 text-xs">
                    <span class="text-green-600">{{ $checks->where('status', 'pass')->count() }} passed</span>
                    <span class="text-amber-600">{{ $checks->where('status', 'warn')->count() }} to improve</span>
                    <span class="text-red-600">{{ $checks->where('status', 'fail')->count() }} to fix</span>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($checks->sortBy(fn ($c) => ['fail' => 0, 'warn' => 1, 'pass' => 2][$c['status']]) as $check)
                    <div class="flex gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:icon :name="['pass' => 'check-circle', 'warn' => 'exclamation-triangle', 'fail' => 'x-circle'][$check['status']]" variant="solid"
                            @class(['size-5 shrink-0', 'text-green-500' => $check['status'] === 'pass', 'text-amber-500' => $check['status'] === 'warn', 'text-red-500' => $check['status'] === 'fail']) />
                        <div class="min-w-0">
                            <div class="font-medium">{{ $check['label'] }}</div>
                            <div class="break-words text-sm text-zinc-600 dark:text-zinc-400">{{ $check['detail'] }}</div>
                            @if ($check['fix'] && $check['status'] !== 'pass')
                                <div class="mt-1 text-sm"><span class="font-medium">How to fix:</span> {{ $check['fix'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:icon name="magnifying-glass" class="mx-auto size-10 text-zinc-300" />
            <flux:text class="mt-2">Enter your website above to get your SEO score and a list of fixes.</flux:text>
        </div>
    @endif

    @if ($history->count() > 1)
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading class="mb-3">Previous checks</flux:heading>
            <div class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @foreach ($history as $past)
                    <button type="button" wire:click="$set('auditId', {{ $past->id }})" class="flex w-full items-center gap-3 py-2 text-left hover:bg-zinc-50 dark:hover:bg-zinc-900">
                        <flux:badge size="sm" :color="$past->scoreColor()">{{ $past->score }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate">{{ $past->url }}</span>
                        <span class="text-xs text-zinc-500">{{ $past->created_at->diffForHumans() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
