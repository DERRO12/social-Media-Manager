<?php

use App\Models\Course;
use App\Services\Academy\CourseLibrary;
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

    public function installFromLibrary(string $slug, CourseLibrary $library): void
    {
        $course = $library->install($slug);

        session()->flash('status', "“{$course->title}” was added as a draft. Review it, add YouTube videos if you like, then publish it.");
        $this->redirectRoute('admin.course', $course, navigate: true);
    }

    /** Add every library course, optionally publishing them straight away. */
    public function installAll(bool $publish = false): void
    {
        $courses = app(CourseLibrary::class)->installAll();

        if ($publish) {
            collect($courses)->each->update(['is_published' => true]);
        }

        session()->flash('status', count($courses).' library courses are in your Academy'.($publish ? ' and published.' : ' as drafts.'));
        $this->redirectRoute('admin.courses', navigate: true);
    }

    public function with(): array
    {
        $installed = Course::pluck('slug')->all();

        return [
            'courses' => Course::withCount(['lessons', 'enrollments', 'certificates', 'questions'])
                ->orderByDesc('is_featured')->orderBy('sort')->orderBy('id')->get(),
            'library' => collect(app(CourseLibrary::class)->all())->map(fn ($def) => [
                'slug' => $def['slug'],
                'title' => $def['title'],
                'summary' => $def['summary'],
                'level' => \App\Models\Course::LEVELS[$def['level'] ?? 'beginner'] ?? 'Beginner',
                'lessons' => collect($def['modules'])->sum(fn ($m) => count($m[1])),
                'questions' => count($def['questions']),
                'installed' => in_array($def['slug'], $installed, true),
            ])->values(),
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

    {{-- Ready-made courses --}}
    @if ($library->where('installed', false)->isNotEmpty())
        <section x-data="{ open: {{ $courses->isEmpty() ? 'true' : 'false' }} }" class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading>Course library: {{ $library->count() }} ready-made courses</flux:heading>
                    <flux:text class="text-sm">
                        A full social media academy: foundations, TikTok, Instagram, YouTube, Facebook, LinkedIn &amp; X, phone video and Google.
                        Each has written lessons, a final quiz and a certificate. Add them as drafts to edit first, or publish them at once.
                    </flux:text>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="ghost" x-on:click="open = ! open" x-text="open ? 'Hide list' : 'Show list'"></flux:button>
                    <flux:button size="sm" icon="sparkles" wire:click="installAll">Add all as drafts</flux:button>
                    <flux:button size="sm" variant="primary" icon="rocket-launch" wire:click="installAll(true)"
                        wire:confirm="Add every library course and publish them now, so students can start straight away?">Add all &amp; publish</flux:button>
                </div>
            </div>
            <div x-show="open" x-cloak class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($library as $item)
                    <div wire:key="lib-{{ $item['slug'] }}" class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $item['title'] }}</div>
                            <div class="text-xs text-zinc-500">{{ $item['level'] }} · {{ $item['lessons'] }} lessons · {{ $item['questions'] }}-question quiz</div>
                            <flux:text class="mt-1 text-sm">{{ $item['summary'] }}</flux:text>
                        </div>
                        @if ($item['installed'])
                            <flux:badge size="sm" color="green">Added</flux:badge>
                        @else
                            <flux:button size="sm" icon="plus" wire:click="installFromLibrary('{{ $item['slug'] }}')">Add</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

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
