<?php

use App\Enums\Platform;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    /** Time slots for "best time to post" (local hours, start inclusive). */
    public const SLOTS = ['Early (5–9)' => [5, 9], 'Morning (9–12)' => [9, 12], 'Afternoon (12–17)' => [12, 17], 'Evening (17–21)' => [17, 21], 'Night (21–5)' => [21, 29]];

    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    #[Url]
    public string $period = '90';

    #[Url]
    public string $platform = '';

    public function with(): array
    {
        $user = auth()->user();
        $tz = $user->timezone ?: 'UTC';

        $items = $user->contentItems()->with('account')
            ->where('content_items.type', '!=', 'business')
            ->whereNotNull('content_items.published_at')
            ->when($this->period !== 'all', fn ($q) => $q->where('content_items.published_at', '>=', now()->subDays((int) $this->period)))
            ->when($this->platform, fn ($q) => $q->where('social_accounts.platform', $this->platform))
            ->get()
            ->map(function ($item) use ($tz) {
                $local = $item->published_at->clone()->setTimezone($tz);
                $engagement = (int) $item->likes + (int) $item->comments_count + (int) $item->shares;

                return (object) [
                    'item' => $item,
                    'engagement' => $engagement,
                    'rate' => $item->views ? $engagement / $item->views : null,
                    'day' => $local->dayOfWeekIso - 1,
                    'slot' => collect(self::SLOTS)->search(fn ($r) => ($h = $local->hour < 5 ? $local->hour + 24 : $local->hour) >= $r[0] && $h < $r[1]),
                ];
            });

        // Average engagement per weekday × time slot; only cells with posts count.
        $grid = [];
        foreach (self::DAYS as $d => $_) {
            foreach (array_keys(self::SLOTS) as $slot) {
                $cell = $items->where('day', $d)->where('slot', $slot);
                $grid[$d][$slot] = $cell->isEmpty() ? null : ['avg' => $cell->avg('engagement'), 'posts' => $cell->count()];
            }
        }
        $maxAvg = max(1, collect($grid)->flatten(1)->filter()->max('avg') ?? 1);

        $byDay = collect(self::DAYS)->map(fn ($label, $d) => ['label' => $label, 'avg' => $items->where('day', $d)->avg('engagement'), 'posts' => $items->where('day', $d)->count()]);
        $bySlot = collect(self::SLOTS)->keys()->map(fn ($slot) => ['label' => $slot, 'avg' => $items->where('slot', $slot)->avg('engagement'), 'posts' => $items->where('slot', $slot)->count()]);

        // Recommendations need a few posts per group to mean anything.
        $bestDay = $byDay->filter(fn ($d) => $d['posts'] >= 2)->sortByDesc('avg')->first();
        $bestSlot = $bySlot->filter(fn ($s) => $s['posts'] >= 2)->sortByDesc('avg')->first();

        $platforms = $items->groupBy(fn ($r) => $r->item->account->platform->value)->map(fn ($rows, $p) => [
            'platform' => Platform::from($p),
            'posts' => $rows->count(),
            'views' => $rows->sum(fn ($r) => (int) $r->item->views),
            'engagement' => $rows->sum('engagement'),
            'avg' => $rows->avg('engagement'),
        ])->sortByDesc('engagement')->values();

        return [
            'count' => $items->count(),
            'totals' => [
                'views' => $items->sum(fn ($r) => (int) $r->item->views),
                'engagement' => $items->sum('engagement'),
                'avg' => $items->avg('engagement'),
                'rate' => ($v = $items->sum(fn ($r) => (int) $r->item->views)) ? $items->sum('engagement') / $v : null,
            ],
            'grid' => $grid,
            'maxAvg' => $maxAvg,
            'bestDay' => $bestDay,
            'bestSlot' => $bestSlot,
            'byDay' => $byDay,
            'top' => $items->sortByDesc('engagement')->take(10)->values(),
            'platforms' => $platforms,
            'connected' => $user->socialAccounts()->whereIn('platform', Platform::publishable())->pluck('platform')->unique()->values(),
            'tz' => $tz,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Insights</flux:heading>
            <flux:text>What works on your accounts: your best days and times to post, and your top posts.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:select wire:model.live="period" class="w-40">
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last 12 months</option>
                <option value="all">All time</option>
            </flux:select>
            <flux:select wire:model.live="platform" class="w-44">
                <option value="">All platforms</option>
                @foreach ($connected as $p)
                    <option value="{{ $p->value }}">{{ $p->label() }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($count < 3)
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:icon name="chart-pie" class="mx-auto size-8 text-zinc-400" />
            <flux:heading size="lg" class="mt-2">Not enough posts yet</flux:heading>
            <flux:text class="mt-1">Insights need at least 3 published posts in this period. Connect your accounts (we import your past posts too) and keep posting.</flux:text>
            <flux:button class="mt-4" :href="route('accounts')" wire:navigate>Connected accounts</flux:button>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @foreach ([
                ['Posts', number_format($count), 'film'],
                ['Views', \App\Support\Metric::short($totals['views']), 'eye'],
                ['Likes + comments + shares', \App\Support\Metric::short($totals['engagement']), 'heart'],
                ['Engagement rate', $totals['rate'] !== null ? number_format(100 * $totals['rate'], 1).'%' : '—', 'arrow-trending-up'],
            ] as [$label, $value, $icon])
                <div class="min-w-0 rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                    <div class="flex items-center gap-2 text-xs text-zinc-500 sm:text-sm"><flux:icon :name="$icon" variant="mini" class="shrink-0" /> <span class="truncate">{{ $label }}</span></div>
                    <div class="mt-1 truncate text-xl font-semibold tabular-nums sm:mt-2 sm:text-2xl">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if ($bestDay || $bestSlot)
            <flux:callout icon="light-bulb" color="indigo">
                <flux:callout.heading>
                    Your posts do best
                    @if ($bestDay) on <strong>{{ ['Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays', 'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'][$bestDay['label']] }}</strong>@endif
                    @if ($bestSlot) in the <strong>{{ strtolower(\Illuminate\Support\Str::before($bestSlot['label'], ' (')) }}</strong>@endif.
                </flux:callout.heading>
                <flux:callout.text>Schedule your most important posts then (times in {{ $tz }}). Keep testing other times too: the more you post, the more accurate this gets.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Best time to post</flux:heading>
                <flux:text class="text-sm">Average likes, comments and shares per post. Darker = better. Empty = no posts at that time yet.</flux:text>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[420px] border-separate border-spacing-1 text-xs">
                        <thead>
                            <tr>
                                <th></th>
                                @foreach (array_keys($this::SLOTS) as $slot)
                                    <th class="px-1 font-medium text-zinc-500">{{ \Illuminate\Support\Str::before($slot, ' (') }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this::DAYS as $d => $label)
                                <tr>
                                    <th class="pe-2 text-left font-medium text-zinc-500">{{ $label }}</th>
                                    @foreach (array_keys($this::SLOTS) as $slot)
                                        @php($cell = $grid[$d][$slot])
                                        @php($shade = $cell ? (int) ceil(4 * $cell['avg'] / $maxAvg) : 0)
                                        <td @class([
                                            'h-9 rounded text-center tabular-nums',
                                            'bg-zinc-100 text-zinc-400 dark:bg-zinc-800' => $shade === 0,
                                            'bg-indigo-100 text-indigo-900' => $shade === 1,
                                            'bg-indigo-200 text-indigo-900' => $shade === 2,
                                            'bg-indigo-400 text-white' => $shade === 3,
                                            'bg-indigo-600 text-white' => $shade >= 4,
                                        ]) title="{{ $cell ? $cell['posts'].' posts, '.round($cell['avg']).' avg' : 'No posts' }}">
                                            {{ $cell ? \App\Support\Metric::short((int) round($cell['avg'])) : '' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>By platform</flux:heading>
                <div class="mt-4 flex flex-col gap-3">
                    @php($maxEng = max(1, $platforms->max('engagement')))
                    @foreach ($platforms as $row)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <x-platform-badge :platform="$row['platform']" />
                                <span class="text-zinc-500">{{ $row['posts'] }} posts · {{ \App\Support\Metric::short($row['views']) }} views · {{ \App\Support\Metric::short((int) round($row['avg'])) }} avg</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full bg-indigo-500" style="width: {{ max(2, round(100 * $row['engagement'] / $maxEng)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <flux:heading class="mt-6">By day of the week</flux:heading>
                @php($maxDay = max(1, $byDay->max('avg') ?? 1))
                <div class="mt-3 flex h-28 items-end gap-2">
                    @foreach ($byDay as $d)
                        <div class="flex flex-1 flex-col items-center gap-1" title="{{ $d['posts'] }} posts">
                            <div class="w-full rounded-t {{ $d['avg'] ? 'bg-indigo-400' : 'bg-zinc-200 dark:bg-zinc-700' }}" style="height: {{ $d['avg'] ? max(6, round(80 * $d['avg'] / $maxDay)) : 6 }}px"></div>
                            <span class="text-xs text-zinc-500">{{ $d['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>Top posts</flux:heading>
            <flux:text class="text-sm">Do more of what works: post similar content, reuse the best ones on other platforms.</flux:text>
            <div class="mt-4 flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($top as $i => $row)
                    <div class="flex items-center gap-3 py-3" wire:key="top-{{ $row->item->id }}">
                        <span class="w-5 text-sm font-semibold text-zinc-400">{{ $i + 1 }}</span>
                        <div class="aspect-video w-20 shrink-0 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                            @if ($row->item->thumbnail_url)<img src="{{ $row->item->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $row->item->permalink }}" target="_blank" rel="noopener" class="line-clamp-1 text-sm font-medium hover:underline">{{ $row->item->title ?: \Illuminate\Support\Str::limit($row->item->caption, 80) ?: 'Post' }}</a>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                                <x-platform-badge :platform="$row->item->account->platform" />
                                {{ auth()->user()->localTime($row->item->published_at, 'D j M, H:i') }}
                            </div>
                        </div>
                        <div class="text-right text-sm">
                            <div class="font-semibold tabular-nums">{{ \App\Support\Metric::short($row->engagement) }}</div>
                            <div class="text-xs text-zinc-500">{{ $row->rate !== null ? number_format(100 * $row->rate, 1).'% rate' : 'engagements' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
