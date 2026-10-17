<?php

use App\Models\Course;
use App\Models\QuizAttempt;
use App\Services\Academy\Academy;
use Livewire\Volt\Component;

new class extends Component {
    public Course $course;

    /** question id => chosen option index */
    public array $answers = [];

    public ?int $attemptId = null;

    public function mount(Course $course, Academy $academy): void
    {
        abort_unless($course->is_published || auth()->user()->isAdmin(), 404);
        abort_unless($course->hasQuiz(), 404);
        $this->course = $course;
    }

    public function submit(Academy $academy): void
    {
        abort_unless($academy->allLessonsDone(auth()->user(), $this->course), 403);

        $questions = $this->course->questions()->get();
        $unanswered = $questions->filter(fn ($q) => ! isset($this->answers[$q->id]) || $this->answers[$q->id] === '')->count();

        if ($unanswered) {
            $this->addError('answers', "Answer every question before submitting ({$unanswered} left).");

            return;
        }

        $this->attemptId = $academy->submitQuiz(auth()->user(), $this->course, $this->answers)->id;
    }

    public function retake(): void
    {
        $this->reset('answers', 'attemptId');
    }

    public function with(): array
    {
        $academy = app(Academy::class);
        $user = auth()->user();
        $attempt = $this->attemptId ? QuizAttempt::where('user_id', $user->id)->find($this->attemptId) : null;

        return [
            'unlocked' => $academy->allLessonsDone($user, $this->course),
            'questions' => $this->course->questions()->get(),
            'attempt' => $attempt,
            'certificate' => $attempt?->passed
                ? $user->certificates()->where('course_id', $this->course->id)->whereNull('revoked_at')->first()
                : null,
            'progress' => $this->course->progressFor($user),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:link :href="route('academy.course', $course)" wire:navigate class="text-sm">← {{ $course->title }}</flux:link>
        <flux:heading size="xl" class="mt-1">Final quiz</flux:heading>
        <flux:text>{{ $questions->count() }} questions · pass mark {{ $course->pass_mark }}%. You can retake it as many times as you need.</flux:text>
    </div>

    @if (! $unlocked)
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
            <flux:icon name="lock-closed" class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-2">Finish all lessons first</flux:heading>
            <flux:text class="mt-1">You’ve completed {{ $progress['done'] }} of {{ $progress['total'] }} lessons.</flux:text>
            <flux:button class="mt-4" :href="route('academy.course', $course)" wire:navigate>Back to the course</flux:button>
        </div>
    @elseif ($attempt)
        {{-- Result --}}
        <div @class([
            'rounded-2xl p-6 text-center',
            'bg-green-50 dark:bg-green-950/30' => $attempt->passed,
            'bg-amber-50 dark:bg-amber-950/30' => ! $attempt->passed,
        ])>
            <div class="text-5xl font-semibold tabular-nums">{{ $attempt->score }}%</div>
            @if ($attempt->passed)
                <flux:heading size="lg" class="mt-2">🎉 Congratulations, you passed!</flux:heading>
                @if ($certificate)
                    <flux:text class="mt-1">Your certificate is ready.</flux:text>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <flux:button variant="primary" icon="academic-cap" :href="route('certificates.show', $certificate)" target="_blank">View your certificate</flux:button>
                        <flux:button icon="plus" :href="$certificate->linkedInUrl()" target="_blank">Add to LinkedIn</flux:button>
                    </div>
                @endif
            @else
                <flux:heading size="lg" class="mt-2">Almost there. You need {{ $course->pass_mark }}% to pass.</flux:heading>
                <flux:text class="mt-1">Review the explanations below, revisit the lessons if you like, then try again.</flux:text>
                <flux:button class="mt-4" variant="primary" icon="arrow-path" wire:click="retake">Retake the quiz</flux:button>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            @foreach ($questions as $i => $q)
                @php($chosen = $attempt->answers[$q->id] ?? null)
                @php($right = $chosen === $q->correct_option)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex gap-2 font-medium">
                        <flux:icon :name="$right ? 'check-circle' : 'x-circle'" variant="solid" @class(['size-5 shrink-0', 'text-green-500' => $right, 'text-red-500' => ! $right]) />
                        {{ $i + 1 }}. {{ $q->question }}
                    </div>
                    @unless ($right)
                        <div class="mt-2 ps-7 text-sm">
                            <div class="text-red-600 dark:text-red-400">Your answer: {{ $q->options[$chosen] ?? '—' }}</div>
                            @if ($attempt->passed)
                                <div class="text-green-700 dark:text-green-400">Correct answer: {{ $q->options[$q->correct_option] }}</div>
                            @endif
                            @if ($q->explanation)
                                <div class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $q->explanation }}</div>
                            @endif
                        </div>
                    @endunless
                </div>
            @endforeach
        </div>
    @else
        {{-- Questions --}}
        <form wire:submit="submit" class="flex flex-col gap-4">
            @foreach ($questions as $i => $q)
                <fieldset wire:key="q-{{ $q->id }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <legend class="sr-only">Question {{ $i + 1 }}</legend>
                    <div class="font-medium">{{ $i + 1 }}. {{ $q->question }}</div>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($q->options as $o => $option)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-zinc-700 dark:has-[:checked]:bg-indigo-950/40">
                                <input type="radio" wire:model="answers.{{ $q->id }}" value="{{ $o }}" class="size-4">
                                {{ $option }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <flux:error name="answers" />
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="paper-airplane">Submit answers</flux:button>
            </div>
        </form>
    @endif
</div>
