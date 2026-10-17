@props(['course', 'href', 'progress' => null, 'certified' => false])

<a href="{{ $href }}" {{ $attributes->class('group flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white transition hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900') }}>
    <div class="relative aspect-video overflow-hidden bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400">
        @if ($cover = $course->coverUrl())
            <img src="{{ $cover }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover transition group-hover:scale-105">
        @else
            <div class="flex size-full items-center justify-center text-white/90"><flux:icon name="academic-cap" class="size-12" /></div>
        @endif
        @if ($course->is_featured)
            <span class="absolute left-3 top-3 rounded-full bg-white/90 px-2 py-0.5 text-xs font-semibold text-zinc-900">★ Featured</span>
        @endif
        @if ($certified)
            <span class="absolute right-3 top-3 rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">Certified</span>
        @endif
    </div>
    <div class="flex flex-1 flex-col gap-2 p-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
            <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ $course->levelLabel() }}</span>
            <span>{{ $course->lessons_count ?? $course->lessons->count() }} lessons</span>
            @if ($course->durationLabel())<span>{{ $course->durationLabel() }}</span>@endif
            @if ($course->certificate_enabled)<span class="flex items-center gap-1"><flux:icon name="check-badge" variant="micro" /> Certificate</span>@endif
        </div>
        <div class="font-semibold leading-snug group-hover:underline">{{ $course->title }}</div>
        @if ($course->summary)
            <p class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $course->summary }}</p>
        @endif
        @if ($progress !== null)
            <div class="mt-auto pt-2">
                <div class="flex justify-between text-xs text-zinc-500">
                    <span>{{ $progress['percent'] === 100 ? 'Completed' : ($progress['done'] ? 'In progress' : 'Not started') }}</span>
                    <span class="tabular-nums">{{ $progress['percent'] }}%</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full rounded-full bg-green-500" style="width: {{ $progress['percent'] }}%"></div>
                </div>
            </div>
        @endif
    </div>
</a>
