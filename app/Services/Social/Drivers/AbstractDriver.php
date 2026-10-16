<?php

namespace App\Services\Social\Drivers;

use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\PlatformDriver;
use App\Services\Social\Data\CommentData;
use App\Services\Social\PlatformException;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;

abstract class AbstractDriver implements PlatformDriver
{
    /** @var string[] Features from PlatformDriver's constants. */
    protected array $features = [];

    public function __construct(protected array $config) {}

    public function configured(): bool
    {
        return filled($this->config['client_id'] ?? null) && filled($this->config['client_secret'] ?? null);
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        throw PlatformException::unsupported($this->platform()->label(), 'reading comments');
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        throw PlatformException::unsupported($this->platform()->label(), 'replying to comments');
    }

    /** Platforms without a revoke endpoint: deleting the stored token is all we can do. */
    public function revoke(SocialAccount $account): void {}

    /** A valid access token for the account, refreshing it first if it has expired. */
    protected function token(SocialAccount $account): string
    {
        if ($account->tokenExpired() && $account->refresh_token) {
            $fresh = $this->refreshAccessToken($account);

            $account->update([
                'access_token' => $fresh['access_token'],
                'refresh_token' => $fresh['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => $this->expiresAt($fresh['expires_in'] ?? null),
            ]);
        }

        return $account->access_token;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    protected function refreshAccessToken(SocialAccount $account): array
    {
        throw new PlatformException($this->platform()->label().': the connection has expired. Please reconnect the account.');
    }

    protected function ok(Response $response, string $action): Response
    {
        if ($response->failed()) {
            throw PlatformException::fromResponse($this->platform()->label(), $response, $action);
        }

        return $response;
    }

    /** Throw if the provider redirected back with an error (e.g. the user clicked "Cancel"). */
    protected function guardCallback(Request $request): string
    {
        if ($request->filled('error')) {
            throw new PlatformException($this->platform()->label().': '.($request->input('error_description') ?: $request->input('error')));
        }

        return (string) $request->input('code') ?: throw new PlatformException('Missing authorization code.');
    }

    protected function pkceChallenge(): string
    {
        $verifier = Str::random(64);
        session()->put($this->pkceKey(), $verifier);

        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    protected function pkceVerifier(): string
    {
        return (string) session()->pull($this->pkceKey());
    }

    protected function expiresAt(?int $seconds): ?CarbonInterface
    {
        return $seconds ? now()->addSeconds($seconds) : null;
    }

    protected function fileStream(string $path): StreamInterface
    {
        return Utils::streamFor(fopen($path, 'r'));
    }

    /** Read one byte range of a file (for chunked uploads). */
    protected function readChunk(string $path, int $offset, int $length): string
    {
        $handle = fopen($path, 'r');
        fseek($handle, $offset);
        $data = fread($handle, $length);
        fclose($handle);

        return $data;
    }

    private function pkceKey(): string
    {
        return 'oauth_pkce_'.$this->platform()->value;
    }
}
