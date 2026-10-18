<?php

namespace App\Services\Academy;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\QuizAttempt;
use App\Models\User;

/**
 * Student progress: lessons → final quiz → certificate.
 * A course with no quiz questions awards its certificate as soon as every lesson is completed.
 */
class Academy
{
    public function open(User $user, Lesson $lesson): Enrollment
    {
        return Enrollment::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $lesson->course_id],
            ['last_lesson_id' => $lesson->id],
        );
    }

    public function completeLesson(User $user, Lesson $lesson): void
    {
        LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['completed_at' => now()],
        );

        $this->open($user, $lesson);

        $course = $lesson->course;
        if ($this->allLessonsDone($user, $course) && ! $course->hasQuiz()) {
            $this->finish($user, $course, null);
        }
    }

    public function allLessonsDone(User $user, Course $course): bool
    {
        $progress = $course->progressFor($user);

        return $progress['total'] > 0 && $progress['done'] >= $progress['total'];
    }

    /**
     * @param  array<int, int|string|null>  $answers  question id => chosen option index
     */
    public function submitQuiz(User $user, Course $course, array $answers): QuizAttempt
    {
        $questions = $course->questions()->get();
        $correct = $questions->filter(fn ($q) => isset($answers[$q->id]) && (int) $answers[$q->id] === $q->correct_option)->count();
        $score = $questions->count() ? (int) round($correct / $questions->count() * 100) : 0;
        $passed = $score >= $course->pass_mark;

        $attempt = QuizAttempt::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'score' => $score,
            'passed' => $passed,
            'answers' => collect($answers)->only($questions->pluck('id'))->map(fn ($a) => (int) $a)->all(),
        ]);

        if ($passed) {
            $this->finish($user, $course, $score);
        }

        return $attempt;
    }

    private function finish(User $user, Course $course, ?int $score): void
    {
        Enrollment::updateOrCreate(['user_id' => $user->id, 'course_id' => $course->id], ['completed_at' => now()]);

        if ($course->certificate_enabled) {
            $this->issueCertificate($user, $course, $score);
        }
    }

    /** One certificate per student per course; a retake with a better score updates it. */
    public function issueCertificate(User $user, Course $course, ?int $score): Certificate
    {
        $existing = Certificate::where('user_id', $user->id)->where('course_id', $course->id)->first();

        if ($existing) {
            if ($score !== null && $score > (int) $existing->score) {
                $existing->update(['score' => $score]);
            }

            return $existing;
        }

        $certificate = Certificate::create([
            'code' => Certificate::newCode(),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'recipient_name' => $user->name,
            'course_title' => $course->title,
            'score' => $score,
            'issued_at' => now(),
        ]);

        rescue(fn () => $user->notify(new \App\Notifications\CertificateEarned($certificate)));

        return $certificate;
    }
}
