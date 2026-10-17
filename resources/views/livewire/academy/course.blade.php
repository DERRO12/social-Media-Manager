<?php

use App\Models\Course;
use App\Services\Academy\Academy;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public Course $course;

    public function mount(Course $course): void
    {
        abort_unless($course->is_published || auth()->user()->isAdmin(), 404);
        $this->course = $course;
    }

    public function with(): array
    {
        $academy = app(Academy::class);
        $user = auth()->user();
        $lessons = $this->course->orderedLessons();
        $done = $user->completedLessonIds();
        $enrollment = $user->enrollments()->where('course_id', $this->course->id)->first();
        $next = $lessons->first(fn ($l) => ! in_array($l->id, $done)) ?? $lessons->first();

        return [
            'lessons' => $lessons,
            'modules' => $this->course->modules()->get(),
            'done' => $done,
            'progress' => $this->course->progressFor($user),
            'next' => $next,
            'started' => (bool) $enrollment,
            'hasQuiz' => $this->course->hasQuiz(),
            'quizUnlocked' => $academy->allLessonsDone($user, $this->course),
            'bestScore' => $user->id ? \App\Models\QuizAttempt::where('user_id', $user->id)->where('course_id', $this->course->id)->max('score') : null,
            'certificate' => $user->certificates()->where('course_id', $this->course->id)->whereNull('revoked_at')->first(),
            'descriptionHtml' => Str::markdown((string) $this->course->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ];
    }
}; ?>

<div class="grid gap-8 lg:grid-cols-[1fr_340px]">
    <div class="flex min-w-0 flex-col gap-6">
        <div>
            <flux:link :href="route('academy')" wire:navigate class="text-sm">← Academy</flux:link>
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:badge size="sm" color="indigo">{{ $course->levelLabel() }}</flux:badge>
                <flux:badge size="sm">{{ $lessons->count() }} lessons</flux:badge>
                @if ($course->durationLabel())<flux:badge size="sm">{{ $course->durationLabel() }}</flux:badge>@endif
                @if ($course->certificate_enabled)<flux:badge size="sm" color="green" icon="check-badge">Certificate</flux:badge>@endif
                @unless ($course->is_published)<flux:badge size="sm" color="amber">Draft (only you can see this)</flux:badge>@endunless
            </div>
            <flux:heading size="xl" class="mt-2">{{ $course->title }}</flux:heading>
            @if ($course->summary)
                <flux:text class="mt-1 text-base">{{ $course->summary }}</flux:text>
            @endif
        </div>

        @if ($course->description)
            <div class="leading-relaxed text-zinc-700 dark:text-zinc-300 [&_li]:mt-1 [&_p]:mt-3 [&_strong]:text-zinc-900 dark:[&_strong]:text-white [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:ps-6 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:ps-6">
                {!! $descriptionHtml !!}
            </div>
        @endif

        <section>
            <flux:heading size="lg" class="mb-3">Course content</flux:heading>
            @php($groups = $lessons->groupBy(fn ($l) => $l->course_module_id ?? 0))
            <div class="flex flex-col gap-3">
                @foreach ($modules->push((object) ['id' => 0, 'title' => $modules->isEmpty() ? 'Lessons' : 'More lessons']) as $module)
                    @if ($groups->has($module->id))
                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between bg-zinc-50 px-4 py-3 dark:bg-zinc-900">
                                <span class="font-medium">{{ $module->title }}</span>
                                <span class="text-xs text-zinc-500">{{ $groups[$module->id]->filter(fn ($l) => in_array($l->id, $done))->count() }}/{{ $groups[$module->id]->count() }}</span>
                            </div>
                            @foreach ($groups[$module->id] as $lesson)
                                <a href="{{ route('academy.lesson', [$course, $lesson]) }}" wire:navigate
                                    class="flex items-center gap-3 border-t border-zinc-200 px-4 py-3 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                                    @if (in_array($lesson->id, $done))
                                        <flux:icon name="check-circle" variant="solid" class="size-5 shrink-0 text-green-500" />
                                    @else
                                        <flux:icon :name="$lesson->isVideo() ? 'play-circle' : 'document-text'" class="size-5 shrink-0 text-zinc-400" />
                                    @endif
                                    <span class="min-w-0 flex-1 truncate">{{ $lesson->title }}</span>
                                    @if ($lesson->duration_minutes)<span class="shrink-0 text-xs text-zinc-500">{{ $lesson->duration_minutes }} min</span>@endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    </div>

    <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
            @if ($cover = $course->coverUrl())
                <img src="{{ $cover }}" alt="" class="aspect-video w-full object-cover" referrerpolicy="no-referrer">
            @endif
            <div class="flex flex-col gap-3 p-5">
                <div>
                    <div class="flex justify-between text-sm"><span>Your progress</span><span class="tabular-nums">{{ $progress['done'] }}/{{ $progress['total'] }} lessons</span></div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full bg-green-500 transition-all" style="width: {{ $progress['percent'] }}%"></div>
                    </div>
                </div>
                @if ($next)
                    <flux:button variant="primary" icon="play" :href="route('academy.lesson', [$course, $next])" wire:navigate>
                        {{ ! $started ? 'Start course' : ($progress['percent'] === 100 ? 'Review lessons' : 'Continue') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($certificate)
            <div class="rounded-2xl border border-green-200 bg-green-50 p-5 dark:border-green-900 dark:bg-green-950/30">
                <div class="flex items-center gap-2 font-semibold text-green-800 dark:text-green-300"><flux:icon name="trophy" variant="solid" class="size-5" /> You’re certified!</div>
                <flux:text class="mt-1 text-sm">Certificate {{ $certificate->code }}, issued {{ $certificate->issued_at->toFormattedDateString() }}.</flux:text>
                <div class="mt-3 flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" :href="route('certificates.show', $certificate)" target="_blank" icon="eye">View certificate</flux:button>
                    <flux:button size="sm" :href="$certificate->linkedInUrl()" target="_blank" icon="plus">Add to LinkedIn</flux:button>
                </div>
            </div>
        @elseif ($course->certificate_enabled)
            <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center gap-2 font-semibold"><flux:icon name="check-badge" class="size-5 text-indigo-500" /> Earn your certificate</div>
                <ol class="mt-3 space-y-2 text-sm">
                    <li class="flex items-center gap-2">
                        <flux:icon :name="$progress['percent'] === 100 ? 'check-circle' : 'minus-circle'" variant="mini" @class(['text-green-500' => $progress['percent'] === 100, 'text-zinc-400' => $progress['percent'] < 100]) />
                        Complete all {{ $progress['total'] }} lessons
                    </li>
                    @if ($hasQuiz)
                        <li class="flex items-center gap-2">
                            <flux:icon name="minus-circle" variant="mini" class="text-zinc-400" />
                            Pass the final quiz ({{ $course->pass_mark }}% or more)
                        </li>
                    @endif
                </ol>
                @if ($hasQuiz)
                    @if ($quizUnlocked)
                        <flux:button class="mt-4 w-full" variant="primary" icon="pencil-square" :href="route('academy.quiz', $course)" wire:navigate>
                            {{ $bestScore !== null ? 'Retake the final quiz' : 'Take the final quiz' }}
                        </flux:button>
                        @if ($bestScore !== null)
                            <flux:text class="mt-2 text-center text-xs">Best score so far: {{ $bestScore }}%</flux:text>
                        @endif
                    @else
                        <flux:button class="mt-4 w-full" disabled icon="lock-closed">Final quiz unlocks after all lessons</flux:button>
                    @endif
                @endif
            </div>
        @endif
    </aside>
</div>
