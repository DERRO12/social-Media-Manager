<?php

use App\Enums\PostStatus;
use App\Support\MarketingDates;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now(auth()->user()->timezone ?: 'UTC')->format('Y-m');
        }
    }

    public function go(int $step): void
    {
        $this->month = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->addMonths($step)->format('Y-m');
    }

    public function today(): void
    {
        $this->month = now(auth()->user()->timezone ?: 'UTC')->format('Y-m');
    }

    public function with(): array
    {
        $user = auth()->user();
        $tz = $user->timezone ?: 'UTC';
        $first = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->month.'-01 00:00', $tz);
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        // Each post sits on the day it was published, or is scheduled for, in the client's time zone.
        $posts = $user->posts()->with('targets.account')
            ->where(fn ($q) => $q->whereBetween('scheduled_at', [$start->utc(), $end->utc()])->orWhereBetween('published_at', [$start->utc(), $end->utc()]))
            ->get()
            ->groupBy(fn ($post) => ($post->published_at ?? $post->scheduled_at)->clone()->setTimezone($tz)->toDateString());

        $ideas = MarketingDates::forMonth($first->year, $first->month);
        $todayDate = now($tz)->toDateString();

        $days = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'day' => $day->day,
                'inMonth' => $day->month === $first->month,
                'isToday' => $date === $todayDate,
                'isPast' => $date < $todayDate,
                'posts' => $posts[$date] ?? collect(),
                'ideas' => $ideas[$date] ?? [],
            ];
        }

        return [
            'title' => $first->format('F Y'),
            'days' => $days,
            'agenda' => collect($days)->filter(fn ($d) => $d['inMonth'] && ($d['posts']->isNotEmpty() || $d['ideas'])),
            'ideas' => $ideas,
            'tz' => $tz,
            'scheduledCount' => $posts->flatten()->where('status', PostStatus::Scheduled)->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Content calendar</flux:heading>
            <flux:text>Your scheduled and published posts, plus days worth posting about. Click a day to plan a post.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('compose')" wire:navigate>Create post</flux:button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <flux:button size="sm" icon="chevron-left" wire:click="go(-1)" aria-label="Previous month" />
        <flux:button size="sm" icon="chevron-right" wire:click="go(1)" aria-label="Next month" />
        <flux:heading size="lg" class="ms-2">{{ $title }}</flux:heading>
        <flux:button size="sm" variant="ghost" wire:click="today">Today</flux:button>
        <flux:spacer />
        <flux:text class="text-sm">{{ $scheduledCount }} scheduled · times in {{ $tz }}</flux:text>
    </div>

    {{-- Month grid (tablet and up) --}}
    <div class="hidden overflow-hidden rounded-xl border border-zinc-200 sm:block dark:border-zinc-700">
        <div class="grid grid-cols-7 border-b border-zinc-200 bg-zinc-50 text-center text-xs font-medium uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                <div class="py-2">{{ $label }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($days as $d)
                <div wire:key="d-{{ $d['date'] }}" @class([
                    'group relative min-h-28 border-b border-r border-zinc-200 p-1.5 dark:border-zinc-700 [&:nth-child(7n)]:border-r-0',
                    'bg-zinc-50/60 dark:bg-zinc-900/40' => ! $d['inMonth'],
                ])>
                    <div class="flex items-center justify-between">
                        <span @class([
                            'flex size-6 items-center justify-center rounded-full text-xs',
                            'bg-zinc-900 font-semibold text-white dark:bg-white dark:text-zinc-900' => $d['isToday'],
                            'text-zinc-400' => ! $d['inMonth'],
                        ])>{{ $d['day'] }}</span>
                        @unless ($d['isPast'])
                            <a href="{{ route('compose', ['date' => $d['date']]) }}" wire:navigate class="rounded p-0.5 text-zinc-400 opacity-0 hover:bg-zinc-100 hover:text-zinc-700 group-hover:opacity-100 focus:opacity-100 dark:hover:bg-zinc-800" aria-label="Plan a post on {{ $d['date'] }}">
                                <flux:icon name="plus" variant="micro" />
                            </a>
                        @endunless
                    </div>
                    <div class="mt-1 flex flex-col gap-1">
                        @foreach ($d['ideas'] as $idea)
                            <div class="truncate rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-900 dark:bg-amber-900/30 dark:text-amber-100" title="{{ $idea['name'] }}: {{ $idea['idea'] }}">★ {{ $idea['name'] }}</div>
                        @endforeach
                        @foreach ($d['posts'] as $post)
                            <a href="{{ route('posts') }}" wire:navigate class="flex items-center gap-1 truncate rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700" title="{{ $post->status->label() }}: {{ $post->title ?: $post->caption }}">
                                <span class="flex shrink-0 gap-0.5">
                                    @foreach ($post->targets->pluck('account.platform')->filter()->unique() as $platform)
                                        <span @class(['size-1.5 rounded-full', [
                                            'red' => 'bg-red-500', 'blue' => 'bg-blue-500', 'pink' => 'bg-pink-500', 'sky' => 'bg-sky-500',
                                            'slate' => 'bg-slate-500', 'emerald' => 'bg-emerald-500',
                                        ][$platform->color()] ?? 'bg-zinc-500']) title="{{ $platform->label() }}"></span>
                                    @endforeach
                                </span>
                                <span class="shrink-0 text-zinc-500">{{ ($post->published_at ?? $post->scheduled_at)->clone()->setTimezone($tz)->format('H:i') }}</span>
                                <span class="truncate">{{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 40) ?: 'Post' }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Agenda (phones) --}}
    <div class="flex flex-col gap-3 sm:hidden">
        @forelse ($agenda as $d)
            <div wire:key="a-{{ $d['date'] }}" class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-medium">{{ \Carbon\Carbon::parse($d['date'])->format('D j M') }} @if ($d['isToday'])<flux:badge size="sm">Today</flux:badge>@endif</div>
                    @unless ($d['isPast'])
                        <flux:button size="xs" icon="plus" :href="route('compose', ['date' => $d['date']])" wire:navigate>Plan</flux:button>
                    @endunless
                </div>
                @foreach ($d['ideas'] as $idea)
                    <div class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-900/30 dark:text-amber-100"><strong>★ {{ $idea['name'] }}</strong>: {{ $idea['idea'] }}</div>
                @endforeach
                @foreach ($d['posts'] as $post)
                    <div class="mt-2 flex items-center gap-2 text-sm">
                        <flux:badge size="sm" :color="$post->status->color()">{{ $post->status->label() }}</flux:badge>
                        <span class="text-zinc-500">{{ ($post->published_at ?? $post->scheduled_at)->clone()->setTimezone($tz)->format('H:i') }}</span>
                        <span class="truncate">{{ $post->title ?: \Illuminate\Support\Str::limit($post->caption, 40) ?: 'Post' }}</span>
                    </div>
                @endforeach
            </div>
        @empty
            <flux:text class="text-center">Nothing planned this month yet.</flux:text>
        @endforelse
    </div>

    @if ($ideas)
        <section class="hidden rounded-xl border border-zinc-200 p-5 sm:block dark:border-zinc-700">
            <flux:heading>Ideas for {{ $title }}</flux:heading>
            <ul class="mt-3 grid gap-2 text-sm md:grid-cols-2">
                @foreach ($ideas as $date => $list)
                    @foreach ($list as $idea)
                        <li><span class="font-medium">{{ \Carbon\Carbon::parse($date)->format('j M') }} · {{ $idea['name'] }}</span>: <span class="text-zinc-600 dark:text-zinc-400">{{ $idea['idea'] }}</span></li>
                    @endforeach
                @endforeach
            </ul>
        </section>
    @endif
</div>
