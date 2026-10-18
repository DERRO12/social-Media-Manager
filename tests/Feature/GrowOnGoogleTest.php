<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\BusinessProfile;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\User;
use App\Services\Academy\CourseLibrary;
use App\Services\Seo\SafeFetcher;
use App\Services\Seo\SeoAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class GrowOnGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** A fetcher that resolves every hostname to a public IP, so tests don't need real DNS. */
    private function fakeDns(string $ip = '93.184.216.34'): void
    {
        $this->app->instance(SafeFetcher::class, new class($ip) extends SafeFetcher
        {
            public function __construct(private string $ip) {}

            protected function lookup(string $host): array
            {
                return [$this->ip];
            }
        });
    }

    // ---------- Course library ----------

    public function test_the_library_has_a_full_academy_in_study_order(): void
    {
        $library = app(CourseLibrary::class)->all();

        $this->assertCount(8, $library);
        $this->assertSame('social-media-marketing-foundations', array_key_first($library));
        $this->assertSame('tiktok-growth-going-viral', array_keys($library)[1]);

        foreach ($library as $slug => $course) {
            $this->assertNotEmpty($course['modules'], $slug);
            foreach ($course['questions'] as [$q, $options, $correct]) {
                $this->assertArrayHasKey($correct, $options, "{$slug}: {$q}");
            }
        }
    }

    public function test_admin_adds_and_publishes_the_whole_library(): void
    {
        Volt::actingAs($this->admin())->test('admin.courses')
            ->assertSee('Course library: 8 ready-made courses')
            ->call('installAll', true);

        $this->assertSame(8, Course::where('is_published', true)->count());
        $this->assertSame(56, \App\Models\Lesson::count());

        $tiktok = Course::firstWhere('slug', 'tiktok-growth-going-viral');
        $this->assertSame(8, $tiktok->questions()->count());
        $this->get('/courses')->assertSee('TikTok Growth: Going Viral the Smart Way')->assertSee('Google Business Profile and Local SEO');
    }

    public function test_one_library_course_can_be_added(): void
    {
        Volt::actingAs($this->admin())->test('admin.courses')->call('installFromLibrary', 'google-business-profile-and-local-seo');

        $this->assertSame(['google-business-profile-and-local-seo'], Course::pluck('slug')->all());
        $this->assertFalse(Course::sole()->is_published);
    }

    // ---------- Business Profile builder ----------

    public function test_business_profile_builder_saves_scores_and_writes_a_description(): void
    {
        $client = User::factory()->create(['name' => 'Amara Studio']);

        Volt::actingAs($client)->test('grow.business')
            ->set('primaryCategory', 'Hair salon')
            ->set('city', 'Kampala')
            ->set('services', "Braiding\nBridal makeup")
            ->set('keywords', 'natural products, friendly staff')
            ->call('draft')
            ->assertSet('description', fn ($d) => str_contains($d, 'Amara Studio is a hair salon in Kampala') && str_contains($d, 'Braiding and Bridal makeup'))
            ->set('placeId', 'ChIJabc123_-')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('https://search.google.com/local/writereview?placeid=ChIJabc123_-', false)
            ->call('toggle', 'created');

        $profile = BusinessProfile::sole();
        $this->assertSame(['Braiding', 'Bridal makeup'], $profile->services);
        $this->assertTrue($profile->checklist['created']);
        $this->assertGreaterThan(30, $profile->completeness()['score']);
    }

    public function test_description_is_limited_to_google_s_750_characters(): void
    {
        Volt::actingAs(User::factory()->create())->test('grow.business')
            ->set('description', str_repeat('a', 751))->call('save')->assertHasErrors('description');
    }

    // ---------- SEO checker ----------

    public function test_seo_checker_scores_a_page_and_explains_fixes(): void
    {
        $this->fakeDns();
        Http::fake([
            'https://shop.example/' => Http::response(
                '<html lang="en"><head><title>Hi</title><meta name="viewport" content="width=device-width"></head>'
                .'<body><h1>Welcome</h1><img src="a.jpg"><p>Short page</p></body></html>',
                200, ['Content-Type' => 'text/html'],
            ),
            'https://shop.example/robots.txt' => Http::response("User-agent: *\nDisallow:\nSitemap: https://shop.example/sitemap.xml"),
            'https://shop.example/sitemap.xml' => Http::response('<urlset></urlset>'),
        ]);

        $result = app(SeoAuditor::class)->audit('shop.example/');
        $checks = collect($result['checks'])->keyBy('key');

        $this->assertSame('pass', $checks['https']['status']);
        $this->assertSame('warn', $checks['title']['status']);
        $this->assertSame('fail', $checks['description']['status']);
        $this->assertSame('pass', $checks['h1']['status']);
        $this->assertSame('fail', $checks['alt']['status']);
        $this->assertSame('pass', $checks['sitemap']['status']);
        $this->assertNotNull($checks['description']['fix']);
        $this->assertGreaterThan(0, $result['score']);
        $this->assertLessThan(100, $result['score']);
    }

    public function test_seo_checker_refuses_private_and_internal_addresses(): void
    {
        foreach (['http://127.0.0.1/', 'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/', 'file:///etc/passwd', 'http://192.168.1.1:22/'] as $url) {
            try {
                app(SafeFetcher::class)->get($url);
                $this->fail("Should have refused {$url}");
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // A public hostname that resolves to a private IP is refused too.
        $this->fakeDns('10.1.2.3');
        $this->expectException(RuntimeException::class);
        app(SafeFetcher::class)->get('https://sneaky.example/');
    }

    public function test_seo_checker_rechecks_every_redirect(): void
    {
        $this->fakeDns();
        Http::fake(['https://ok.example/' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        $this->expectExceptionMessage('private network');
        app(SafeFetcher::class)->get('https://ok.example/');
    }

    public function test_seo_page_saves_history_and_is_rate_limited(): void
    {
        $this->fakeDns();
        Http::fake(['*' => Http::response('<html><head><title>A good page title for the checker test</title></head><body><h1>Hi</h1></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $client = User::factory()->create();

        $page = Volt::actingAs($client)->test('grow.seo')->set('url', 'https://site.example');
        for ($i = 0; $i < 10; $i++) {
            $page->call('check')->assertHasNoErrors();
        }
        $page->call('check')->assertHasErrors('url');

        $this->assertSame(10, $client->seoAudits()->count());
    }

    // ---------- Public pages ----------

    private function clientWithContent(): array
    {
        $client = User::factory()->create(['name' => 'Kofi Fitness']);
        $account = $client->socialAccounts()->create(['platform' => Platform::YouTube, 'platform_user_id' => 'UCk', 'name' => 'Kofi', 'access_token' => 't']);
        $video = $account->contentItems()->create([
            'platform_content_id' => 'dQw4w9WgXcQ', 'title' => '10 minute home workout', 'caption' => 'No equipment needed.',
            'permalink' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            'published_at' => now()->subDay(), 'views' => 1200,
        ]);
        $hidden = $account->contentItems()->create(['platform_content_id' => 'x2', 'title' => 'Secret clip', 'permalink' => 'https://youtu.be/x2', 'hidden_from_profile' => true]);

        return [$client, $video, $hidden];
    }

    public function test_client_publishes_a_public_page_that_search_engines_can_read(): void
    {
        [$client, $video, $hidden] = $this->clientWithContent();

        Volt::actingAs($client)->test('grow.page')
            ->set('handle', 'kofi-fitness')
            ->set('headline', 'Home workouts in Kampala')
            ->set('isPublic', true)
            ->call('save')
            ->assertHasNoErrors();
        auth()->logout();

        $this->get('/p/kofi-fitness')
            ->assertOk()
            ->assertSee('Home workouts in Kampala')
            ->assertSee('10 minute home workout')
            ->assertDontSee('Secret clip')
            ->assertSee('"@type":"ProfilePage"', false);

        $this->get("/p/kofi-fitness/v/{$video->id}")->assertRedirect("/p/kofi-fitness/v/{$video->id}/10-minute-home-workout");
        $this->get("/p/kofi-fitness/v/{$video->id}/10-minute-home-workout")
            ->assertOk()
            ->assertSee('"@type":"VideoObject"', false)
            ->assertSee('"uploadDate"', false)
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('<link rel="canonical"', false);
        $this->get("/p/kofi-fitness/v/{$hidden->id}/secret-clip")->assertNotFound();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(url('/p/kofi-fitness'), false)
            ->assertSee("/p/kofi-fitness/v/{$video->id}/10-minute-home-workout", false)
            ->assertDontSee('secret-clip', false);
    }

    public function test_pages_that_are_off_or_whose_plan_ended_are_hidden(): void
    {
        [$client] = $this->clientWithContent();
        $client->publicProfile()->create(['handle' => 'kofi', 'is_public' => false]);

        $this->get('/p/kofi')->assertNotFound();
        $this->actingAs($client)->get('/p/kofi')->assertOk()->assertSee('Preview: this page is off');
        auth()->logout();

        $client->publicProfile->update(['is_public' => true]);
        $this->get('/p/kofi')->assertOk();

        app(\App\Services\Billing\SubscriptionManager::class)->expire($client);
        $this->get('/p/kofi')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/p/kofi', false);
    }

    public function test_handles_must_be_unique_and_not_reserved(): void
    {
        $other = User::factory()->create();
        $other->publicProfile()->create(['handle' => 'taken']);

        $page = Volt::actingAs(User::factory()->create())->test('grow.page');
        $page->set('handle', 'taken')->call('save')->assertHasErrors('handle');
        $page->set('handle', 'admin')->call('save')->assertHasErrors('handle');
        $page->set('handle', 'Bad Handle!')->call('save')->assertHasErrors('handle');
    }

    public function test_clients_can_hide_their_own_items_only(): void
    {
        [$client, $video] = $this->clientWithContent();
        $intruder = User::factory()->create();

        Volt::actingAs($intruder)->test('grow.page')->call('toggleItem', $video->id)->assertNotFound();
        Volt::actingAs($client)->test('grow.page')->call('toggleItem', $video->id);

        $this->assertTrue($video->fresh()->hidden_from_profile);
    }

    public function test_robots_txt_points_to_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('/sitemap.xml'))->assertSee('Disallow: /admin');
    }
}
