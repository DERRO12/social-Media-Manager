<?php

use App\Models\Course;
use App\Services\Academy\StarterCourse;
use Livewire\Volt\Component;

new class extends Component {
    public string $title = '';
    public string $level = 'beginner';

    public function create(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'level' => ['required', 'in:'.implode(',', array_keys(Course::LEVELS))],
        ]);

        $course = Course::create([
            'title' => $this->title,
            'level' => $this->level,
            'sort' => (int) Course::max('sort') + 1,
        ]);

        $this->redirectRoute('admin.course', $course, navigate: true);
    }

    public function installStarter(StarterCourse $starter): void
    {
        $course = $starter->install();

        session()->flash('status', "“{$course->title}” was added as a draft. Review it, add your own YouTube videos if you like, then publish it.");
        $this->redirectRoute('admin.course', $course, navigate: true);
    }

    public function with(): array
    {
        return [
            'courses' => Course::withCount(['lessons', 'enrollments', 'certificates', 'questions'])
                ->orderByDesc('is_featured')->orderBy('sort')->orderBy('id')->get(),
            'hasStarter' => Course::where('slug', StarterCourse::SLUG)->exists(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Academy courses</flux:heading>
            <flux:text>Create courses from YouTube videos and written lessons, add a final quiz, and award certificates.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('admin.certificates')" wire:navigate icon="academic-cap">Certificates</flux:button>
            <flux:modal.trigger name="new-course">
                <flux:button variant="primary" icon="plus">New course</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    @unless ($hasStarter)
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">
            <div>
                <flux:heading>Start with a ready-made course</flux:heading>
                <flux:text class="text-sm">
                    “Social Media Marketing Foundations”: 4 modules, 12 written lessons and a 12-question final exam with a certificate.
                    It’s added as a draft so you can edit everything and add YouTube videos before publishing.
                </flux:text>
            </div>
            <flux:button wire:click="installStarter" icon="sparkles">Add starter course</flux:button>
        </div>
    @endunless

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($courses as $course)
            <a href="{{ route('admin.course', $course) }}" wire:navigate wire:key="course-{{ $course->id }}"
                class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 transition hover:shadow-md dark:border-zinc-700">
                <div class="aspect-video bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400">
                    @if ($course->coverUrl())
                        <img src="{{ $course->coverUrl() }}" alt="" class="size-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                    @endif
                </div>
                <div class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex flex-wrap gap-1">
                        @if ($course->is_published)
                            <flux:badge size="sm" color="green">Published</flux:badge>
                        @else
                            <flux:badge size="sm">Draft</flux:badge>
                        @endif
                        @if ($course->is_featured)
                            <flux:badge size="sm" color="amber">Featured</flux:badge>
                        @endif
                        <flux:badge size="sm" color="sky">{{ $course->levelLabel() }}</flux:badge>
                    </div>
                    <div class="font-medium">{{ $course->title }}</div>
                    <div class="mt-auto grid grid-cols-4 gap-2 pt-2 text-center text-xs text-zinc-500">
                        <div><div class="text-base font-semibold text-zinc-900 dark:text-white">{{ $course->lessons_count }}</div>lessons</div>
                        <div><div class="text-base font-semibold text-zinc-900 dark:text-white">{{ $course->questions_count }}</div>quiz Qs</div>
                        <div><div class="text-base font-semibold text-zinc-900 dark:text-white">{{ $course->enrollments_count }}</div>students</div>
                        <div><div class="text-base font-semibold text-zinc-900 dark:text-white">{{ $course->certificates_count }}</div>certified</div>
                    </div>
                </div>
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>No courses yet. Create one, or add the starter course above.</flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal name="new-course" class="w-full max-w-md">
        <form wire:submit="create" class="flex flex-col gap-4">
            <flux:heading size="lg">New course</flux:heading>
            <flux:input wire:model="title" label="Course title" placeholder="Instagram Reels Mastery" required />
            <flux:select wire:model="level" label="Level">
                @foreach (\App\Models\Course::LEVELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            <flux:text class="text-sm">You’ll add the description, lessons and quiz on the next screen. New courses start as drafts.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create course</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
