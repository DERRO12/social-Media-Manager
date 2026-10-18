<?php

namespace App\Services\Social\Drivers;

use App\Enums\Platform;
use App\Models\BusinessProfile;
use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Data\AccountData;
use App\Services\Social\Data\CommentData;
use App\Services\Social\Data\ContentData;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\PlatformException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Business Profile: the business listing on Google Search and Maps.
 * Each connected "account" here is one business location; its Google reviews are synced as comments,
 * so they show in the inbox and can be answered from there.
 *
 * Google only lets approved apps use these APIs: the admin requests access for the Google Cloud project
 * (Admin → Settings → Google) at https://developers.google.com/my-business/content/prereqs.
 *
 * @see https://developers.google.com/my-business/reference/businessinformation/rest
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews
 */
class GoogleBusinessDriver extends AbstractDriver
{
    private const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const V4 = 'https://mybusiness.googleapis.com/v4';

    private const READ_MASK = 'name,title,storefrontAddress,metadata,websiteUri,phoneNumbers,categories';

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    protected array $features = [self::CONTENT, self::COMMENTS, self::REPLY];

    public function platform(): Platform
    {
        return Platform::GoogleBusiness;
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/business.manage',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** One connected account per business location; a Google account without locations is kept too, so one can be created. */
    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $tokens = $this->ok(Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $this->guardCallback($request),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $callbackUrl,
            'grant_type' => 'authorization_code',
        ]), 'token exchange')->json();

        $http = Http::withToken($tokens['access_token']);
        $accounts = $this->ok($http->get(self::ACCOUNTS.'/accounts', ['pageSize' => 20]), 'loading business accounts')->json('accounts', []);

        if (! $accounts) {
            throw new PlatformException('Google Business Profile: this Google account doesn’t manage any business. Sign in with the account that owns it.');
        }

        $result = [];
        $make = fn (string $id, string $name, array $meta) => new AccountData(
            platform: Platform::GoogleBusiness,
            platformUserId: $id,
            name: $name,
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $this->expiresAt($tokens['expires_in'] ?? null),
            scopes: explode(' ', $tokens['scope'] ?? ''),
            meta: $meta,
        );

        foreach ($accounts as $account) {
            $locations = $this->ok($http->get(self::INFO.'/'.$account['name'].'/locations', ['readMask' => self::READ_MASK, 'pageSize' => 100]), 'loading business locations')->json('locations', []);

            foreach ($locations as $location) {
                $result[] = $make($location['name'], $location['title'], self::locationMeta($account['name'], $location));
            }

            if (! $locations) {
                $result[] = $make($account['name'], ($account['accountName'] ?? 'Google account').' (no business yet)', ['account' => $account['name'], 'location' => null]);
            }
        }

        return $result;
    }

    private static function locationMeta(string $account, array $location): array
    {
        return [
            'account' => $account,
            'location' => $location['name'],
            'maps_url' => $location['metadata']['mapsUri'] ?? null,
            'review_url' => $location['metadata']['newReviewUri'] ?? null,
            'place_id' => $location['metadata']['placeId'] ?? null,
            'verified' => ! empty($location['metadata']['hasVoiceOfMerchant']),
        ];
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

    public function revoke(SocialAccount $account): void
    {
        $this->ok(Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $account->refresh_token ?: $account->access_token]), 'revoking access');
    }

    public function publish(PostTarget $target): PublishResult
    {
        throw PlatformException::unsupported($this->platform()->label(), 'posting videos');
    }

    /** The location itself is the one "content item"; its reviews are its comments. */
    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $location = $account->meta['location'] ?? null;
        if (! $location) {
            return [];
        }

        $http = Http::withToken($this->token($account));
        $info = $this->ok($http->get(self::INFO.'/'.$location, ['readMask' => self::READ_MASK]), 'loading the business')->json();
        $summary = $this->ok($http->get($this->reviewsUrl($account), ['pageSize' => 1]), 'loading reviews')->json();

        $account->update(['name' => $info['title'] ?? $account->name, 'meta' => self::locationMeta($account->meta['account'], $info)]);

        $count = (int) ($summary['totalReviewCount'] ?? 0);
        $rating = isset($summary['averageRating']) ? round((float) $summary['averageRating'], 1) : null;

        return [new ContentData(
            platformContentId: $location,
            type: 'business',
            title: 'Google reviews: '.($info['title'] ?? $account->name),
            caption: trim(($rating ? "★ {$rating} average from {$count} ".Str::plural('review', $count).'. ' : 'No reviews yet. ').self::address($info)),
            permalink: $info['metadata']['mapsUri'] ?? null,
            commentsCount: $count,
        )];
    }

    private static function address(array $location): string
    {
        $a = $location['storefrontAddress'] ?? [];

        return implode(', ', array_filter([...($a['addressLines'] ?? []), $a['locality'] ?? null]));
    }

    /** Every review (and the owner's reply to it, as a reply comment). */
    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $http = Http::withToken($this->token($account));
        $comments = [];
        $pageToken = null;

        for ($page = 0; $page < 20; $page++) {
            $data = $this->ok($http->get($this->reviewsUrl($account), array_filter(['pageSize' => 50, 'pageToken' => $pageToken])), 'loading reviews')->json();

            foreach ($data['reviews'] ?? [] as $review) {
                $stars = self::STARS[$review['starRating'] ?? ''] ?? 0;
                $text = trim((string) ($review['comment'] ?? ''));

                $comments[] = new CommentData(
                    platformCommentId: $review['reviewId'],
                    body: ($stars ? str_repeat('★', $stars).str_repeat('☆', 5 - $stars).' ' : '').($text !== '' ? self::withoutTranslation($text) : '(rating only, no text)'),
                    authorName: $review['reviewer']['displayName'] ?? 'Google user',
                    authorAvatarUrl: $review['reviewer']['profilePhotoUrl'] ?? null,
                    commentedAt: isset($review['createTime']) ? Carbon::parse($review['createTime']) : null,
                );

                if (! empty($review['reviewReply']['comment'])) {
                    $comments[] = new CommentData(
                        platformCommentId: $review['reviewId'].'/reply',
                        body: $review['reviewReply']['comment'],
                        parentPlatformCommentId: $review['reviewId'],
                        authorName: $account->name,
                        commentedAt: isset($review['reviewReply']['updateTime']) ? Carbon::parse($review['reviewReply']['updateTime']) : null,
                        isOwn: true,
                    );
                }
            }

            if (! ($pageToken = $data['nextPageToken'] ?? null)) {
                break;
            }
        }

        return $comments;
    }

    public function returnsAllComments(): bool
    {
        return true;
    }

    /** Google adds "(Translated by Google) …" to reviews in other languages; keep the original. */
    private static function withoutTranslation(string $text): string
    {
        return str_contains($text, '(Original)') ? trim(Str::after($text, '(Original)')) : $text;
    }

    /** A business has one public reply per review; replying again replaces it. */
    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        $reviewId = $comment->parent_platform_comment_id ?: Str::before($comment->platform_comment_id, '/reply');

        $this->ok(Http::withToken($this->token($account))->put($this->reviewsUrl($account).'/'.$reviewId.'/reply', ['comment' => $message]), 'replying to the review');

        return new CommentData(
            platformCommentId: $reviewId.'/reply',
            body: $message,
            parentPlatformCommentId: $reviewId,
            authorName: $account->name,
            commentedAt: now(),
            isOwn: true,
        );
    }

    private function reviewsUrl(SocialAccount $account): string
    {
        $location = Str::after((string) ($account->meta['location'] ?? ''), 'locations/');

        return self::V4.'/'.$account->meta['account'].'/locations/'.$location.'/reviews';
    }

    // ---------- Creating and updating the business on Google ----------

    /**
     * Google's business categories matching a search, e.g. "hair" → Hair salon (categories/gcid:hair_salon).
     *
     * @return array<int, array{name: string, label: string}>
     */
    public function categories(SocialAccount $account, string $search, string $regionCode): array
    {
        $response = Http::withToken($this->token($account))->get(self::INFO.'/categories', [
            'regionCode' => strtoupper($regionCode),
            'languageCode' => 'en',
            'filter' => 'displayName='.$search,
            'view' => 'BASIC',
            'pageSize' => 10,
        ]);

        return array_map(fn ($c) => ['name' => $c['name'], 'label' => $c['displayName']], $this->ok($response, 'searching categories')->json('categories', []));
    }

    /** Create the business on Google from the Business Profile builder, and point this connection at it. */
    public function createLocation(SocialAccount $account, BusinessProfile $profile, string $category, string $regionCode): array
    {
        $body = $this->locationBody($profile, $regionCode) + [
            'languageCode' => 'en',
            'categories' => ['primaryCategory' => ['name' => $category]],
        ];

        $location = $this->ok(Http::withToken($this->token($account))
            ->post(self::INFO.'/'.$account->meta['account'].'/locations?requestId='.Str::uuid(), $body), 'creating the business on Google')->json();

        $account->update([
            'platform_user_id' => $location['name'],
            'name' => $location['title'],
            'meta' => self::locationMeta($account->meta['account'], $location),
        ]);

        return $location;
    }

    /** Send the builder's latest name, phone, website, hours and description to Google. */
    public function updateLocation(SocialAccount $account, BusinessProfile $profile, string $regionCode): array
    {
        $body = $this->locationBody($profile, $regionCode);
        $mask = implode(',', array_keys(array_intersect_key(
            ['title' => 1, 'phoneNumbers' => 1, 'websiteUri' => 1, 'regularHours' => 1, 'profile' => 1, 'storefrontAddress' => 1],
            $body,
        )));

        return $this->ok(Http::withToken($this->token($account))
            ->patch(self::INFO.'/'.$account->meta['location'].'?updateMask='.$mask, $body), 'updating the business on Google')->json();
    }

    private function locationBody(BusinessProfile $p, string $regionCode): array
    {
        $periods = [];
        foreach ($p->hours ?? [] as $day => $h) {
            if (($h['open'] ?? false) && ! empty($h['from']) && ! empty($h['to'])) {
                [$fh, $fm] = array_map('intval', explode(':', $h['from']));
                [$th, $tm] = array_map('intval', explode(':', $h['to']));
                $periods[] = [
                    'openDay' => strtoupper($day), 'openTime' => ['hours' => $fh, 'minutes' => $fm],
                    'closeDay' => strtoupper($day), 'closeTime' => ['hours' => $th, 'minutes' => $tm],
                ];
            }
        }

        return array_filter([
            'title' => $p->name ?: $p->user->name,
            'storefrontAddress' => $p->address ? [
                'regionCode' => strtoupper($regionCode),
                'languageCode' => 'en',
                'locality' => $p->city,
                'addressLines' => [$p->address],
            ] : null,
            'phoneNumbers' => $p->phone ? ['primaryPhone' => $p->phone] : null,
            'websiteUri' => $p->website,
            'regularHours' => $periods ? ['periods' => $periods] : null,
            'profile' => $p->description ? ['description' => mb_substr($p->description, 0, 750)] : null,
        ]);
    }

    /** Google's API-access errors are confusing; say plainly what's needed. */
    protected function ok(Response $response, string $action): Response
    {
        if ($response->status() === 429 || ($response->status() === 403 && str_contains($response->body(), 'has not been used'))) {
            throw new PlatformException('Google Business Profile: this app’s access to Google’s Business Profile API isn’t approved or enabled yet. The site admin needs to request access from Google (see Admin → Settings → Google).');
        }

        return parent::ok($response, $action);
    }
}
