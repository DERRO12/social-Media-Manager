<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Models\Comment;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CommentSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    /** Comment-thread API calls made so far (to prove unchanged videos are skipped). */
    private int $threadCalls = 0;

    /** What the fake YouTube currently reports (one fake is registered; tests change these). */
    private int $count = 6;

    private bool $withSecondThread = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = $this->user->socialAccounts()->create([
            'platform' => Platform::YouTube, 'platform_user_id' => 'UCme', 'name' => 'My channel',
            'access_token' => 't', 'token_expires_at' => now()->addHour(), 'meta' => ['uploads_playlist' => 'UUme'],
        ]);
    }

    private function snippet(string $text, string $author = 'Fan', ?string $parent = null, int $likes = 0, string $channel = 'UCfan', string $at = '2026-10-16T10:00:00Z'): array
    {
        return array_filter([
            'textDisplay' => $text, 'authorDisplayName' => $author, 'likeCount' => $likes,
            'publishedAt' => $at, 'parentId' => $parent, 'authorChannelId' => ['value' => $channel],
        ], fn ($v) => $v !== null);
    }

    /** A video with $count comments; two threads over two pages, the first with 3 replies (only 1 included inline). */
    private function fakeYouTube(int $count = 6, bool $withSecondThread = true): void
    {
        $this->count = $count;
        $this->withSecondThread = $withSecondThread;

        static $registered = null;
        if ($registered === $this) {
            return;
        }
        $registered = $this;

        Http::fake(function ($request) {
            $count = $this->count;
            $withSecondThread = $this->withSecondThread;
            $url = $request->url();

            return match (true) {
                str_contains($url, '/playlistItems') => Http::response(['items' => [['contentDetails' => ['videoId' => 'vid1']]]]),
                str_contains($url, '/videos') => Http::response(['items' => [[
                    'id' => 'vid1', 'snippet' => ['title' => 'My video', 'publishedAt' => '2026-10-15T10:00:00Z'],
                    'statistics' => ['viewCount' => '100', 'likeCount' => '10', 'commentCount' => (string) $count],
                ]]]),
                str_contains($url, '/commentThreads') && ! str_contains($url, 'pageToken') => (function () {
                    $this->threadCalls++;

                    return Http::response(['nextPageToken' => 'p2', 'items' => [[
                        'snippet' => ['totalReplyCount' => 3, 'topLevelComment' => ['id' => 'c1', 'snippet' => $this->snippet('Great video &amp; tips!', likes: 4)]],
                        'replies' => ['comments' => [['id' => 'c1.r1', 'snippet' => $this->snippet('Thanks!', 'Me', 'c1', channel: 'UCme', at: '2026-10-16T11:00:00Z')]]],
                    ]]]);
                })(),
                str_contains($url, '/commentThreads') => Http::response(['items' => $withSecondThread ? [[
                    'snippet' => ['totalReplyCount' => 0, 'topLevelComment' => ['id' => 'c2', 'snippet' => $this->snippet('Second page comment', 'Ama', at: '2026-10-16T09:00:00Z')]],
                ]] : []]),
                str_contains($url, '/comments') => Http::response(['items' => [
                    ['id' => 'c1.r1', 'snippet' => $this->snippet('Thanks!', 'Me', 'c1', channel: 'UCme', at: '2026-10-16T11:00:00Z')],
                    ['id' => 'c1.r2', 'snippet' => $this->snippet('Where can I buy it?', 'Kofi', 'c1', likes: 2, at: '2026-10-16T12:00:00Z')],
                    ['id' => 'c1.r3', 'snippet' => $this->snippet('Link in bio', 'Me', 'c1', channel: 'UCme', at: '2026-10-16T13:00:00Z')],
                ]]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_every_page_and_every_reply_is_imported(): void
    {
        $this->fakeYouTube();

        SyncSocialAccount::dispatchSync($this->account);

        $this->assertEqualsCanonicalizing(['c1', 'c1.r1', 'c1.r2', 'c1.r3', 'c2'], Comment::pluck('platform_comment_id')->all());
        $this->assertSame('Great video & tips!', Comment::firstWhere('platform_comment_id', 'c1')->body);
        $this->assertSame(4, (int) Comment::firstWhere('platform_comment_id', 'c1')->likes);
        $this->assertTrue(Comment::firstWhere('platform_comment_id', 'c1.r3')->is_own);
        $this->assertSame('c1', Comment::firstWhere('platform_comment_id', 'c1.r2')->parent_platform_comment_id);
    }

    public function test_comments_are_only_downloaded_again_when_the_count_changes(): void
    {
        $this->fakeYouTube(count: 6);
        SyncSocialAccount::dispatchSync($this->account);
        SyncSocialAccount::dispatchSync($this->account);
        $this->assertSame(1, $this->threadCalls, 'Unchanged comment count should not re-download comments.');

        $this->fakeYouTube(count: 7);
        SyncSocialAccount::dispatchSync($this->account);
        $this->assertSame(2, $this->threadCalls, 'A new comment should trigger a download.');
    }

    public function test_comments_deleted_on_youtube_disappear(): void
    {
        $this->fakeYouTube(count: 6);
        SyncSocialAccount::dispatchSync($this->account);
        $this->assertTrue(Comment::where('platform_comment_id', 'c2')->exists());

        $this->fakeYouTube(count: 5, withSecondThread: false);
        SyncSocialAccount::dispatchSync($this->account);

        $this->assertFalse(Comment::where('platform_comment_id', 'c2')->exists());
        $this->assertTrue(Comment::where('platform_comment_id', 'c1')->exists());
    }

    public function test_inbox_shows_conversations_with_replies_likes_and_live_refresh(): void
    {
        $this->fakeYouTube();
        SyncSocialAccount::dispatchSync($this->account);
        $this->actingAs($this->user);

        $this->get('/inbox?filter=all')
            ->assertOk()
            ->assertSee('wire:poll.20s.visible', false)
            ->assertSeeInOrder(['Great video & tips!', 'Thanks!', 'Where can I buy it?', 'Link in bio'])
            ->assertSee('3 replies')
            ->assertSee('Second page comment');
    }

    public function test_a_thread_with_an_unread_reply_counts_as_unread_and_can_be_marked_read(): void
    {
        $this->fakeYouTube();
        SyncSocialAccount::dispatchSync($this->account);
        Comment::where('platform_comment_id', 'c1')->update(['read_at' => now()]);
        $thread = Comment::firstWhere('platform_comment_id', 'c1');
        $this->actingAs($this->user);

        // Top comment read, but Kofi's reply is new → the conversation still shows under Unread.
        Volt::test('inbox.index')->assertSee('Where can I buy it?')
            ->call('markRead', $thread->id)
            ->assertDontSee('Where can I buy it?');

        $this->assertNotNull(Comment::firstWhere('platform_comment_id', 'c1.r2')->read_at);
    }

    public function test_check_now_pulls_new_comments_immediately(): void
    {
        $this->fakeYouTube();
        $this->actingAs($this->user);

        Volt::test('inbox.index')->call('refresh');

        $this->assertSame(5, Comment::count());
        $this->assertSame('Up to date.', session('status'));
    }

    public function test_x_on_the_free_plan_explains_why_replies_are_missing(): void
    {
        $this->user->socialAccounts()->create(['platform' => Platform::X, 'platform_user_id' => 'x1', 'name' => 'Me on X', 'access_token' => 't']);
        $this->actingAs($this->user);

        $this->get('/inbox')->assertSee('X only allows reading replies on its paid API plans');
    }
}
