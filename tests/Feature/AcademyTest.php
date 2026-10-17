<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Services\Academy\StarterCourse;
use App\Support\YouTube;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AcademyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** A published course: 2 lessons (first is a free YouTube preview) and a 2-question quiz. */
    private function course(bool $withQuiz = true): Course
    {
        $course = Course::create(['title' => 'Reels Mastery', 'summary' => 'Make better Reels', 'is_published' => true, 'pass_mark' => 50]);
        $module = $course->modules()->create(['title' => 'Basics', 'sort' => 0]);
        $course->lessons()->create(['course_module_id' => $module->id, 'title' => 'Hooks', 'youtube_id' => 'dQw4w9WgXcQ', 'duration_minutes' => 5, 'is_preview' => true, 'sort' => 0]);
        $course->lessons()->create(['course_module_id' => $module->id, 'title' => 'Captions', 'content' => '## Write a hook', 'duration_minutes' => 4, 'sort' => 1]);

        if ($withQuiz) {
            $course->questions()->create(['question' => 'Best hook length?', 'options' => ['2 seconds', '2 minutes'], 'correct_option' => 0, 'explanation' => 'Short wins.', 'sort' => 0]);
            $course->questions()->create(['question' => 'Captions help viewers who…', 'options' => ['watch muted', 'never watch'], 'correct_option' => 0, 'sort' => 1]);
        }

        return $course;
    }

    public function test_youtube_links_of_every_shape_are_understood(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
            'dQw4w9WgXcQ',
        ] as $link) {
            $this->assertSame('dQw4w9WgXcQ', YouTube::videoId($link), $link);
        }

        $this->assertNull(YouTube::videoId('https://example.com/video'));
        $this->assertSame('PLabcdefghij123', YouTube::playlistId('https://www.youtube.com/playlist?list=PLabcdefghij123'));
    }

    public function test_guests_see_the_public_catalog_and_free_previews_only(): void
    {
        $course = $this->course();
        Course::create(['title' => 'Secret draft']);

        $this->get('/courses')->assertOk()->assertSee('Reels Mastery')->assertDontSee('Secret draft');
        $this->get("/courses/{$course->slug}")
            ->assertOk()
            ->assertSee('Free preview')
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('Captions');

        $lesson = $course->lessons()->firstWhere('title', 'Captions');
        $this->get("/academy/{$course->slug}/lessons/{$lesson->id}")->assertRedirect('/login');
    }

    public function test_drafts_are_hidden_from_clients_but_visible_to_admin(): void
    {
        $draft = Course::create(['title' => 'Draft course']);

        $this->get("/courses/{$draft->slug}")->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/academy/{$draft->slug}")->assertNotFound();
        $this->actingAs($this->admin())->get("/courses/{$draft->slug}")->assertOk();
    }

    public function test_students_progress_through_lessons_then_quiz_then_certificate(): void
    {
        $course = $this->course();
        [$first, $second] = $course->orderedLessons()->all();
        $student = User::factory()->create(['name' => 'Ama Owusu']);
        $this->actingAs($student);

        $this->get('/academy')->assertOk()->assertSee('Reels Mastery');
        $this->get("/academy/{$course->slug}/lessons/{$first->id}")->assertOk()->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false);

        // Quiz stays locked until every lesson is done.
        Volt::test('academy.quiz', ['course' => $course])->assertSee('Finish all lessons first');

        Volt::test('academy.lesson', ['course' => $course, 'lesson' => $first])->call('complete')
            ->assertRedirect(route('academy.lesson', [$course, $second]));
        Volt::test('academy.lesson', ['course' => $course, 'lesson' => $second])->call('complete')
            ->assertRedirect(route('academy.quiz', $course));

        $this->assertSame(100, $course->progressFor($student)['percent']);

        // Failing gives explanations and no certificate.
        $q = $course->questions()->get();
        Volt::test('academy.quiz', ['course' => $course])
            ->set("answers.{$q[0]->id}", 1)->set("answers.{$q[1]->id}", 1)
            ->call('submit')
            ->assertSee('0%')->assertSee('Short wins.');
        $this->assertSame(0, Certificate::count());

        // Passing issues the certificate.
        Volt::test('academy.quiz', ['course' => $course])
            ->set("answers.{$q[0]->id}", 0)->set("answers.{$q[1]->id}", 0)
            ->call('submit')
            ->assertSee('Congratulations, you passed!');

        $certificate = Certificate::sole();
        $this->assertSame('Ama Owusu', $certificate->recipient_name);
        $this->assertSame(100, $certificate->score);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $certificate->code);
    }

    public function test_unanswered_quizzes_and_locked_quizzes_are_refused(): void
    {
        $course = $this->course();
        $this->actingAs(User::factory()->create());

        Volt::test('academy.quiz', ['course' => $course])->call('submit')->assertForbidden();
    }

    public function test_a_course_without_quiz_certifies_when_all_lessons_are_done(): void
    {
        $course = $this->course(withQuiz: false);
        $student = User::factory()->create();
        $this->actingAs($student);

        foreach ($course->orderedLessons() as $lesson) {
            Volt::test('academy.lesson', ['course' => $course, 'lesson' => $lesson])->call('complete');
        }

        $this->assertSame(1, $student->certificates()->count());
    }

    public function test_certificate_page_is_public_and_shows_revocation(): void
    {
        Setting::put('academy.issuer', 'Gokatale Academy');
        $course = $this->course();
        $certificate = app(\App\Services\Academy\Academy::class)->issueCertificate(User::factory()->create(['name' => 'Kofi B']), $course, 90);

        $this->get("/certificates/{$certificate->code}")
            ->assertOk()
            ->assertSee('Certificate of Completion')
            ->assertSee('Kofi B')
            ->assertSee('Reels Mastery')
            ->assertSee('Gokatale Academy')
            ->assertSee('Verified certificate');

        $certificate->update(['revoked_at' => now()]);
        $this->get("/certificates/{$certificate->code}")->assertSee('This certificate has been revoked');
        $this->get('/certificates/XX-NOPE-NOPE')->assertNotFound();

        $this->assertStringContainsString('startTask=CERTIFICATION_NAME', $certificate->linkedInUrl());
    }

    public function test_admin_builds_a_course_with_youtube_lessons_and_quiz(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'How to film Reels'])]);
        $this->actingAs($this->admin());

        Volt::test('admin.courses')->set('title', 'Instagram Growth')->call('create');
        $course = Course::firstWhere('title', 'Instagram Growth');
        $this->assertSame('instagram-growth', $course->slug);
        $this->assertFalse($course->is_published);

        Volt::test('admin.course', ['course' => $course])
            ->set('newModule', 'Getting started')->call('addModule')
            ->set('lessonVideo', 'https://youtu.be/dQw4w9WgXcQ')->call('fetchVideo')
            ->assertSet('lessonTitle', 'How to film Reels')
            ->set('lessonModuleId', (string) $course->modules()->first()->id)
            ->set('lessonPreview', true)
            ->call('saveLesson')
            ->assertHasNoErrors();

        $lesson = $course->lessons()->sole();
        $this->assertSame('dQw4w9WgXcQ', $lesson->youtube_id);
        $this->assertTrue($lesson->is_preview);

        Volt::test('admin.course', ['course' => $course])
            ->set('questionText', 'Q?')
            ->set('questionOptions', ['Yes', 'No', 'Maybe'])
            ->set('questionCorrect', 2)
            ->call('saveQuestion')
            ->assertHasNoErrors();
        $this->assertSame(2, $course->questions()->sole()->correct_option);

        Volt::test('admin.course', ['course' => $course])->set('isPublished', true)->call('saveDetails')->assertHasNoErrors();
        $this->assertTrue($course->fresh()->is_published);
    }

    public function test_a_lesson_needs_a_video_or_notes_and_a_valid_youtube_link(): void
    {
        $course = Course::create(['title' => 'X']);
        $this->actingAs($this->admin());

        Volt::test('admin.course', ['course' => $course])
            ->set('lessonTitle', 'Empty')->call('saveLesson')->assertHasErrors('lessonContent')
            ->set('lessonVideo', 'https://vimeo.com/123')->call('saveLesson')->assertHasErrors('lessonVideo');
    }

    public function test_playlist_import_creates_lessons_in_order(): void
    {
        Setting::put('youtube.api_key', 'key', encrypt: true);
        Http::fake([
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [
                ['contentDetails' => ['videoId' => 'aaaaaaaaaaa']],
                ['contentDetails' => ['videoId' => 'bbbbbbbbbbb']],
            ]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'bbbbbbbbbbb', 'snippet' => ['title' => 'Part 2'], 'contentDetails' => ['duration' => 'PT9M30S']],
                ['id' => 'aaaaaaaaaaa', 'snippet' => ['title' => 'Part 1'], 'contentDetails' => ['duration' => 'PT4M']],
            ]]),
        ]);
        $course = Course::create(['title' => 'Imported']);
        $this->actingAs($this->admin());

        Volt::test('admin.course', ['course' => $course])
            ->set('playlistUrl', 'https://www.youtube.com/playlist?list=PLxyz1234567890')
            ->call('importPlaylist')
            ->assertHasNoErrors();

        $this->assertSame(['Part 1', 'Part 2'], $course->orderedLessons()->pluck('title')->all());
        $this->assertSame([4, 10], $course->orderedLessons()->pluck('duration_minutes')->all());
    }

    public function test_starter_course_installs_once_with_content_and_quiz(): void
    {
        $this->actingAs($this->admin());

        Volt::test('admin.courses')->call('installStarter');
        Volt::test('admin.courses')->call('installStarter');

        $course = Course::sole();
        $this->assertSame(StarterCourse::SLUG, $course->slug);
        $this->assertSame(4, $course->modules()->count());
        $this->assertSame(12, $course->lessons()->count());
        $this->assertSame(12, $course->questions()->count());
        $this->assertFalse($course->is_published);
        $this->assertStringContainsString('<table>', $course->orderedLessons()[2]->contentHtml()->toHtml());
    }

    public function test_clients_cannot_reach_course_admin(): void
    {
        $course = Course::create(['title' => 'X']);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/courses')->assertForbidden();
        $this->get("/admin/courses/{$course->id}")->assertForbidden();
        $this->get('/admin/certificates')->assertForbidden();
    }

    public function test_lesson_notes_strip_raw_html(): void
    {
        $course = $this->course();
        $lesson = $course->lessons()->firstWhere('title', 'Captions');
        $lesson->update(['content' => "Hello <script>alert(1)</script> **bold**"]);

        $html = $lesson->contentHtml()->toHtml();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }
}
