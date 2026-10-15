<?php

namespace App\Services\Social\Drivers;

use App\Enums\Platform;
use App\Services\Social\Data\AccountData;
use App\Services\Social\PlatformException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared Facebook Login flow for Facebook Pages and Instagram professional accounts.
 * One login returns every Page the user granted plus any Instagram account linked to those Pages.
 *
 * @see https://developers.facebook.com/docs/graph-api
 */
abstract class MetaDriver extends AbstractDriver
{
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_read_user_content',
        'pages_manage_posts',
        'pages_manage_engagement',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
    ];

    protected function graphUrl(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.$this->config['graph_version'].'/'.ltrim($path, '/');
    }

    protected function graph(string $token): PendingRequest
    {
        return Http::baseUrl($this->graphUrl())->withQueryParameters(['access_token' => $token]);
    }

    public function authorizationUrl(string $state, string $callbackUrl): string
    {
        return 'https://www.facebook.com/'.$this->config['graph_version'].'/dialog/oauth?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $callbackUrl,
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);
    }

    public function handleCallback(Request $request, string $callbackUrl): array
    {
        $shortLived = $this->ok(Http::get($this->graphUrl('oauth/access_token'), [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $callbackUrl,
            'code' => $this->guardCallback($request),
        ]), 'token exchange')->json('access_token');

        // Page tokens obtained from a long-lived user token do not expire.
        $userToken = $this->ok(Http::get($this->graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'fb_exchange_token' => $shortLived,
        ]), 'long-lived token exchange')->json('access_token');

        $pages = $this->ok($this->graph($userToken)->get('me/accounts', [
            'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
        ]), 'loading pages')->json('data', []);

        if (empty($pages)) {
            throw new PlatformException('Facebook: no Pages were shared. Make sure you manage a Facebook Page and selected it during login.');
        }

        $accounts = [];

        foreach ($pages as $page) {
            $accounts[] = new AccountData(
                platform: Platform::Facebook,
                platformUserId: $page['id'],
                name: $page['name'],
                accessToken: $page['access_token'],
                avatarUrl: $page['picture']['data']['url'] ?? null,
            );

            if ($ig = $page['instagram_business_account'] ?? null) {
                $accounts[] = new AccountData(
                    platform: Platform::Instagram,
                    platformUserId: $ig['id'],
                    name: $ig['name'] ?? $ig['username'],
                    accessToken: $page['access_token'],
                    username: $ig['username'] ?? null,
                    avatarUrl: $ig['profile_picture_url'] ?? null,
                    meta: ['page_id' => $page['id']],
                );
            }
        }

        return $accounts;
    }
}
