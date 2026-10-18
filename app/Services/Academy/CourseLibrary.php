<?php

namespace App\Services\Academy;

use App\Models\Course;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ready-made courses the admin can add with one click (Admin → Courses → Course library).
 * Each is installed as a draft so the admin can edit text, add YouTube videos, then publish.
 *
 * Course files live in resources/academy/*.php and return:
 *   ['slug', 'title', 'summary', 'description' (Markdown), 'level', 'featured' (optional),
 *    'modules' => [[module title, [[lesson title, minutes, Markdown], …]], …],
 *    'questions' => [[question, [answers…], index of correct answer, explanation], …]]
 */
class CourseLibrary
{
    /** Suggested study order; courses not listed here come after, alphabetically. */
    private const ORDER = [
        StarterCourse::SLUG,
        'tiktok-growth-going-viral',
        'instagram-growth-reels-stories-sales',
        'youtube-and-shorts-for-business',
        'facebook-pages-and-groups-for-business',
        'linkedin-and-x-personal-brand-b2b',
        'create-great-content-with-your-phone',
        'google-business-profile-and-local-seo',
    ];

    /** @return array<string, array> slug => definition, in study order */
    public function all(): array
    {
        $courses = [StarterCourse::SLUG => app(StarterCourse::class)->definition()];

        foreach (glob(resource_path('academy/*.php')) ?: [] as $file) {
            $definition = require $file;
            $courses[$definition['slug']] = $definition;
        }

        uksort($courses, function ($a, $b) {
            $ia = array_search($a, self::ORDER, true);
            $ib = array_search($b, self::ORDER, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        return $courses;
    }

    public function find(string $slug): array
    {
        return $this->all()[$slug] ?? throw new InvalidArgumentException("No library course “{$slug}”.");
    }

    /** Add a library course (or return it if it's already installed). */
    public function install(string $slug): Course
    {
        if ($existing = Course::firstWhere('slug', $slug)) {
            return $existing;
        }

        $def = $this->find($slug);

        return DB::transaction(function () use ($def) {
            $course = Course::create([
                'title' => $def['title'],
                'slug' => $def['slug'],
                'summary' => $def['summary'],
                'description' => trim($def['description']),
                'level' => $def['level'] ?? 'beginner',
                'is_published' => false,
                'is_featured' => $def['featured'] ?? false,
                'certificate_enabled' => true,
                'pass_mark' => 70,
                'sort' => (int) Course::max('sort') + 1,
            ]);

            foreach ($def['modules'] as $m => [$moduleTitle, $lessons]) {
                $module = $course->modules()->create(['title' => $moduleTitle, 'sort' => $m]);

                foreach ($lessons as $l => [$title, $minutes, $content]) {
                    $course->lessons()->create([
                        'course_module_id' => $module->id,
                        'title' => $title,
                        'content' => trim($content),
                        'duration_minutes' => $minutes,
                        // The first lesson is a free preview on the public course page.
                        'is_preview' => $m === 0 && $l === 0,
                        'sort' => $l,
                    ]);
                }
            }

            foreach ($def['questions'] as $i => [$question, $options, $correct, $explanation]) {
                $course->questions()->create([
                    'question' => $question,
                    'options' => $options,
                    'correct_option' => $correct,
                    'explanation' => $explanation,
                    'sort' => $i,
                ]);
            }

            return $course;
        });
    }

    /** Install every library course that isn't installed yet. @return Course[] */
    public function installAll(): array
    {
        return array_map(fn ($slug) => $this->install($slug), array_keys($this->all()));
    }
}
