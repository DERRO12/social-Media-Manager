<?php

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Services\Academy\YouTubeCatalog;
use App\Support\YouTube;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    public Course $course;

    #[Url]
    public string $tab = 'lessons';

    // Details
    public string $title = '';
    public string $slug = '';
    public string $summary = '';
    public string $description = '';
    public string $level = 'beginner';
    public string $thumbnailUrl = '';
    public bool $isPublished = false;
    public bool $isFeatured = false;
    public bool $certificateEnabled = true;
    public $passMark = 70;

    // Modules
    public string $newModule = '';
    public ?int $renamingModule = null;
    public string $moduleTitle = '';

    // Lesson form
    public ?int $lessonId = null;
    public $lessonModuleId = '';
    public string $lessonTitle = '';
    public string $lessonVideo = '';
    public string $lessonContent = '';
    public $lessonMinutes = '';
    public bool $lessonPreview = false;

    // Playlist import
    public string $playlistUrl = '';
    public $playlistModuleId = '';

    // Quiz question form
    public ?int $questionId = null;
    public string $questionText = '';
    public array $questionOptions = ['', '', '', ''];
    public $questionCorrect = 0;
    public string $questionExplanation = '';

    public function mount(Course $course): void
    {
        $this->course = $course;
        $this->fillDetails();
    }

    private function fillDetails(): void
    {
        $c = $this->course;
        $this->title = $c->title;
        $this->slug = $c->slug;
        $this->summary = (string) $c->summary;
        $this->description = (string) $c->description;
        $this->level = $c->level;
        $this->thumbnailUrl = (string) $c->thumbnail_url;
        $this->isPublished = $c->is_published;
        $this->isFeatured = $c->is_featured;
        $this->certificateEnabled = $c->certificate_enabled;
        $this->passMark = $c->pass_mark;
    }

    private function done(string $message): void
    {
        session()->flash('status', $message);
        $this->redirectRoute('admin.course', ['course' => $this->course, 'tab' => $this->tab], navigate: true);
    }

    // ---------- Details ----------

    public function saveDetails(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'alpha_dash', 'max:150', 'not_in:certificates', Rule::unique('courses', 'slug')->ignore($this->course->id)],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'level' => ['required', Rule::in(array_keys(Course::LEVELS))],
            'thumbnailUrl' => ['nullable', 'url', 'max:2000'],
            'passMark' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        if ($this->isPublished && $this->course->lessons()->doesntExist()) {
            $this->addError('isPublished', 'Add at least one lesson before publishing.');

            return;
        }

        $this->course->update([
            'title' => $data['title'],
            'slug' => strtolower($data['slug']),
            'summary' => $data['summary'] ?: null,
            'description' => $data['description'] ?: null,
            'level' => $data['level'],
            'thumbnail_url' => $data['thumbnailUrl'] ?: null,
            'is_published' => $this->isPublished,
            'is_featured' => $this->isFeatured,
            'certificate_enabled' => $this->certificateEnabled,
            'pass_mark' => (int) $data['passMark'],
        ]);

        $this->done('Course details saved.');
    }

    public function deleteCourse(): void
    {
        $title = $this->course->title;
        $this->course->delete();

        session()->flash('status', "Deleted “{$title}”.");
        $this->redirectRoute('admin.courses', navigate: true);
    }

    // ---------- Modules ----------

    public function addModule(): void
    {
        $this->validate(['newModule' => ['required', 'string', 'max:150']]);

        $this->course->modules()->create([
            'title' => $this->newModule,
            'sort' => (int) $this->course->modules()->max('sort') + 1,
        ]);

        $this->done('Module added.');
    }

    public function startRename(int $id): void
    {
        $this->renamingModule = $id;
        $this->moduleTitle = $this->module($id)->title;
    }

    public function renameModule(): void
    {
        $this->validate(['moduleTitle' => ['required', 'string', 'max:150']]);
        $this->module($this->renamingModule)->update(['title' => $this->moduleTitle]);
        $this->renamingModule = null;
    }

    public function deleteModule(int $id): void
    {
        // Its lessons are kept and move to "Other lessons".
        $this->module($id)->delete();
        $this->done('Module deleted. Its lessons are now under “Other lessons”.');
    }

    public function moveModule(int $id, int $direction): void
    {
        $this->swap($this->course->modules()->get(), $id, $direction);
    }

    private function module(int $id): CourseModule
    {
        return $this->course->modules()->findOrFail($id);
    }

    // ---------- Lessons ----------

    public function newLesson($moduleId = null): void
    {
        $this->reset('lessonId', 'lessonTitle', 'lessonVideo', 'lessonContent', 'lessonMinutes', 'lessonPreview');
        $this->lessonModuleId = $moduleId ?? '';
        $this->resetErrorBag();
        $this->modal('lesson-form')->show();
    }

    public function editLesson(int $id): void
    {
        $lesson = $this->lesson($id);
        $this->lessonId = $lesson->id;
        $this->lessonModuleId = $lesson->course_module_id ?? '';
        $this->lessonTitle = $lesson->title;
        $this->lessonVideo = $lesson->youtube_id ? YouTube::watchUrl($lesson->youtube_id) : '';
        $this->lessonContent = (string) $lesson->content;
        $this->lessonMinutes = $lesson->duration_minutes ?? '';
        $this->lessonPreview = $lesson->is_preview;
        $this->resetErrorBag();
        $this->modal('lesson-form')->show();
    }

    /** Fill the title (and length, with an API key) from the YouTube link. */
    public function fetchVideo(YouTubeCatalog $youtube): void
    {
        $id = YouTube::videoId($this->lessonVideo);

        if (! $id) {
            $this->addError('lessonVideo', 'That doesn’t look like a YouTube video link.');

            return;
        }

        $info = $youtube->lookup($id);
        $this->lessonTitle = $this->lessonTitle ?: (string) $info['title'];
        $this->lessonMinutes = $info['minutes'] ?? $this->lessonMinutes;

        if (! $info['title']) {
            $this->addError('lessonVideo', 'YouTube didn’t return this video. Check it is public or unlisted.');
        }
    }

    public function saveLesson(): void
    {
        $this->validate([
            'lessonTitle' => ['required', 'string', 'max:200'],
            'lessonModuleId' => ['nullable', Rule::exists('course_modules', 'id')->where('course_id', $this->course->id)],
            'lessonVideo' => ['nullable', 'string', 'max:500'],
            'lessonContent' => ['nullable', 'string', 'max:50000'],
            'lessonMinutes' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $videoId = $this->lessonVideo !== '' ? YouTube::videoId($this->lessonVideo) : null;

        if ($this->lessonVideo !== '' && ! $videoId) {
            $this->addError('lessonVideo', 'That doesn’t look like a YouTube video link.');

            return;
        }

        if (! $videoId && trim($this->lessonContent) === '') {
            $this->addError('lessonContent', 'Add a YouTube video, written notes, or both.');

            return;
        }

        $moduleId = $this->lessonModuleId !== '' ? (int) $this->lessonModuleId : null;
        $attributes = [
            'course_module_id' => $moduleId,
            'title' => $this->lessonTitle,
            'youtube_id' => $videoId,
            'content' => $this->lessonContent ?: null,
            'duration_minutes' => $this->lessonMinutes !== '' ? (int) $this->lessonMinutes : null,
            'is_preview' => $this->lessonPreview,
        ];

        if ($this->lessonId) {
            $this->lesson($this->lessonId)->update($attributes);
        } else {
            $this->course->lessons()->create($attributes + ['sort' => $this->nextLessonSort($moduleId)]);
        }

        $this->modal('lesson-form')->close();
        $this->done($this->lessonId ? 'Lesson updated.' : 'Lesson added.');
    }

    public function deleteLesson(int $id): void
    {
        $this->lesson($id)->delete();
        $this->done('Lesson deleted.');
    }

    public function moveLesson(int $id, int $direction): void
    {
        $lesson = $this->lesson($id);
        $siblings = $this->course->lessons()->where('course_module_id', $lesson->course_module_id)->orderBy('sort')->orderBy('id')->get();
        $this->swap($siblings, $id, $direction);
    }

    public function importPlaylist(YouTubeCatalog $youtube): void
    {
        $this->validate([
            'playlistUrl' => ['required', 'string'],
            'playlistModuleId' => ['nullable', Rule::exists('course_modules', 'id')->where('course_id', $this->course->id)],
        ]);

        $playlistId = YouTube::playlistId($this->playlistUrl);
        if (! $playlistId) {
            $this->addError('playlistUrl', 'That doesn’t look like a YouTube playlist link (it should contain “list=”).');

            return;
        }

        try {
            $videos = $youtube->playlist($playlistId);
        } catch (\RuntimeException $e) {
            $this->addError('playlistUrl', $e->getMessage());

            return;
        }

        $moduleId = $this->playlistModuleId !== '' ? (int) $this->playlistModuleId : null;
        $sort = $this->nextLessonSort($moduleId);

        foreach ($videos as $video) {
            $this->course->lessons()->create([
                'course_module_id' => $moduleId,
                'title' => mb_substr($video['title'], 0, 200),
                'youtube_id' => $video['id'],
                'duration_minutes' => $video['minutes'],
                'sort' => $sort++,
            ]);
        }

        $this->modal('import-playlist')->close();
        $this->done('Imported '.count($videos).' '.str('video')->plural(count($videos)).' as lessons. Add notes to any lesson by clicking Edit.');
    }

    private function lesson(int $id): Lesson
    {
        return $this->course->lessons()->findOrFail($id);
    }

    private function nextLessonSort(?int $moduleId): int
    {
        return (int) $this->course->lessons()->where('course_module_id', $moduleId)->max('sort') + 1;
    }

    // ---------- Quiz ----------

    public function newQuestion(): void
    {
        $this->reset('questionId', 'questionText', 'questionOptions', 'questionCorrect', 'questionExplanation');
        $this->resetErrorBag();
        $this->modal('question-form')->show();
    }

    public function editQuestion(int $id): void
    {
        $q = $this->course->questions()->findOrFail($id);
        $this->questionId = $q->id;
        $this->questionText = $q->question;
        $this->questionOptions = array_pad($q->options, 2, '');
        $this->questionCorrect = $q->correct_option;
        $this->questionExplanation = (string) $q->explanation;
        $this->resetErrorBag();
        $this->modal('question-form')->show();
    }

    public function addOption(): void
    {
        if (count($this->questionOptions) < 6) {
            $this->questionOptions[] = '';
        }
    }

    public function removeOption(int $index): void
    {
        if (count($this->questionOptions) > 2) {
            array_splice($this->questionOptions, $index, 1);
            $this->questionCorrect = min((int) $this->questionCorrect, count($this->questionOptions) - 1);
        }
    }

    public function saveQuestion(): void
    {
        $this->questionOptions = array_map('trim', $this->questionOptions);

        $this->validate([
            'questionText' => ['required', 'string', 'max:1000'],
            'questionOptions' => ['array', 'min:2', 'max:6'],
            'questionOptions.*' => ['required', 'string', 'max:300'],
            'questionCorrect' => ['required', 'integer', 'min:0', 'max:'.(count($this->questionOptions) - 1)],
            'questionExplanation' => ['nullable', 'string', 'max:1000'],
        ], ['questionOptions.*.required' => 'Fill in every answer, or remove the empty ones.']);

        $attributes = [
            'question' => $this->questionText,
            'options' => array_values($this->questionOptions),
            'correct_option' => (int) $this->questionCorrect,
            'explanation' => $this->questionExplanation ?: null,
        ];

        if ($this->questionId) {
            $this->course->questions()->findOrFail($this->questionId)->update($attributes);
        } else {
            $this->course->questions()->create($attributes + ['sort' => (int) $this->course->questions()->max('sort') + 1]);
        }

        $this->modal('question-form')->close();
        $this->done($this->questionId ? 'Question updated.' : 'Question added.');
    }

    public function deleteQuestion(int $id): void
    {
        $this->course->questions()->findOrFail($id)->delete();
        $this->done('Question deleted.');
    }

    public function moveQuestion(int $id, int $direction): void
    {
        $this->swap($this->course->questions()->get(), $id, $direction);
    }

    // ---------- Students ----------

    public function toggleCertificate(int $id): void
    {
        $certificate = $this->course->certificates()->findOrFail($id);
        $certificate->update(['revoked_at' => $certificate->revoked_at ? null : now()]);
    }

    // ---------- Helpers ----------

    /** Swap an item's sort position with its neighbour (renumbering first so positions are always distinct). */
    private function swap($items, int $id, int $direction): void
    {
        $items = $items->values();
        $items->each(fn ($item, $i) => $item->sort !== $i ? $item->update(['sort' => $i]) : null);

        $index = $items->search(fn ($item) => $item->id === $id);
        $other = $index === false ? null : $items->get($index + $direction);

        if ($other) {
            [$a, $b] = [$items[$index]->sort, $other->sort];
            $items[$index]->update(['sort' => $b]);
            $other->update(['sort' => $a]);
        }
    }

    public function with(): array
    {
        $modules = $this->course->modules()->with('lessons')->get();

        return [
            'modules' => $modules,
            'looseLessons' => $this->course->lessons()->whereNull('course_module_id')->orderBy('sort')->orderBy('id')->get(),
            'questions' => $this->course->questions()->get(),
            'lessonCount' => $this->course->lessons()->count(),
            'students' => $this->tab === 'students'
                ? $this->course->enrollments()->with('user')->latest('updated_at')->get()
                : collect(),
            'certificates' => $this->tab === 'students'
                ? $this->course->certificates()->get()->keyBy('user_id')
                : collect(),
            'hasApiKey' => app(YouTubeCatalog::class)->hasApiKey(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('admin.courses')" wire:navigate class="text-sm">← All courses</flux:link>
            <flux:heading size="xl" class="mt-1">{{ $course->title }}</flux:heading>
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($course->is_published)
                    <flux:badge size="sm" color="green">Published</flux:badge>
                @else
                    <flux:badge size="sm">Draft</flux:badge>
                @endif
                <flux:badge size="sm" color="sky">{{ $course->levelLabel() }}</flux:badge>
                <flux:badge size="sm">{{ $lessonCount }} {{ Str::plural('lesson', $lessonCount) }}</flux:badge>
                <flux:badge size="sm">{{ $questions->count() }} quiz {{ Str::plural('question', $questions->count()) }}</flux:badge>
            </div>
        </div>
        <div class="flex gap-2">
            @if ($course->is_published)
                <flux:button :href="route('courses.show', $course)" target="_blank" icon="arrow-top-right-on-square">View public page</flux:button>
            @endif
        </div>
    </div>

    <flux:radio.group wire:model.live="tab" variant="segmented">
        <flux:radio value="lessons" label="Lessons" />
        <flux:radio value="details" label="Details" />
        <flux:radio value="quiz" label="Final quiz" />
        <flux:radio value="students" label="Students" />
    </flux:radio.group>

    {{-- ===================== DETAILS ===================== --}}
    @if ($tab === 'details')
        <form wire:submit="saveDetails" class="flex max-w-3xl flex-col gap-4">
            <flux:input wire:model="title" label="Title" required />
            <flux:input wire:model="slug" label="Web address" :description="'Shown as '.url('/courses').'/'.$slug.'. Changing it breaks links people already shared.'" required />
            <flux:input wire:model="summary" label="Short summary" description="One or two sentences for course cards (max 300 characters)." />
            <flux:textarea wire:model="description" label="Full description" rows="6" description="What students will learn and who it’s for. Markdown is supported (**bold**, lists with -)." />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="level" label="Level">
                    @foreach (\App\Models\Course::LEVELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="passMark" type="number" min="1" max="100" label="Pass mark (%)" description="For the final quiz." />
                <flux:input wire:model="thumbnailUrl" type="url" label="Cover image URL (optional)" description="Empty = first video’s thumbnail." />
            </div>
            <div class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900">
                <flux:switch wire:model="isPublished" label="Published" description="Visible to students and on the public courses page." />
                <flux:error name="isPublished" />
                <flux:switch wire:model="isFeatured" label="Featured" description="Shown first and highlighted." />
                <flux:switch wire:model="certificateEnabled" label="Award a certificate" description="After all lessons are completed and the final quiz is passed (or just all lessons, if there’s no quiz)." />
            </div>
            <div class="flex items-center justify-between">
                <flux:button type="submit" variant="primary">Save details</flux:button>
                <flux:button variant="danger" icon="trash" wire:click="deleteCourse"
                    wire:confirm="Delete this course with all its lessons, quiz, student progress and certificates? This can’t be undone.">Delete course</flux:button>
            </div>
        </form>
    @endif

    {{-- ===================== LESSONS ===================== --}}
    @if ($tab === 'lessons')
        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="primary" icon="plus" wire:click="newLesson">Add lesson</flux:button>
            <flux:modal.trigger name="import-playlist">
                <flux:button icon="queue-list">Import YouTube playlist</flux:button>
            </flux:modal.trigger>
            <form wire:submit="addModule" class="ms-auto flex gap-2">
                <flux:input wire:model="newModule" placeholder="New module title…" class="w-56" />
                <flux:button type="submit" icon="folder-plus">Add module</flux:button>
            </form>
        </div>
        <flux:error name="newModule" />

        @foreach ($modules as $module)
            <div wire:key="module-{{ $module->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    @if ($renamingModule === $module->id)
                        <form wire:submit="renameModule" class="flex flex-1 gap-2">
                            <flux:input wire:model="moduleTitle" size="sm" class="flex-1" />
                            <flux:button size="sm" type="submit" variant="primary">Save</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="$set('renamingModule', null)">Cancel</flux:button>
                        </form>
                    @else
                        <flux:heading class="flex-1">{{ $module->title }}</flux:heading>
                        <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveModule({{ $module->id }}, -1)" aria-label="Move up" />
                        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveModule({{ $module->id }}, 1)" aria-label="Move down" />
                        <flux:button size="xs" variant="ghost" icon="pencil" wire:click="startRename({{ $module->id }})">Rename</flux:button>
                        <flux:button size="xs" variant="ghost" icon="plus" wire:click="newLesson({{ $module->id }})">Lesson</flux:button>
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteModule({{ $module->id }})" wire:confirm="Delete this module? Its lessons are kept under “Other lessons”." />
                    @endif
                </div>
                @include('partials.academy.admin-lesson-rows', ['lessons' => $module->lessons])
            </div>
        @endforeach

        @if ($looseLessons->isNotEmpty() || $modules->isEmpty())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading>{{ $modules->isEmpty() ? 'Lessons' : 'Other lessons' }}</flux:heading>
                </div>
                @include('partials.academy.admin-lesson-rows', ['lessons' => $looseLessons])
            </div>
        @endif
    @endif

    {{-- ===================== QUIZ ===================== --}}
    @if ($tab === 'quiz')
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:text class="max-w-2xl text-sm">
                Students take the final quiz after completing every lesson. They pass with {{ $course->pass_mark }}% or more
                (change it under Details), can retake it as often as they like, and see explanations for wrong answers.
                @if ($questions->isEmpty())
                    <strong>Without questions, the certificate is awarded as soon as all lessons are completed.</strong>
                @endif
            </flux:text>
            <flux:button variant="primary" icon="plus" wire:click="newQuestion">Add question</flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse ($questions as $i => $q)
                <div wire:key="q-{{ $q->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold dark:bg-zinc-800">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $q->question }}</div>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($q->options as $o => $option)
                                    <li @class(['flex items-center gap-2', 'font-medium text-green-700 dark:text-green-400' => $o === $q->correct_option])>
                                        <flux:icon :name="$o === $q->correct_option ? 'check-circle' : 'minus'" variant="micro" /> {{ $option }}
                                    </li>
                                @endforeach
                            </ul>
                            @if ($q->explanation)
                                <flux:text class="mt-2 text-xs">Explanation: {{ $q->explanation }}</flux:text>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveQuestion({{ $q->id }}, -1)" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveQuestion({{ $q->id }}, 1)" />
                            <flux:button size="xs" variant="ghost" icon="pencil" wire:click="editQuestion({{ $q->id }})" />
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteQuestion({{ $q->id }})" wire:confirm="Delete this question?" />
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                    <flux:text>No quiz questions yet.</flux:text>
                </div>
            @endforelse
        </div>
    @endif

    {{-- ===================== STUDENTS ===================== --}}
    @if ($tab === 'students')
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Student</th>
                        <th class="px-4 py-3 font-medium">Progress</th>
                        <th class="px-4 py-3 font-medium">Last active</th>
                        <th class="px-4 py-3 font-medium">Certificate</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($students as $enrollment)
                        @php($progress = $course->progressFor($enrollment->user))
                        @php($cert = $certificates->get($enrollment->user_id))
                        <tr wire:key="student-{{ $enrollment->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $enrollment->user->name }}</div>
                                <div class="text-zinc-500">{{ $enrollment->user->email }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div class="h-full rounded-full bg-green-500" style="width: {{ $progress['percent'] }}%"></div>
                                    </div>
                                    <span class="tabular-nums text-zinc-500">{{ $progress['done'] }}/{{ $progress['total'] }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-500">{{ $enrollment->updated_at->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                @if ($cert)
                                    <a href="{{ route('certificates.show', $cert) }}" target="_blank" class="font-mono text-xs underline">{{ $cert->code }}</a>
                                    @if (! $cert->isValid())
                                        <flux:badge size="sm" color="red">Revoked</flux:badge>
                                    @endif
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($cert)
                                    <flux:button size="xs" :variant="$cert->isValid() ? 'danger' : 'filled'" wire:click="toggleCertificate({{ $cert->id }})"
                                        wire:confirm="{{ $cert->isValid() ? 'Revoke this certificate? Its verification page will show it as revoked.' : 'Restore this certificate?' }}">
                                        {{ $cert->isValid() ? 'Revoke' : 'Restore' }}
                                    </flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">No students have started this course yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ===================== MODALS ===================== --}}
    <flux:modal name="lesson-form" class="w-full max-w-2xl">
        <form wire:submit="saveLesson" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $lessonId ? 'Edit lesson' : 'Add lesson' }}</flux:heading>

            <flux:field>
                <flux:label>YouTube video (optional)</flux:label>
                <div class="flex gap-2">
                    <flux:input wire:model="lessonVideo" placeholder="https://www.youtube.com/watch?v=… or https://youtu.be/…" class="flex-1" />
                    <flux:button wire:click="fetchVideo" icon="arrow-down-tray">Get details</flux:button>
                </div>
                <flux:description>Any public or unlisted video. “Get details” fills in the title{{ $hasApiKey ? ' and length' : '' }}.</flux:description>
                <flux:error name="lessonVideo" />
            </flux:field>
            @if ($previewId = \App\Support\YouTube::videoId($lessonVideo))
                <img src="{{ \App\Support\YouTube::thumbnail($previewId) }}" alt="" class="w-48 rounded-lg" referrerpolicy="no-referrer">
            @endif

            <flux:input wire:model="lessonTitle" label="Lesson title" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="lessonModuleId" label="Module">
                    <option value="">No module</option>
                    @foreach ($modules as $module)
                        <option value="{{ $module->id }}">{{ $module->title }}</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="lessonMinutes" type="number" min="1" label="Length (minutes)" />
            </div>
            <flux:textarea wire:model="lessonContent" label="Lesson notes" rows="8"
                description="Shown under the video (or on their own for a reading lesson). Markdown: **bold**, ## headings, - lists, [links](https://…)." />
            <flux:error name="lessonContent" />
            <flux:checkbox wire:model="lessonPreview" label="Free preview" description="Anyone can watch this lesson on the public course page, even without an account. Great for attracting sign-ups." />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save lesson</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="import-playlist" class="w-full max-w-lg">
        <form wire:submit="importPlaylist" class="flex flex-col gap-4">
            <flux:heading size="lg">Import a YouTube playlist</flux:heading>
            <flux:text class="text-sm">Every video in the playlist becomes a lesson, in playlist order, with its title and length.</flux:text>
            @unless ($hasApiKey)
                <flux:callout variant="warning" icon="key" heading="Needs a YouTube API key">
                    <flux:callout.text>Add one in Admin → Settings → YouTube (“API key”), then come back.</flux:callout.text>
                </flux:callout>
            @endunless
            <flux:input wire:model="playlistUrl" label="Playlist link" placeholder="https://www.youtube.com/playlist?list=PL…" />
            <flux:error name="playlistUrl" />
            <flux:select wire:model="playlistModuleId" label="Add to module">
                <option value="">No module</option>
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" :disabled="! $hasApiKey">Import</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="question-form" class="w-full max-w-xl">
        <form wire:submit="saveQuestion" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $questionId ? 'Edit question' : 'Add question' }}</flux:heading>
            <flux:textarea wire:model="questionText" label="Question" rows="2" required />
            <flux:field>
                <flux:label>Answers (choose the correct one)</flux:label>
                <div class="flex flex-col gap-2">
                    @foreach ($questionOptions as $i => $option)
                        <div wire:key="opt-{{ $i }}" class="flex items-center gap-2">
                            <input type="radio" wire:model="questionCorrect" value="{{ $i }}" class="size-4" aria-label="Correct answer {{ $i + 1 }}">
                            <flux:input wire:model="questionOptions.{{ $i }}" placeholder="Answer {{ $i + 1 }}" class="flex-1" />
                            @if (count($questionOptions) > 2)
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeOption({{ $i }})" aria-label="Remove answer" />
                            @endif
                        </div>
                    @endforeach
                </div>
                @if (count($questionOptions) < 6)
                    <flux:button size="xs" variant="ghost" icon="plus" wire:click="addOption" class="mt-1 self-start">Add answer</flux:button>
                @endif
                <flux:error name="questionOptions" />
                <flux:error name="questionOptions.*" />
            </flux:field>
            <flux:textarea wire:model="questionExplanation" label="Explanation (optional)" rows="2" description="Shown to students who get it wrong." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save question</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
