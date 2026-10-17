<?php

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Academy\Academy;
use Livewire\Volt\Component;

new class extends Component {
    public Course $course;
    public Lesson $lesson;

    public function mount(Course $course, Lesson $lesson, Academy $academy): void
    {
        abort_unless($lesson->course_id === $course->id, 404);
        abort_unless($course->is_published || auth()->user()->isAdmin(), 404);

        $this->course = $course;
        $this->lesson = $lesson;
        $academy->open(auth()->user(), $lesson);
    }

    public function complete(Academy $academy): void
    {
        $user = auth()->user();
        $academy->completeLesson($user, $this->lesson);

        $lessons = $this->course->orderedLessons();
        $index = $lessons->search(fn ($l) => $l->id === $this->lesson->id);
        $next = $lessons->get($index + 1);

        if ($next) {
            $this->redirectRoute('academy.lesson', [$this->course, $next], navigate: true);

            return;
        }

        // Last lesson: go to the quiz, or back to the course to see the certificate.
        if ($this->course->hasQuiz()) {
            session()->flash('status', 'You’ve finished every lesson. Time for the final quiz!');
            $this->redirectRoute('academy.quiz', $this->course, navigate: true);
        } else {
            session()->flash('status', 'Course complete! 🎉');
            $this->redirectRoute('academy.course', $this->course, navigate: true);
        }
    }

    public function with(): array
    {
        $lessons = $this->course->orderedLessons();
        $index = $lessons->search(fn ($l) => $l->id === $this->lesson->id);
        $done = auth()->user()->completedLessonIds();

        return [
            'lessons' => $lessons,
            'number' => $index + 1,
            'previous' => $index > 0 ? $lessons->get($index - 1) : null,
            'next' => $lessons->get($index + 1),
            'done' => $done,
            'isDone' => in_array($this->lesson->id, $done),
            'progress' => $this->course->progressFor(auth()->user()),
        ];
    }
}; ?>

<div class="grid gap-6 xl:grid-cols-[1fr_320px]">
    <div class="flex min-w-0 flex-col gap-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:link :href="route('academy.course', $course)" wire:navigate class="text-sm">← {{ $course->title }}</flux:link>
            <span class="text-sm text-zinc-500">Lesson {{ $number }} of {{ $lessons->count() }}</span>
        </div>

        @if ($lesson->isVideo())
            <div class="aspect-video overflow-hidden rounded-xl bg-black shadow-lg">
                <iframe src="{{ $lesson->embedUrl() }}" title="{{ $lesson->title }}" class="size-full" loading="lazy"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen
                    referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
        @endif

        <div>
            @if ($lesson->module)
                <div class="text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ $lesson->module->title }}</div>
            @endif
            <flux:heading size="xl" class="mt-1">{{ $lesson->title }}</flux:heading>
            @if ($lesson->duration_minutes)
                <flux:text class="mt-1 text-sm">{{ $lesson->duration_minutes }} min</flux:text>
            @endif
        </div>

        @if (filled($lesson->content))
            <article class="max-w-3xl leading-relaxed text-zinc-700 dark:text-zinc-300
                [&_a]:text-indigo-600 [&_a]:underline dark:[&_a]:text-indigo-400
                [&_blockquote]:mt-4 [&_blockquote]:border-l-4 [&_blockquote]:border-indigo-300 [&_blockquote]:ps-4 [&_blockquote]:italic
                [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white
                [&_h3]:mt-6 [&_h3]:font-semibold [&_h3]:text-zinc-900 dark:[&_h3]:text-white
                [&_li]:mt-1 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:ps-6 [&_p]:mt-3
                [&_strong]:text-zinc-900 dark:[&_strong]:text-white [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:ps-6
                [&_table]:mt-4 [&_table]:w-full [&_table]:text-sm [&_td]:border [&_td]:border-zinc-200 [&_td]:px-3 [&_td]:py-2 dark:[&_td]:border-zinc-700
                [&_th]:border [&_th]:border-zinc-200 [&_th]:bg-zinc-50 [&_th]:px-3 [&_th]:py-2 [&_th]:text-left dark:[&_th]:border-zinc-700 dark:[&_th]:bg-zinc-900">
                {!! $lesson->contentHtml() !!}
            </article>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            @if ($previous)
                <flux:button variant="ghost" icon="arrow-left" :href="route('academy.lesson', [$course, $previous])" wire:navigate>Previous</flux:button>
            @else
                <span></span>
            @endif
            <div class="flex items-center gap-3">
                @if ($isDone && $next)
                    <flux:button variant="ghost" icon-trailing="arrow-right" :href="route('academy.lesson', [$course, $next])" wire:navigate>Next lesson</flux:button>
                @endif
                <flux:button variant="primary" icon="check" wire:click="complete">
                    {{ $isDone ? ($next ? 'Done, next' : 'Finish') : ($next ? 'Mark complete & continue' : 'Mark complete & finish') }}
                </flux:button>
            </div>
        </div>
    </div>

    <aside class="flex flex-col gap-3 xl:sticky xl:top-6 xl:self-start">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex justify-between text-sm"><span class="font-medium">Course progress</span><span class="tabular-nums">{{ $progress['percent'] }}%</span></div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-green-500" style="width: {{ $progress['percent'] }}%"></div>
            </div>
        </div>
        <nav class="max-h-[70vh] overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            @foreach ($lessons as $i => $item)
                <a href="{{ route('academy.lesson', [$course, $item]) }}" wire:navigate @class([
                    'flex items-center gap-3 border-b border-zinc-200 px-3 py-2.5 text-sm last:border-0 dark:border-zinc-700',
                    'bg-indigo-50 font-medium dark:bg-indigo-950/40' => $item->id === $lesson->id,
                    'hover:bg-zinc-50 dark:hover:bg-zinc-900' => $item->id !== $lesson->id,
                ])>
                    @if (in_array($item->id, $done))
                        <flux:icon name="check-circle" variant="solid" class="size-5 shrink-0 text-green-500" />
                    @else
                        <span class="flex size-5 shrink-0 items-center justify-center rounded-full border border-zinc-300 text-[10px] text-zinc-500 dark:border-zinc-600">{{ $i + 1 }}</span>
                    @endif
                    <span class="min-w-0 flex-1 truncate">{{ $item->title }}</span>
                </a>
            @endforeach
        </nav>
        @if ($course->hasQuiz())
            <a href="{{ route('academy.quiz', $course) }}" wire:navigate class="flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2.5 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
                <flux:icon :name="$progress['percent'] === 100 ? 'pencil-square' : 'lock-closed'" class="size-5 text-zinc-400" />
                Final quiz
            </a>
        @endif
    </aside>
</div>
