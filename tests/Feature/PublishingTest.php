<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\SyncSocialAccount;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private function youtubeAccount(): SocialAccount
    {
        return User::factory()->create()->socialAccounts()->create([
            'platform' => Platform::YouTube,
            'platform_user_id' => 'UC123',
            'name' => 'My channel',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->subHour(),
            'meta' => ['uploads_playlist' => 'UU123'],
        ]);
    }

    public function test_scheduled_video_is_published_to_youtube(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/1/clip.mp4', 'fake-video-bytes');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response([], 200, ['Location' => 'https://upload.example/session']),
            'upload.example/session' => Http::response(['id' => 'vid123']),
        ]);

        $account = $this->youtubeAccount();
        $user = $account->user;
        $media = $user->media()->create([
            'path' => 'media/1/clip.mp4', 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 16,
        ]);
        $post = $user->posts()->create([
            'media_id' => $media->id,
            'title' => 'My video',
            'caption' => 'Hello world',
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
        $target = $post->targets()->create(['social_account_id' => $account->id, 'options' => ['privacy' => 'unlisted']]);

        $this->artisan('posts:publish-due')->assertSuccessful();

        $target->refresh();
        $this->assertSame(TargetStatus::Published, $target->status, (string) $target->error);
        $this->assertSame('vid123', $target->platform_post_id);
        $this->assertSame(PostStatus::Published, $post->fresh()->status);

        // The expired token was refreshed before uploading.
        $this->assertSame('new-token', $account->fresh()->access_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'uploadType=resumable')
            && $request['snippet']['title'] === 'My video'
            && $request['status']['privacyStatus'] === 'unlisted'
            && $request->hasHeader('Authorization', 'Bearer new-token'));
    }

    public function test_failed_publish_records_the_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'quotaExceeded']], 403),
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('clip.mp4', 'x');

        $account = $this->youtubeAccount();
        $account->update(['token_expires_at' => now()->addHour()]);
        $media = $account->user->media()->create(['path' => 'clip.mp4', 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 1]);
        $post = $account->user->posts()->create(['media_id' => $media->id, 'caption' => 'x', 'status' => PostStatus::Scheduled, 'scheduled_at' => now()]);
        $target = $post->targets()->create(['social_account_id' => $account->id]);

        $post->dispatchPublishing();

        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
        $this->assertStringContainsString('quotaExceeded', $target->fresh()->error);
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
    }

    public function test_a_network_timeout_on_comments_does_not_flag_the_account(): void
    {
        Sleep::fake();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [['contentDetails' => ['videoId' => 'v1']]]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'v1',
                'snippet' => ['title' => 'First video'],
                'statistics' => ['viewCount' => '10', 'likeCount' => '1', 'commentCount' => '3'],
            ]]]),
            'www.googleapis.com/youtube/v3/commentThreads*' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('cURL error 28: SSL connection timeout');
            },
        ]);
        $attempts = 0;

        $account = $this->youtubeAccount();

        SyncSocialAccount::dispatchSync($account);

        $this->assertNull($account->fresh()->last_error);
        $this->assertNotNull($account->fresh()->last_synced_at);
        $this->assertSame(1, $account->contentItems()->count());
        $this->assertSame(3, $attempts); // retried, then skipped
    }

    public function test_sync_imports_videos_metrics_and_comments(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [['contentDetails' => ['videoId' => 'v1']]]]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'v1',
                'snippet' => ['title' => 'First video', 'publishedAt' => '2026-10-01T10:00:00Z'],
                'statistics' => ['viewCount' => '1500', 'likeCount' => '120', 'commentCount' => '2'],
            ]]]),
            'www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => [[
                'snippet' => ['topLevelComment' => [
                    'id' => 'c1',
                    'snippet' => ['textOriginal' => 'Great video!', 'authorDisplayName' => 'Fan', 'likeCount' => 3, 'publishedAt' => '2026-10-02T10:00:00Z', 'authorChannelId' => ['value' => 'UCfan']],
                ]],
                'replies' => ['comments' => [[
                    'id' => 'c1.r1',
                    'snippet' => ['textOriginal' => 'Thanks!', 'parentId' => 'c1', 'authorDisplayName' => 'Me', 'publishedAt' => '2026-10-02T11:00:00Z', 'authorChannelId' => ['value' => 'UC123']],
                ]]],
            ]]]),
        ]);

        $account = $this->youtubeAccount();

        SyncSocialAccount::dispatchSync($account);

        $item = $account->contentItems()->sole();
        $this->assertSame('First video', $item->title);
        $this->assertSame(1500, (int) $item->views);
        $this->assertSame(120, (int) $item->likes);

        $this->assertCount(2, $item->comments);
        $this->assertFalse($item->comments()->where('platform_comment_id', 'c1')->sole()->is_own);
        $this->assertTrue($item->comments()->where('platform_comment_id', 'c1.r1')->sole()->is_own);
        $this->assertNull($account->fresh()->last_error);
    }
}
