<?php

namespace App\Models;

use App\Support\YouTube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Course extends Model
{
    public const LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    protected $fillable = [
        'title', 'slug', 'summary', 'description', 'level', 'thumbnail_url',
        'is_published', 'is_featured', 'certificate_enabled', 'pass_mark', 'sort',
    ];

    /** Same defaults as the database, so a freshly created course has them before it is re-read. */
    protected $attributes = [
        'level' => 'beginner',
        'is_published' => false,
        'is_featured' => false,
        'certificate_enabled' => true,
        'pass_mark' => 70,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'certificate_enabled' => 'boolean',
            'pass_mark' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The web address is made once from the title; renaming the course later keeps old links working.
        static::saving(function (Course $course) {
            if (blank($course->slug)) {
                $course->slug = static::uniqueSlug($course->title, $course->id);
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'course';
        // 'certificates' is a fixed page under /academy, so a course can't use it.
        $base = $base === 'certificates' ? 'certificates-course' : $base;
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->orderByDesc('is_featured')->orderBy('sort')->orderBy('id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort')->orderBy('id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort')->orderBy('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** Lessons in study order: by module, then by position inside the module. Lessons without a module come last. */
    public function orderedLessons(): Collection
    {
        return $this->lessons()->with('module')->get()
            ->sortBy(fn (Lesson $l) => sprintf('%010d-%010d-%010d', $l->module?->sort ?? 999999, $l->sort, $l->id))
            ->values();
    }

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? ucfirst($this->level);
    }

    public function totalMinutes(): int
    {
        return (int) $this->lessons->sum('duration_minutes');
    }

    public function durationLabel(): ?string
    {
        $m = $this->totalMinutes();

        return $m ? ($m >= 60 ? floor($m / 60).'h '.($m % 60 ? ($m % 60).'m' : '') : $m.' min') : null;
    }

    /** Cover image: the uploaded/linked one, else the first video's YouTube thumbnail. */
    public function coverUrl(): ?string
    {
        if ($this->thumbnail_url) {
            return $this->thumbnail_url;
        }

        $first = $this->orderedLessons()->firstWhere('youtube_id', '!=', null);

        return $first ? YouTube::thumbnail($first->youtube_id) : null;
    }

    /** @return array{done: int, total: int, percent: int} */
    public function progressFor(?User $user): array
    {
        $total = $this->lessons()->count();
        $done = $user ? LessonProgress::where('user_id', $user->id)->whereIn('lesson_id', $this->lessons()->select('id'))->count() : 0;

        return ['done' => $done, 'total' => $total, 'percent' => $total ? (int) floor($done / $total * 100) : 0];
    }

    public function hasQuiz(): bool
    {
        return $this->questions()->exists();
    }
}
