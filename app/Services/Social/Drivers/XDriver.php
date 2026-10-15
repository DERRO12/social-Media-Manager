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
use App\Services\Social\PlatformException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * X API v2 with OAuth 2.0 (PKCE). Reading timelines and replies requires a paid API tier.
 *
 * @see https://docs.x.com/x-api
 */
class XDriver extends AbstractDriver
{
    private const API = 'https://api.x.com/2';

    private const SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];

    private const CHUNK = 4 * 1024 * 1024;

    protected array $features = [self::PUBLISH, self::CONTENT, self::COMMENTS, self::REPLY];

    public function platform(): Platform
    {
        return Platform::X;
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $callbackUrl,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => $this->pkceChallenge(),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $tokens = $this->ok(Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post(self::API.'/oauth2/token', [
                'code' => $this->guardCallback($request),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $callbackUrl,
                'code_verifier' => $this->pkceVerifier(),
            ]), 'token exchange')->json();

        $me = $this->ok(Http::withToken($tokens['access_token'])->get(self::API.'/users/me', [
            'user.fields' => 'profile_image_url',
        ]), 'loading profile')->json('data');

        return [new AccountData(
            platform: Platform::X,
            platformUserId: $me['id'],
            name: $me['name'],
            accessToken: $tokens['access_token'],
            username: $me['username'],
            avatarUrl: $me['profile_image_url'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $this->expiresAt($tokens['expires_in'] ?? null),
            scopes: explode(' ', $tokens['scope'] ?? ''),
        )];
    }

    protected function refreshAccessToken(SocialAccount $account): array
    {
        return $this->ok(Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post(self::API.'/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]), 'token refresh')->json();
    }

    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $payload = ['text' => mb_substr($target->effectiveCaption(), 0, 280)];

        if ($media = $target->post->media) {
            $payload['media'] = ['media_ids' => [$this->uploadVideo($account, $media->localPath(), $media->mime_type, $media->size)]];
        }

        $id = $this->ok($this->api($account)->post('/tweets', $payload), 'publishing post')->json('data.id');

        return new PublishResult($id, "https://x.com/{$account->username}/status/{$id}");
    }

    private function uploadVideo(SocialAccount $account, string $path, string $mime, int $size): string
    {
        $api = $this->api($account);

        $mediaId = $this->ok($api->post('/media/upload/initialize', [
            'media_type' => $mime,
            'total_bytes' => $size,
            'media_category' => 'tweet_video',
        ]), 'starting video upload')->json('data.id');

        for ($offset = 0, $segment = 0; $offset < $size; $offset += self::CHUNK, $segment++) {
            $this->ok(Http::withToken($this->token($account))
                ->timeout(600)
                ->attach('media', $this->readChunk($path, $offset, self::CHUNK), 'chunk')
                ->post(self::API."/media/upload/{$mediaId}/append", ['segment_index' => $segment]), 'uploading video');
        }

        $info = $this->ok($api->post("/media/upload/{$mediaId}/finalize"), 'finalizing video')->json('data.processing_info');

        // Wait until X has transcoded the video.
        while ($info && in_array($info['state'], ['pending', 'in_progress'], true)) {
            sleep(max(1, (int) ($info['check_after_secs'] ?? 5)));

            $info = $this->ok($api->get('/media/upload', [
                'command' => 'STATUS',
                'media_id' => $mediaId,
            ]), 'checking video')->json('data.processing_info');
        }

        if (($info['state'] ?? 'succeeded') === 'failed') {
            throw new PlatformException('X could not process the video: '.($info['error']['message'] ?? 'unknown error'));
        }

        return $mediaId;
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $tweets = $this->ok($this->api($account)->get("/users/{$account->platform_user_id}/tweets", [
            'max_results' => max(5, min($limit, 100)),
            'tweet.fields' => 'created_at,public_metrics',
            'exclude' => 'replies,retweets',
        ]), 'listing posts')->json('data', []);

        return array_map(fn (array $t) => new ContentData(
            platformContentId: $t['id'],
            type: 'post',
            caption: $t['text'],
            permalink: "https://x.com/{$account->username}/status/{$t['id']}",
            publishedAt: isset($t['created_at']) ? Carbon::parse($t['created_at']) : null,
            views: $t['public_metrics']['impression_count'] ?? null,
            likes: $t['public_metrics']['like_count'] ?? null,
            commentsCount: $t['public_metrics']['reply_count'] ?? null,
            shares: $t['public_metrics']['retweet_count'] ?? null,
        ), $tweets);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $response = $this->ok($this->api($account)->get('/tweets/search/recent', [
            'query' => "conversation_id:{$item->platform_content_id}",
            'tweet.fields' => 'author_id,created_at,public_metrics,in_reply_to_user_id,referenced_tweets',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 50,
        ]), 'loading replies')->json();

        $users = collect($response['includes']['users'] ?? [])->keyBy('id');

        return array_map(function (array $t) use ($users, $account, $item) {
            $author = $users->get($t['author_id'], []);
            $parent = collect($t['referenced_tweets'] ?? [])->firstWhere('type', 'replied_to')['id'] ?? null;

            return new CommentData(
                platformCommentId: $t['id'],
                body: $t['text'],
                parentPlatformCommentId: $parent === $item->platform_content_id ? null : $parent,
                authorName: $author['name'] ?? null,
                authorHandle: isset($author['username']) ? '@'.$author['username'] : null,
                authorAvatarUrl: $author['profile_image_url'] ?? null,
                likes: $t['public_metrics']['like_count'] ?? 0,
                commentedAt: isset($t['created_at']) ? Carbon::parse($t['created_at']) : null,
                isOwn: $t['author_id'] === $account->platform_user_id,
            );
        }, $response['data'] ?? []);
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        $id = $this->ok($this->api($account)->post('/tweets', [
            'text' => mb_substr($message, 0, 280),
            'reply' => ['in_reply_to_tweet_id' => $comment->platform_comment_id],
        ]), 'posting reply')->json('data.id');

        return new CommentData(
            platformCommentId: $id,
            body: $message,
            parentPlatformCommentId: $comment->platform_comment_id,
            authorName: $account->name,
            authorHandle: '@'.$account->username,
            authorAvatarUrl: $account->avatar_url,
            commentedAt: now(),
            isOwn: true,
        );
    }

    private function api(SocialAccount $account): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($this->token($account))->asJson();
    }
}
