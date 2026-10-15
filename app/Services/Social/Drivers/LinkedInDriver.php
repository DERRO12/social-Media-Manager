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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn "Share on LinkedIn" + Posts/Videos REST API for a member profile.
 * Reading posts and comments needs the restricted r_member_social scope (Community Management API);
 * set LINKEDIN_COMMUNITY_API=true once LinkedIn approves your app.
 *
 * @see https://learn.microsoft.com/linkedin/marketing/community-management/shares/posts-api
 */
class LinkedInDriver extends AbstractDriver
{
    private const API = 'https://api.linkedin.com/rest';

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->features = $config['community_api']
            ? [self::PUBLISH, self::CONTENT, self::COMMENTS, self::REPLY]
            : [self::PUBLISH];
    }

    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    private function scopes(): array
    {
        return array_merge(
            ['openid', 'profile', 'w_member_social'],
            $this->config['community_api'] ? ['r_member_social'] : [],
        );
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $callbackUrl,
            'state' => $state,
            'scope' => implode(' ', $this->scopes()),
        ]);
    }

    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $tokens = $this->ok(Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $this->guardCallback($request),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $callbackUrl,
        ]), 'token exchange')->json();

        $me = $this->ok(Http::withToken($tokens['access_token'])->get('https://api.linkedin.com/v2/userinfo'), 'loading profile')->json();

        return [new AccountData(
            platform: Platform::LinkedIn,
            platformUserId: $me['sub'],
            name: $me['name'] ?? trim(($me['given_name'] ?? '').' '.($me['family_name'] ?? '')),
            accessToken: $tokens['access_token'],
            avatarUrl: $me['picture'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $this->expiresAt($tokens['expires_in'] ?? null),
            scopes: explode(' ', $tokens['scope'] ?? ''),
        )];
    }

    protected function refreshAccessToken(SocialAccount $account): array
    {
        return $this->ok(Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]), 'token refresh')->json();
    }

    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $author = $this->urn($account);
        $api = $this->api($account);
        $content = null;

        if ($media = $target->post->media) {
            $init = $this->ok($api->post('/videos?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $author,
                    'fileSizeBytes' => $media->size,
                    'uploadCaptions' => false,
                    'uploadThumbnail' => false,
                ],
            ]), 'starting video upload')->json('value');

            $etags = [];

            foreach ($init['uploadInstructions'] as $part) {
                $etags[] = $this->ok(Http::timeout(600)
                    ->withBody($this->readChunk($media->localPath(), $part['firstByte'], $part['lastByte'] - $part['firstByte'] + 1), 'application/octet-stream')
                    ->put($part['uploadUrl']), 'uploading video')->header('ETag');
            }

            $this->ok($api->post('/videos?action=finalizeUpload', [
                'finalizeUploadRequest' => [
                    'video' => $init['video'],
                    'uploadToken' => $init['uploadToken'] ?? '',
                    'uploadedPartIds' => $etags,
                ],
            ]), 'finalizing video');

            $content = ['media' => ['title' => $target->post->title ?: 'Video', 'id' => $init['video']]];
        }

        $response = $this->ok($api->post('/posts', array_filter([
            'author' => $author,
            'commentary' => $target->effectiveCaption(),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content' => $content,
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ])), 'publishing post');

        $postUrn = $response->header('x-restli-id');

        return new PublishResult($postUrn, "https://www.linkedin.com/feed/update/{$postUrn}");
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $posts = $this->ok($this->api($account)->get('/posts', [
            'q' => 'author',
            'author' => $this->urn($account),
            'count' => $limit,
        ]), 'listing posts')->json('elements', []);

        return array_map(fn (array $p) => new ContentData(
            platformContentId: $p['id'],
            type: isset($p['content']['media']) ? 'video' : 'post',
            caption: $p['commentary'] ?? null,
            permalink: "https://www.linkedin.com/feed/update/{$p['id']}",
            publishedAt: isset($p['publishedAt']) ? Carbon::createFromTimestampMs($p['publishedAt']) : null,
        ), $posts);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $comments = $this->ok($this->api($account)->get('/socialActions/'.rawurlencode($item->platform_content_id).'/comments'), 'loading comments')
            ->json('elements', []);

        return array_map(fn (array $c) => new CommentData(
            platformCommentId: $c['$URN'] ?? $c['commentUrn'] ?? (string) $c['id'],
            body: $c['message']['text'] ?? '',
            parentPlatformCommentId: $c['parentComment'] ?? null,
            authorName: $c['actor'] ?? null,
            commentedAt: isset($c['created']['time']) ? Carbon::createFromTimestampMs($c['created']['time']) : null,
            isOwn: ($c['actor'] ?? null) === $this->urn($account),
        ), $comments);
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        $object = $comment->contentItem->platform_content_id;

        $response = $this->ok($this->api($account)->post('/socialActions/'.rawurlencode($object).'/comments', [
            'actor' => $this->urn($account),
            'object' => $object,
            'parentComment' => $comment->platform_comment_id,
            'message' => ['text' => $message],
        ]), 'posting reply');

        return new CommentData(
            platformCommentId: $response->header('x-restli-id') ?: (string) $response->json('id'),
            body: $message,
            parentPlatformCommentId: $comment->platform_comment_id,
            authorName: $account->name,
            authorAvatarUrl: $account->avatar_url,
            commentedAt: now(),
            isOwn: true,
        );
    }

    /**
     * LinkedIn ships a dated API version every month and retires each one after about a year.
     * Two months back is always released and still supported.
     */
    private function apiVersion(): string
    {
        return ($this->config['api_version'] ?? null) ?: now()->subMonths(2)->format('Ym');
    }

    private function urn(SocialAccount $account): string
    {
        return "urn:li:person:{$account->platform_user_id}";
    }

    private function api(SocialAccount $account): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($this->token($account))
            ->withHeaders([
                'LinkedIn-Version' => $this->apiVersion(),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->asJson();
    }
}
