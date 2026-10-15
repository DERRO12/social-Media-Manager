<?php

namespace App\Services\Social\Drivers;

use App\Enums\Platform;
use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Data\CommentData;
use App\Services\Social\Data\ContentData;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\PlatformException;
use Illuminate\Support\Carbon;

class InstagramDriver extends MetaDriver
{
    protected array $features = [self::PUBLISH, self::CONTENT, self::COMMENTS, self::REPLY];

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    /**
     * Instagram downloads the video itself from a public URL, so APP_URL must be reachable
     * from the internet (use a real domain, S3, or a tunnel like ngrok while developing).
     */
    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $media = $target->post->media ?? throw new PlatformException('Instagram posts need a video.');
        $graph = $this->graph($this->token($account));

        $containerId = $this->ok($graph->post("{$account->platform_user_id}/media", [
            'media_type' => 'REELS',
            'video_url' => $media->url(),
            'caption' => $target->effectiveCaption(),
            'share_to_feed' => 'true',
        ]), 'creating media container')->json('id');

        // Wait for Instagram to finish processing the video (up to ~10 minutes).
        for ($i = 0; $i < 60; $i++) {
            $status = $this->ok($graph->get($containerId, ['fields' => 'status_code,status']), 'checking upload')->json();

            if ($status['status_code'] === 'FINISHED') {
                break;
            }

            if (in_array($status['status_code'], ['ERROR', 'EXPIRED'], true)) {
                throw new PlatformException('Instagram could not process the video: '.($status['status'] ?? $status['status_code']));
            }

            sleep(10);
        }

        $mediaId = $this->ok($graph->post("{$account->platform_user_id}/media_publish", [
            'creation_id' => $containerId,
        ]), 'publishing reel')->json('id');

        $permalink = $graph->get($mediaId, ['fields' => 'permalink'])->json('permalink');

        return new PublishResult($mediaId, $permalink);
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $items = $this->ok($this->graph($this->token($account))->get("{$account->platform_user_id}/media", [
            'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
            'limit' => $limit,
        ]), 'listing media')->json('data', []);

        return array_map(fn (array $m) => new ContentData(
            platformContentId: $m['id'],
            type: strtolower($m['media_type'] ?? 'post'),
            caption: $m['caption'] ?? null,
            permalink: $m['permalink'] ?? null,
            thumbnailUrl: $m['thumbnail_url'] ?? ($m['media_type'] === 'VIDEO' ? null : ($m['media_url'] ?? null)),
            publishedAt: isset($m['timestamp']) ? Carbon::parse($m['timestamp']) : null,
            likes: $m['like_count'] ?? null,
            commentsCount: $m['comments_count'] ?? null,
        ), $items);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $fields = 'id,text,username,timestamp,like_count';

        $threads = $this->ok($this->graph($this->token($account))->get("{$item->platform_content_id}/comments", [
            'fields' => "{$fields},replies{{$fields}}",
            'limit' => 50,
        ]), 'loading comments')->json('data', []);

        $comments = [];

        foreach ($threads as $thread) {
            $comments[] = $this->mapComment($account, $thread);

            foreach ($thread['replies']['data'] ?? [] as $reply) {
                $comments[] = $this->mapComment($account, $reply, $thread['id']);
            }
        }

        return $comments;
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        // Instagram replies are always attached to the top-level comment.
        $parentId = $comment->parent_platform_comment_id ?: $comment->platform_comment_id;

        $id = $this->ok($this->graph($this->token($account))->post("{$parentId}/replies", [
            'message' => $message,
        ]), 'posting reply')->json('id');

        return new CommentData(
            platformCommentId: $id,
            body: $message,
            parentPlatformCommentId: $parentId,
            authorName: $account->name,
            authorHandle: $account->username,
            authorAvatarUrl: $account->avatar_url,
            commentedAt: now(),
            isOwn: true,
        );
    }

    private function mapComment(SocialAccount $account, array $c, ?string $parentId = null): CommentData
    {
        return new CommentData(
            platformCommentId: $c['id'],
            body: $c['text'] ?? '',
            parentPlatformCommentId: $parentId,
            authorName: $c['username'] ?? null,
            authorHandle: isset($c['username']) ? '@'.$c['username'] : null,
            likes: (int) ($c['like_count'] ?? 0),
            commentedAt: isset($c['timestamp']) ? Carbon::parse($c['timestamp']) : null,
            isOwn: isset($c['username']) && $c['username'] === $account->username,
        );
    }
}
