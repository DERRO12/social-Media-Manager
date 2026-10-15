<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TrendingTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithYouTube(string $timezone = 'Africa/Kampala'): User
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $user->socialAccounts()->create([
            'platform' => Platform::YouTube,
            'platform_user_id' => 'UC1',
            'name' => 'My channel',
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
        ]);

        return $user;
    }

    private function fakeChart(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
                [
                    'id' => 'song1',
                    'snippet' => ['title' => 'Big Song', 'channelTitle' => 'Star', 'publishedAt' => '2026-10-14T10:00:00Z'],
                    'statistics' => ['viewCount' => '2500000', 'likeCount' => '90000', 'commentCount' => '4000'],
                    'contentDetails' => ['duration' => 'PT3M45S'],
                ],
                [
                    'id' => 'short1',
                    'snippet' => ['title' => 'Quick Dance', 'channelTitle' => 'Dancer'],
                    'statistics' => ['viewCount' => '800000'],
                    'contentDetails' => ['duration' => 'PT45S'],
                ],
            ]]),
        ]);
    }

    public function test_trending_defaults_to_the_users_country_and_shows_youtube_chart(): void
    {
        $this->fakeChart();
        $this->actingAs($this->clientWithYouTube());

        $this->get('/trending')
            ->assertOk()
            ->assertSee('Trending videos in Uganda')
            ->assertSee('Big Song')
            ->assertSee('2.5M')
            ->assertSee('3:45')
            ->assertSee('TikTok trending sounds');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'chart=mostPopular') && str_contains($r->url(), 'regionCode=UG'));
    }

    public function test_music_category_and_short_filter(): void
    {
        $this->fakeChart();
        $this->actingAs($this->clientWithYouTube());

        Volt::test('trending.index')
            ->set('category', '10')
            ->set('length', 'short')
            ->assertSee('Trending music')
            ->assertSee('Quick Dance')
            ->assertDontSee('Big Song');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'videoCategoryId=10'));
    }

    public function test_category_without_a_chart_shows_a_friendly_message(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['error' => ['message' => 'notFound']], 404)]);
        $this->actingAs($this->clientWithYouTube());

        Volt::test('trending.index')->set('category', '20')->assertSee('has no trending chart for this category');
    }

    public function test_without_a_channel_or_api_key_the_client_is_asked_to_connect(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->get('/trending')->assertOk()->assertSee('Connect a YouTube channel to see trends');
        Http::assertNothingSent();
    }

    public function test_admin_api_key_lets_clients_without_a_channel_see_trends(): void
    {
        $this->fakeChart();
        Setting::put('youtube.api_key', 'AIza-test', encrypt: true);
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/London']));

        $this->get('/trending')->assertOk()->assertSee('in United Kingdom')->assertSee('Big Song');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'key=AIza-test') && ! $r->hasHeader('Authorization'));
    }
}
