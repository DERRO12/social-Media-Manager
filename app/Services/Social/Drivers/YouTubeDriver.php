<?php

namespace App\Services\Social\Drivers;

use App\Enums\Platform;
use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Data\AccountData;
use App\Services\Social\Data\CommentData;
use App\Services\Social\Data\ContentData;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\Data\TrendingVideo;
use App\Services\Social\PlatformException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * YouTube Data API v3.
 *
 * @see https://developers.google.com/youtube/v3/docs
 */
class YouTubeDriver extends AbstractDriver
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    private const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    protected array $features = [self::PUBLISH, self::CONTENT, self::COMMENTS, self::REPLY];

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    /** Trending works with either a connected channel's login or a plain API key set by the admin. */
    public function canFetchTrending(?SocialAccount $account): bool
    {
        return $account !== null || filled($this->config['api_key'] ?? null);
    }

    /**
     * YouTube's "Trending" chart for a country, optionally limited to one category (10 = Music).
     * Costs 1 quota unit per call.
     *
     * @return TrendingVideo[]
     */
    public function fetchTrending(?SocialAccount $account, string $region, ?string $categoryId = null, int $limit = 24): array
    {
        $request = $account ? Http::withToken($this->token($account)) : Http::withQueryParameters(['key' => $this->config['api_key']]);

        $response = $request->get(self::API.'/videos', array_filter([
            'part' => 'snippet,statistics,contentDetails',
            'chart' => 'mostPopular',
            'regionCode' => strtoupper($region),
            'videoCategoryId' => $categoryId ?: null,
            'maxResults' => min($limit, 50),
        ]));

        // Some categories have no chart in some countries; YouTube answers 404 for those.
        if ($response->status() === 404) {
            return [];
        }

        return array_map(fn (array $v) => new TrendingVideo(
            id: $v['id'],
            title: $v['snippet']['title'] ?? 'Untitled',
            url: "https://www.youtube.com/watch?v={$v['id']}",
            channel: $v['snippet']['channelTitle'] ?? null,
            // maxres and medium are 16:9; "high"/"standard" are 4:3 with black bars baked in.
            thumbnailUrl: $v['snippet']['thumbnails']['maxres']['url'] ?? $v['snippet']['thumbnails']['medium']['url'] ?? null,
            publishedAt: isset($v['snippet']['publishedAt']) ? Carbon::parse($v['snippet']['publishedAt']) : null,
            views: isset($v['statistics']['viewCount']) ? (int) $v['statistics']['viewCount'] : null,
            likes: isset($v['statistics']['likeCount']) ? (int) $v['statistics']['likeCount'] : null,
            comments: isset($v['statistics']['commentCount']) ? (int) $v['statistics']['commentCount'] : null,
            durationSeconds: $this->seconds($v['contentDetails']['duration'] ?? null),
        ), $this->ok($response, 'loading trending videos')->json('items', []));
    }

    /** ISO 8601 duration ("PT4M13S") to seconds. */
    private function seconds(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }

        try {
            $i = new \DateInterval($iso);

            return $i->d * 86400 + $i->h * 3600 + $i->i * 60 + $i->s;
        } catch (\Exception) {
            return null;
        }
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $tokens = $this->ok(Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $this->guardCallback($request),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $callbackUrl,
            'grant_type' => 'authorization_code',
        ]), 'token exchange')->json();

        $channels = $this->ok(Http::withToken($tokens['access_token'])->get(self::API.'/channels', [
            'part' => 'snippet,contentDetails',
            'mine' => 'true',
        ]), 'loading channel')->json('items', []);

        if (empty($channels)) {
            throw new PlatformException('YouTube: this Google account has no YouTube channel.');
        }

        return array_map(fn (array $channel) => new AccountData(
            platform: Platform::YouTube,
            platformUserId: $channel['id'],
            name: $channel['snippet']['title'],
            accessToken: $tokens['access_token'],
            username: $channel['snippet']['customUrl'] ?? null,
            avatarUrl: $channel['snippet']['thumbnails']['default']['url'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $this->expiresAt($tokens['expires_in'] ?? null),
            scopes: explode(' ', $tokens['scope'] ?? ''),
            meta: ['uploads_playlist' => $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null],
        ), $channels);
    }

    protected function refreshAccessToken(SocialAccount $account): array
    {
        return $this->ok(Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]), 'token refresh')->json();
    }

    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $media = $target->post->media ?? throw new PlatformException('YouTube posts need a video.');
        $token = $this->token($account);

        // 1. Start a resumable upload session with the video metadata.
        $session = $this->ok(Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type' => $media->mime_type,
                'X-Upload-Content-Length' => $media->size,
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => mb_substr($target->post->title ?: str($target->effectiveCaption())->limit(90, ''), 0, 100) ?: 'Untitled',
                    'description' => $target->effectiveCaption(),
                    'categoryId' => (string) $target->option('category_id', '22'),
                ],
                'status' => [
                    'privacyStatus' => $target->option('privacy', 'public'),
                    'selfDeclaredMadeForKids' => (bool) $target->option('made_for_kids', false),
                ],
            ]), 'starting upload');

        $uploadUrl = $session->header('Location');

        // 2. Send the file bytes.
        $video = $this->ok(Http::withToken($token)
            ->timeout(3600)
            ->withBody($this->fileStream($media->localPath()), $media->mime_type)
            ->put($uploadUrl), 'uploading video')->json();

        return new PublishResult($video['id'], "https://www.youtube.com/watch?v={$video['id']}");
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $token = $this->token($account);
        $playlist = $account->meta['uploads_playlist'] ?? null;

        if (! $playlist) {
            return [];
        }

        $ids = collect($this->ok(Http::withToken($token)->get(self::API.'/playlistItems', [
            'part' => 'contentDetails',
            'playlistId' => $playlist,
            'maxResults' => min($limit, 50),
        ]), 'listing videos')->json('items', []))->pluck('contentDetails.videoId')->filter();

        if ($ids->isEmpty()) {
            return [];
        }

        $videos = $this->ok(Http::withToken($token)->get(self::API.'/videos', [
            'part' => 'snippet,statistics',
            'id' => $ids->implode(','),
        ]), 'loading video stats')->json('items', []);

        return array_map(fn (array $v) => new ContentData(
            platformContentId: $v['id'],
            title: $v['snippet']['title'] ?? null,
            caption: $v['snippet']['description'] ?? null,
            permalink: "https://www.youtube.com/watch?v={$v['id']}",
            thumbnailUrl: $v['snippet']['thumbnails']['medium']['url'] ?? null,
            publishedAt: isset($v['snippet']['publishedAt']) ? Carbon::parse($v['snippet']['publishedAt']) : null,
            views: (int) ($v['statistics']['viewCount'] ?? 0),
            likes: (int) ($v['statistics']['likeCount'] ?? 0),
            commentsCount: (int) ($v['statistics']['commentCount'] ?? 0),
        ), $videos);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $response = Http::withToken($this->token($account))->get(self::API.'/commentThreads', [
            'part' => 'snippet,replies',
            'videoId' => $item->platform_content_id,
            'maxResults' => 50,
            'order' => 'time',
        ]);

        // Comments disabled on this video.
        if ($response->status() === 403 && str_contains($response->body(), 'commentsDisabled')) {
            return [];
        }

        $comments = [];

        foreach ($this->ok($response, 'loading comments')->json('items', []) as $thread) {
            $comments[] = $this->mapComment($account, $thread['snippet']['topLevelComment']);

            foreach ($thread['replies']['comments'] ?? [] as $reply) {
                $comments[] = $this->mapComment($account, $reply);
            }
        }

        return $comments;
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        $created = $this->ok(Http::withToken($this->token($account))->post(self::API.'/comments?part=snippet', [
            'snippet' => [
                // YouTube only allows one level of replies, so reply to the thread's top comment.
                'parentId' => $comment->parent_platform_comment_id ?: $comment->platform_comment_id,
                'textOriginal' => $message,
            ],
        ]), 'posting reply')->json();

        return $this->mapComment($account, $created);
    }

    private function mapComment(SocialAccount $account, array $comment): CommentData
    {
        $s = $comment['snippet'];

        return new CommentData(
            platformCommentId: $comment['id'],
            body: $s['textOriginal'] ?? strip_tags($s['textDisplay'] ?? ''),
            parentPlatformCommentId: $s['parentId'] ?? null,
            authorName: $s['authorDisplayName'] ?? null,
            authorHandle: $s['authorDisplayName'] ?? null,
            authorAvatarUrl: $s['authorProfileImageUrl'] ?? null,
            likes: (int) ($s['likeCount'] ?? 0),
            commentedAt: isset($s['publishedAt']) ? Carbon::parse($s['publishedAt']) : null,
            isOwn: ($s['authorChannelId']['value'] ?? null) === $account->platform_user_id,
        );
    }
}
