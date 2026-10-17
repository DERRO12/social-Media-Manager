<?php

use App\Models\Course;
use App\Models\LessonProgress;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    #[Url]
    public string $level = '';

    public function with(): array
    {
        $user = auth()->user();
        $courses = Course::published()->withCount('lessons')->with('lessons:id,course_id,duration_minutes')
            ->when($this->level, fn ($q) => $q->where('level', $this->level))
            ->get();

        $enrollments = $user->enrollments()->with('course', 'lastLesson')->latest('updated_at')->get()->keyBy('course_id');
        $certified = $user->certificates()->whereNull('revoked_at')->pluck('course_id')->all();

        return [
            'courses' => $courses,
            'progress' => $courses->mapWithKeys(fn ($c) => [$c->id => $c->progressFor($user)]),
            'certified' => $certified,
            'continue' => $enrollments->filter(fn ($e) => ! $e->completed_at && $e->course?->is_published && $e->lastLesson)->take(3),
            'stats' => [
                ['Lessons completed', LessonProgress::where('user_id', $user->id)->count(), 'check-circle'],
                ['Courses finished', $enrollments->whereNotNull('completed_at')->count(), 'trophy'],
                ['Certificates', count($certified), 'academic-cap'],
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="relative overflow-hidden rounded-2xl bg-linear-to-br from-indigo-600 via-fuchsia-600 to-orange-500 p-6 text-white sm:p-8">
        <div class="max-w-2xl">
            <div class="text-sm font-medium text-white/80">{{ config('app.name') }} Academy</div>
            <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">Learn social media marketing. Get certified.</h1>
            <p class="mt-2 text-white/85">Short video lessons, practical notes, a final quiz, and a certificate you can share on LinkedIn.</p>
        </div>
        <div class="mt-6 grid max-w-xl grid-cols-3 gap-3">
            @foreach ($stats as [$label, $value, $icon])
                <div class="rounded-xl bg-white/15 p-3 backdrop-blur">
                    <flux:icon :name="$icon" variant="mini" class="text-white/80" />
                    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</div>
                    <div class="text-xs text-white/80">{{ $label }}</div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($continue->isNotEmpty())
        <section>
            <flux:heading size="lg" class="mb-3">Continue learning</flux:heading>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ($continue as $enrollment)
                    <a href="{{ route('academy.lesson', [$enrollment->course, $enrollment->lastLesson]) }}" wire:navigate
                        class="flex items-center gap-3 rounded-xl border border-zinc-200 p-3 transition hover:shadow-md dark:border-zinc-700">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white"><flux:icon name="play" variant="solid" class="size-4" /></span>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">{{ $enrollment->lastLesson->title }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $enrollment->course->title }} · {{ $progress[$enrollment->course_id]['percent'] ?? 0 }}%</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">All courses</flux:heading>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="level" class="w-40">
                    <option value="">All levels</option>
                    @foreach (\App\Models\Course::LEVELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:button :href="route('academy.certificates')" wire:navigate icon="academic-cap">My certificates</flux:button>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($courses as $course)
                <x-academy.course-card wire:key="c-{{ $course->id }}" :course="$course" :href="route('academy.course', $course)"
                    :progress="$progress[$course->id]" :certified="in_array($course->id, $certified)" wire:navigate />
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                    <flux:text>New courses are on the way. Check back soon!</flux:text>
                </div>
            @endforelse
        </div>
    </section>
</div>
