<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Models\Comment;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GoogleBusinessTest extends TestCase
{
    use RefreshDatabase;

    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const V4 = 'https://mybusiness.googleapis.com/v4';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.youtube.client_id' => 'cid', 'services.youtube.client_secret' => 'secret']);
    }

    private function location(User $user, array $meta = []): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => Platform::GoogleBusiness, 'platform_user_id' => 'locations/55', 'name' => 'Amara Studio',
            'access_token' => 't', 'token_expires_at' => now()->addHour(),
            'meta' => $meta + ['account' => 'accounts/1', 'location' => 'locations/55'],
        ]);
    }

    private function review(string $id, string $stars, ?string $text, ?string $reply = null): array
    {
        return array_filter([
            'reviewId' => $id,
            'reviewer' => ['displayName' => 'Customer '.$id],
            'starRating' => $stars,
            'comment' => $text,
            'createTime' => '2026-10-10T10:00:00Z',
            'reviewReply' => $reply ? ['comment' => $reply, 'updateTime' => '2026-10-11T10:00:00Z'] : null,
        ]);
    }

    public function test_connecting_imports_every_business_location_and_accounts_without_one(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/business.manage']),
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [
                ['name' => 'accounts/1', 'accountName' => 'Amara'],
                ['name' => 'accounts/2', 'accountName' => 'New Shop'],
            ]]),
            self::INFO.'/accounts/1/locations*' => Http::response(['locations' => [[
                'name' => 'locations/55', 'title' => 'Amara Studio',
                'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=55', 'placeId' => 'ChIJ55', 'hasVoiceOfMerchant' => true],
            ]]]),
            self::INFO.'/accounts/2/locations*' => Http::response([]),
        ]);

        $driver = app(SocialManager::class)->driver(Platform::GoogleBusiness);
        $this->assertStringContainsString('business.manage', $driver->authorizationUrl('s', 'https://x/cb'));

        $accounts = $driver->handleCallback(Request::create('/cb', 'GET', ['code' => 'c']), 'https://x/cb');

        $this->assertCount(2, $accounts);
        $this->assertSame(['locations/55', 'Amara Studio', 'ChIJ55', true], [$accounts[0]->platformUserId, $accounts[0]->name, $accounts[0]->meta['place_id'], $accounts[0]->meta['verified']]);
        $this->assertSame(['accounts/2', 'New Shop (no business yet)', null], [$accounts[1]->platformUserId, $accounts[1]->name, $accounts[1]->meta['location']]);
    }

    public function test_google_reviews_and_replies_come_into_the_inbox(): void
    {
        $client = User::factory()->create();
        $account = $this->location($client);

        Http::fake([
            self::INFO.'/locations/55*' => Http::response(['name' => 'locations/55', 'title' => 'Amara Studio', 'storefrontAddress' => ['addressLines' => ['Plot 5'], 'locality' => 'Kampala'], 'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=55']]),
            self::V4.'/accounts/1/locations/55/reviews*' => Http::response([
                'averageRating' => 4.5, 'totalReviewCount' => 2,
                'reviews' => [
                    $this->review('r1', 'FIVE', 'Best braids in town!', 'Thank you, Ama!'),
                    $this->review('r2', 'FOUR', null),
                ],
            ]),
        ]);

        SyncSocialAccount::dispatchSync($account);

        $item = $account->contentItems()->sole();
        $this->assertSame('business', $item->type);
        $this->assertSame('Google reviews: Amara Studio', $item->title);
        $this->assertStringContainsString('★ 4.5 average from 2 reviews', $item->caption);

        $comments = Comment::orderBy('id')->get();
        $this->assertSame(['r1', 'r1/reply', 'r2'], $comments->pluck('platform_comment_id')->all());
        $this->assertSame('★★★★★ Best braids in town!', $comments[0]->body);
        $this->assertSame('★★★★☆ (rating only, no text)', $comments[2]->body);
        $this->assertTrue((bool) $comments[1]->is_own);

        // Reviews aren't posts: they never show on the public page.
        $client->publicProfile()->create(['handle' => 'amara', 'is_public' => true]);
        $this->get('/p/amara')->assertDontSee('Google reviews: Amara Studio');
    }

    public function test_replying_to_a_review_from_the_inbox_sends_it_to_google(): void
    {
        $client = User::factory()->create();
        $account = $this->location($client);
        $item = $account->contentItems()->create(['platform_content_id' => 'locations/55', 'type' => 'business', 'title' => 'Google reviews: Amara Studio']);
        $review = $item->comments()->create(['social_account_id' => $account->id, 'platform_comment_id' => 'r2', 'body' => '★★★★☆ Nice', 'author_name' => 'Kofi']);

        Http::fake([self::V4.'/accounts/1/locations/55/reviews/r2/reply' => Http::response(['comment' => 'Thanks Kofi!'])]);

        Volt::actingAs($client)->test('inbox.index')
            ->call('startReply', $review->id)
            ->set('reply', 'Thanks Kofi!')
            ->call('sendReply')
            ->assertHasNoErrors();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r['comment'] === 'Thanks Kofi!');
        $this->assertSame('Thanks Kofi!', Comment::firstWhere('platform_comment_id', 'r2/reply')->body);
    }

    public function test_the_builder_creates_the_business_on_google(): void
    {
        $client = User::factory()->create();
        $account = $client->socialAccounts()->create([
            'platform' => Platform::GoogleBusiness, 'platform_user_id' => 'accounts/2', 'name' => 'New Shop (no business yet)',
            'access_token' => 't', 'token_expires_at' => now()->addHour(), 'meta' => ['account' => 'accounts/2', 'location' => null],
        ]);

        Http::fake([
            self::INFO.'/categories*' => Http::response(['categories' => [['name' => 'categories/gcid:hair_salon', 'displayName' => 'Hair salon']]]),
            self::INFO.'/accounts/2/locations*' => Http::response(['name' => 'locations/77', 'title' => 'Amara Studio', 'metadata' => ['placeId' => 'ChIJ77']]),
        ]);

        Volt::actingAs($client)->test('grow.business')
            ->assertSee('Create on Google')
            ->set('name', 'Amara Studio')
            ->set('phone', '+256700123456')
            ->set('city', 'Kampala')
            ->set('categorySearch', 'hair')
            ->call('searchCategories')
            ->assertSet('googleCategory', 'categories/gcid:hair_salon')
            ->call('createOnGoogle')
            ->assertHasErrors('address')
            ->set('address', 'Plot 5, Ntinda Road')
            ->call('createOnGoogle')
            ->assertHasNoErrors()
            ->assertSet('googleOk', true);

        Http::assertSent(function (HttpRequest $r) {
            return $r->method() === 'POST' && str_contains($r->url(), '/accounts/2/locations')
                && $r['title'] === 'Amara Studio'
                && $r['categories']['primaryCategory']['name'] === 'categories/gcid:hair_salon'
                && $r['storefrontAddress'] === ['regionCode' => 'UG', 'languageCode' => 'en', 'locality' => 'Kampala', 'addressLines' => ['Plot 5, Ntinda Road']]
                && $r['phoneNumbers']['primaryPhone'] === '+256700123456'
                && $r['regularHours']['periods'][0] === ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 18, 'minutes' => 0]];
        });

        $account->refresh();
        $this->assertSame(['locations/77', 'Amara Studio', 'locations/77'], [$account->platform_user_id, $account->name, $account->meta['location']]);
        $this->assertTrue($client->businessProfile()->first()->checklist['created']);
    }

    public function test_api_access_not_yet_approved_is_explained(): void
    {
        $client = User::factory()->create();
        $this->location($client);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        Volt::actingAs($client)->test('grow.business')
            ->call('pushToGoogle')
            ->assertSet('googleOk', false)
            ->assertSee('isn’t approved or enabled yet', false);
    }

    public function test_business_profiles_are_not_offered_when_posting_videos(): void
    {
        $client = User::factory()->create();
        $this->location($client);
        $client->socialAccounts()->create(['platform' => Platform::YouTube, 'platform_user_id' => 'UC1', 'name' => 'My channel', 'access_token' => 't']);

        Volt::actingAs($client)->test('posts.compose')->assertSee('My channel')->assertDontSee('Amara Studio');
    }
}
