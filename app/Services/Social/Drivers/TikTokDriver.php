<?php

namespace App\Services\Social\Drivers;

use App\Enums\Platform;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Data\AccountData;
use App\Services\Social\Data\ContentData;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\PlatformException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Login Kit + Content Posting API + Display API.
 * TikTok exposes like/comment/view counts but not the comment text, so there is no inbox for TikTok.
 *
 * Until TikTok audits your app, direct posts can only be published as private (SELF_ONLY).
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-get-started
 */
class TikTokDriver extends AbstractDriver
{
    private const API = 'https://open.tiktokapis.com/v2';

    private const SCOPES = ['user.info.basic', 'user.info.profile', 'video.publish', 'video.upload', 'video.list'];

    private const CHUNK = 10 * 1024 * 1024;

    protected array $features = [self::PUBLISH, self::CONTENT];

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => $this->config['client_id'],
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => $callbackUrl,
            'state' => $state,
        ]);
    }

    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $tokens = $this->ok(Http::asForm()->post(self::API.'/oauth/token/', [
            'client_key' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $this->guardCallback($request),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $callbackUrl,
        ]), 'token exchange')->json();

        if (isset($tokens['error']) && $tokens['error'] !== 'ok' && ! isset($tokens['access_token'])) {
            throw new PlatformException('TikTok: '.($tokens['error_description'] ?? $tokens['error']));
        }

        $user = $this->ok(Http::withToken($tokens['access_token'])->get(self::API.'/user/info/', [
            'fields' => 'open_id,display_name,avatar_url,username',
        ]), 'loading profile')->json('data.user');

        return [new AccountData(
            platform: Platform::TikTok,
            platformUserId: $user['open_id'] ?? $tokens['open_id'],
            name: $user['display_name'] ?? 'TikTok user',
            accessToken: $tokens['access_token'],
            username: $user['username'] ?? null,
            avatarUrl: $user['avatar_url'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $this->expiresAt($tokens['expires_in'] ?? null),
            scopes: explode(',', $tokens['scope'] ?? ''),
        )];
    }

    protected function refreshAccessToken(SocialAccount $account): array
    {
        return $this->ok(Http::asForm()->post(self::API.'/oauth/token/', [
            'client_key' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]), 'token refresh')->json();
    }

    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $media = $target->post->media ?? throw new PlatformException('TikTok posts need a video.');
        $api = $this->api($account);

        // Files up to 64 MB go in one chunk; bigger files use 10 MB chunks with the remainder in the last one.
        $size = $media->size;
        $chunkSize = $size <= 64 * 1024 * 1024 ? $size : self::CHUNK;
        $chunks = max(1, intdiv($size, $chunkSize));

        // Every choice below was made explicitly by the creator on the posting screen (TikTok Direct Post rules).
        $init = $this->ok($api->post('/post/publish/video/init/', [
            'post_info' => [
                'title' => mb_substr($target->effectiveCaption(), 0, 2200),
                'privacy_level' => $target->option('privacy', $this->config['default_privacy']),
                'disable_comment' => ! $target->option('allow_comments', false),
                'disable_duet' => ! $target->option('allow_duet', false),
                'disable_stitch' => ! $target->option('allow_stitch', false),
                'brand_organic_toggle' => (bool) $target->option('your_brand', false),
                'brand_content_toggle' => (bool) $target->option('branded_content', false),
            ],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $size,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $chunks,
            ],
        ]), 'starting upload')->json('data');

        for ($i = 0; $i < $chunks; $i++) {
            $start = $i * $chunkSize;
            $end = $i === $chunks - 1 ? $size - 1 : $start + $chunkSize - 1;

            $this->ok(Http::timeout(600)
                ->withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$size}"])
                ->withBody($this->readChunk($media->localPath(), $start, $end - $start + 1), $media->mime_type)
                ->put($init['upload_url']), 'uploading video');
        }

        // Wait for TikTok to process the post (up to ~5 minutes).
        for ($i = 0; $i < 30; $i++) {
            $status = $this->ok($api->post('/post/publish/status/fetch/', [
                'publish_id' => $init['publish_id'],
            ]), 'checking status')->json('data');

            if ($status['status'] === 'PUBLISH_COMPLETE') {
                $videoId = (string) ($status['publicaly_available_post_id'][0] ?? $init['publish_id']);

                return new PublishResult($videoId, $account->username ? "https://www.tiktok.com/@{$account->username}/video/{$videoId}" : null);
            }

            if ($status['status'] === 'FAILED') {
                throw new PlatformException('TikTok rejected the video: '.($status['fail_reason'] ?? 'unknown reason'));
            }

            sleep(10);
        }

        // Still processing; TikTok will finish on its side.
        return new PublishResult($init['publish_id']);
    }

    /**
     * What this creator may post right now: their nickname, the privacy options TikTok allows them,
     * whether they turned off comments/duet/stitch, and the longest video they can post.
     * TikTok requires calling this every time the posting screen is shown.
     *
     * @return array{nickname: string, username: ?string, avatar_url: ?string, privacy_options: string[],
     *               comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_duration_sec: ?int}
     */
    public function creatorInfo(SocialAccount $account): array
    {
        $data = $this->ok($this->api($account)->withBody('{}', 'application/json')->post('/post/publish/creator_info/query/'),
            'loading TikTok account settings')->json('data');

        return [
            'nickname' => $data['creator_nickname'] ?? $account->name,
            'username' => $data['creator_username'] ?? $account->username,
            'avatar_url' => $data['creator_avatar_url'] ?? $account->avatar_url,
            'privacy_options' => array_values($data['privacy_level_options'] ?? ['SELF_ONLY']),
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
            'max_duration_sec' => isset($data['max_video_post_duration_sec']) ? (int) $data['max_video_post_duration_sec'] : null,
        ];
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $videos = $this->ok($this->api($account)
            ->withQueryParameters(['fields' => 'id,title,video_description,cover_image_url,share_url,create_time,like_count,comment_count,share_count,view_count'])
            ->post('/video/list/', ['max_count' => min($limit, 20)]), 'listing videos')->json('data.videos', []);

        return array_map(fn (array $v) => new ContentData(
            platformContentId: (string) $v['id'],
            title: $v['title'] ?? null,
            caption: $v['video_description'] ?? null,
            permalink: $v['share_url'] ?? null,
            thumbnailUrl: $v['cover_image_url'] ?? null,
            publishedAt: isset($v['create_time']) ? Carbon::createFromTimestamp($v['create_time']) : null,
            views: $v['view_count'] ?? null,
            likes: $v['like_count'] ?? null,
            commentsCount: $v['comment_count'] ?? null,
            shares: $v['share_count'] ?? null,
        ), $videos);
    }

    private function api(SocialAccount $account): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($this->token($account))->asJson();
    }
}
