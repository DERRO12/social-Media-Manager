<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MarketingToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_links_add_campaign_tags_and_count_clicks_but_not_bots(): void
    {
        $client = User::factory()->create();

        Volt::actingAs($client)->test('links.index')
            ->set('url', 'shop.example/product?id=7#reviews')
            ->set('title', 'December offer')
            ->set('place', 'Instagram bio or story')
            ->set('campaign', 'December Sale')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('December offer')
            ->assertSee('Download (SVG, prints sharp)');

        $link = ShortLink::sole();
        $this->assertSame('https://shop.example/product?id=7&utm_source=instagram&utm_medium=social&utm_campaign=december-sale#reviews', $link->destination());

        $this->get('/go/'.$link->code, ['User-Agent' => 'Mozilla/5.0 (iPhone)'])->assertRedirect($link->destination());
        $this->get('/go/'.$link->code, ['User-Agent' => 'WhatsApp/2.23'])->assertRedirect(); // link preview: not counted
        $this->get('/go/'.strtoupper($link->code), ['User-Agent' => 'Mozilla/5.0'])->assertRedirect();
        $this->get('/go/nothere')->assertNotFound();

        $this->assertSame(2, $link->fresh()->clicks);
        $this->assertSame(2, $link->dailyClicks()->sole()->clicks);
    }

    public function test_links_and_qr_codes_are_private_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $link = $owner->shortLinks()->create(['url' => 'https://example.com']);
        $intruder = User::factory()->create();

        $this->actingAs($owner)->get(route('qr', ['type' => 'link', 'link' => $link->id]))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
        $this->actingAs($owner)->get(route('qr', ['type' => 'link', 'link' => $link->id, 'download' => 1]))
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-'.$link->code.'.svg"');

        $this->actingAs($intruder)->get(route('qr', ['type' => 'link', 'link' => $link->id]))->assertNotFound();
        Volt::actingAs($intruder)->test('links.index')->call('delete', $link->id)->assertNotFound();
        $this->actingAs($intruder)->get(route('qr', ['type' => 'review']))->assertNotFound(); // no review link yet

        $owner->businessProfile()->create(['name' => 'Amara', 'place_id' => 'ChIJabc']);
        $this->actingAs($owner)->get(route('qr', ['type' => 'review']))->assertOk();
    }

    public function test_public_page_shows_link_in_bio_buttons_and_whatsapp(): void
    {
        $client = User::factory()->create(['name' => 'Amara Studio']);
        $client->publicProfile()->create(['handle' => 'amara', 'is_public' => true]);

        Volt::actingAs($client)->test('grow.page')
            ->call('addLink')
            ->set('bioLinks.0.label', 'Book now')
            ->set('bioLinks.0.url', 'not a link')
            ->call('save')
            ->assertHasErrors('bioLinks.0.url')
            ->set('bioLinks.0.url', 'https://book.example')
            ->set('whatsapp', '0772 123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->get('/p/amara')
            ->assertSee('Chat on WhatsApp')
            ->assertSee('https://wa.me/256772123456?text=', false)
            ->assertSee('href="https://book.example"', false)
            ->assertSee('Book now');
    }

    public function test_insights_find_the_best_day_and_time_and_top_posts(): void
    {
        $this->travelTo('2026-10-18 12:00:00');
        $client = User::factory()->create(['timezone' => 'Africa/Kampala']);
        $account = $client->socialAccounts()->create(['platform' => Platform::YouTube, 'platform_user_id' => 'UC1', 'name' => 'Ch', 'access_token' => 't']);

        // Friday evenings (UTC 16:00 = 19:00 Kampala) do far better than Monday mornings.
        foreach (['2026-10-02', '2026-10-09', '2026-10-16'] as $i => $friday) {
            $account->contentItems()->create(['platform_content_id' => "f{$i}", 'title' => "Friday hit {$i}", 'published_at' => "{$friday} 16:00:00", 'views' => 1000, 'likes' => 100 + $i, 'comments_count' => 20]);
        }
        foreach (['2026-10-05', '2026-10-12'] as $i => $monday) {
            $account->contentItems()->create(['platform_content_id' => "m{$i}", 'title' => "Monday post {$i}", 'published_at' => "{$monday} 06:00:00", 'views' => 1000, 'likes' => 5, 'comments_count' => 1]);
        }
        $account->contentItems()->create(['platform_content_id' => 'old', 'title' => 'Ancient', 'published_at' => '2025-01-01 10:00:00', 'likes' => 9999]);

        Volt::actingAs($client)->test('insights.index')
            ->assertSee('Fridays')
            ->assertSee('evening')
            ->assertSee('Friday hit 2')
            ->assertDontSee('Ancient')
            ->set('period', 'all')
            ->assertSee('Ancient');
    }

    public function test_insights_explain_when_there_is_too_little_data(): void
    {
        Volt::actingAs(User::factory()->create())->test('insights.index')->assertSee('Not enough posts yet');
    }
}
