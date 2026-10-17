<?php

namespace App\Services\Social\Contracts;

use App\Enums\Platform;
use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Data\AccountData;
use App\Services\Social\Data\CommentData;
use App\Services\Social\Data\ContentData;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Request;

interface PlatformDriver
{
    public const PUBLISH = 'publish';

    public const CONTENT = 'content';

    public const COMMENTS = 'comments';

    public const REPLY = 'reply';

    public function platform(): Platform;

    /** Whether the platform's app credentials are set in .env. */
    public function configured(): bool;

    /** Whether this platform's API supports a feature (see the constants above). */
    public function supports(string $feature): bool;

    /** Build the OAuth authorization URL the user is redirected to. */
    public function authorizationUrl(string $state, string $callbackUrl): string;

    /**
     * Exchange the OAuth callback for one or more accounts
     * (Meta returns every Page / Instagram account the user granted).
     *
     * @return AccountData[]
     */
    public function handleCallback(Request $request, string $callbackUrl): array;

    public function publish(PostTarget $target): PublishResult;

    /** @return ContentData[] */
    public function fetchContent(SocialAccount $account, int $limit = 25): array;

    /** @return CommentData[] */
    public function fetchComments(SocialAccount $account, ContentItem $item): array;

    /** Whether fetchComments() returns every comment, so ones missing from it were deleted on the platform. */
    public function returnsAllComments(): bool;

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData;

    /** Ask the platform to invalidate our access token (best effort; called when an account is disconnected). */
    public function revoke(SocialAccount $account): void;
}
