<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** TikTok's Direct Post rules for the posting screen, which its app audit checks. */
class TikTokPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    private int $mediaId;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('tiktok.client_id', 'key');
        Setting::put('tiktok.client_secret', 'secret', encrypt: true);
        Storage::fake('public');

        $this->user = User::factory()->create();
        $this->account = $this->user->socialAccounts()->create([
            'platform' => Platform::TikTok, 'platform_user_id' => 'open-1', 'name' => 'Ama Dance',
            'username' => 'amadance', 'access_token' => 'tt', 'token_expires_at' => now()->addDay(),
        ]);
        Storage::disk('public')->put('media/clip.mp4', str_repeat('v', 2048));
        $this->mediaId = $this->user->media()->create(['path' => 'media/clip.mp4', 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 2048])->id;
    }

    private function fakeCreator(array $overrides = []): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => array_merge([
                'creator_nickname' => 'Ama Dance',
                'creator_username' => 'amadance',
                'creator_avatar_url' => 'https://p16.tiktokcdn.com/a.jpg',
                'privacy_level_options' => ['FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
                'comment_disabled' => false,
                'duet_disabled' => true,
                'stitch_disabled' => false,
                'max_video_post_duration_sec' => 600,
            ], $overrides), 'error' => ['code' => 'ok']]),
        ]);
    }

    private function composer()
    {
        return Volt::actingAs($this->user)->test('posts.compose')
            ->call('useMedia', $this->mediaId, 30)
            ->set('accountIds', [(string) $this->account->id])
            ->set('when', 'draft');
    }

    public function test_the_creator_and_only_their_allowed_options_are_shown(): void
    {
        $this->fakeCreator();

        $this->composer()
            ->assertSee('Posting to TikTok as Ama Dance')
            ->assertSee('@amadance')
            ->assertSee('Choose who can see it')
            ->assertSee('Followers')
            ->assertSee('Only me')
            ->assertDontSee('Friends (followers you follow back)')
            ->assertSee('Turned off in your TikTok settings')
            ->assertSee('Music Usage Confirmation')
            ->assertSet("tiktok.{$this->account->id}.privacy", '')
            ->assertSet("tiktok.{$this->account->id}.allow_comments", false);
    }

    public function test_the_creator_must_choose_who_can_see_the_video(): void
    {
        $this->fakeCreator();

        $this->composer()->call('save')->assertHasErrors("tiktok.{$this->account->id}");
        $this->assertSame(0, $this->user->posts()->count());
    }

    public function test_disclosure_needs_a_type_and_branded_content_cannot_be_private(): void
    {
        $this->fakeCreator();
        $id = $this->account->id;

        $this->composer()
            ->set("tiktok.{$id}.privacy", 'FOLLOWER_OF_CREATOR')
            ->set("tiktok.{$id}.disclose", true)
            ->call('save')
            ->assertHasErrors("tiktok.{$id}")
            ->assertSee('Select “Your brand” and/or “Branded content”')
            ->set("tiktok.{$id}.branded_content", true)
            ->set("tiktok.{$id}.privacy", 'SELF_ONLY')
            ->call('save')
            ->assertHasErrors("tiktok.{$id}")
            ->assertSee('Branded content can’t be private')
            ->assertSee('Branded Content Policy');
    }

    public function test_videos_longer_than_the_creator_may_post_are_refused(): void
    {
        $this->fakeCreator(['max_video_post_duration_sec' => 60]);
        $id = $this->account->id;

        Volt::actingAs($this->user)->test('posts.compose')
            ->call('useMedia', $this->mediaId, 95)
            ->set('accountIds', [(string) $id])
            ->set("tiktok.{$id}.privacy", 'SELF_ONLY')
            ->set('when', 'draft')
            ->call('save')
            ->assertHasErrors("tiktok.{$id}")
            ->assertSee('This video is 95 seconds long');
    }

    public function test_choices_are_saved_and_creator_disabled_switches_stay_off(): void
    {
        $this->fakeCreator();
        $id = $this->account->id;

        $this->composer()
            ->set("tiktok.{$id}.privacy", 'FOLLOWER_OF_CREATOR')
            ->set("tiktok.{$id}.allow_comments", true)
            ->set("tiktok.{$id}.allow_duet", true) // disabled by the creator in TikTok
            ->set("tiktok.{$id}.disclose", true)
            ->set("tiktok.{$id}.your_brand", true)
            ->call('save')
            ->assertHasNoErrors();

        $options = $this->user->posts()->first()->targets()->first()->options;
        $this->assertSame('FOLLOWER_OF_CREATOR', $options['privacy']);
        $this->assertTrue($options['allow_comments']);
        $this->assertFalse($options['allow_duet']);
        $this->assertFalse($options['allow_stitch']);
        $this->assertTrue($options['your_brand']);
        $this->assertFalse($options['branded_content']);
    }

    public function test_publishing_sends_the_creators_choices_to_tiktok(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub1', 'upload_url' => 'https://upload.tiktokapis.com/up']]),
            'upload.tiktokapis.com/*' => Http::response(),
            'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7123]]]),
        ]);

        $post = $this->user->posts()->create(['media_id' => $this->mediaId, 'caption' => 'Dance!', 'status' => PostStatus::Scheduled, 'scheduled_at' => now()]);
        $target = $post->targets()->create(['social_account_id' => $this->account->id, 'options' => [
            'privacy' => 'FOLLOWER_OF_CREATOR', 'allow_comments' => true, 'allow_duet' => false,
            'allow_stitch' => true, 'your_brand' => true, 'branded_content' => false,
        ]]);

        $post->dispatchPublishing();

        $this->assertSame(TargetStatus::Published, $target->fresh()->status, (string) $target->fresh()->error);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/post/publish/video/init/')
            && $r['post_info']['privacy_level'] === 'FOLLOWER_OF_CREATOR'
            && $r['post_info']['disable_comment'] === false
            && $r['post_info']['disable_duet'] === true
            && $r['post_info']['disable_stitch'] === false
            && $r['post_info']['brand_organic_toggle'] === true
            && $r['post_info']['brand_content_toggle'] === false);
    }

    public function test_a_tiktok_error_is_shown_instead_of_the_options(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid']], 401)]);

        $this->composer()
            ->assertSee("We couldn't load your TikTok account settings")
            ->call('save')
            ->assertHasErrors("tiktok.{$this->account->id}");
    }
}
