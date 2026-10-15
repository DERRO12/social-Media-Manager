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
use Illuminate\Support\Facades\Http;

class FacebookDriver extends MetaDriver
{
    protected array $features = [self::PUBLISH, self::CONTENT, self::COMMENTS, self::REPLY];

    public function platform(): Platform
    {
        return Platform::Facebook;
    }

    public function publish(PostTarget $target): PublishResult
    {
        $account = $target->account;
        $post = $target->post;
        $token = $this->token($account);

        if (! $post->media) {
            $id = $this->ok($this->graph($token)->post("{$account->platform_user_id}/feed", [
                'message' => $target->effectiveCaption(),
            ]), 'publishing post')->json('id');

            return new PublishResult($id, "https://www.facebook.com/{$id}");
        }

        $video = $this->ok(Http::timeout(3600)
            ->attach('source', fopen($post->media->localPath(), 'r'), $post->media->original_name)
            ->post('https://graph-video.facebook.com/'.$this->config['graph_version']."/{$account->platform_user_id}/videos", array_filter([
                'access_token' => $token,
                'title' => $post->title,
                'description' => $target->effectiveCaption(),
            ])), 'uploading video')->json();

        return new PublishResult($video['id'], "https://www.facebook.com/{$video['id']}");
    }

    public function fetchContent(SocialAccount $account, int $limit = 25): array
    {
        $posts = $this->ok($this->graph($this->token($account))->get("{$account->platform_user_id}/posts", [
            'fields' => 'id,message,permalink_url,created_time,full_picture,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
            'limit' => $limit,
        ]), 'listing posts')->json('data', []);

        return array_map(fn (array $p) => new ContentData(
            platformContentId: $p['id'],
            type: 'post',
            caption: $p['message'] ?? null,
            permalink: $p['permalink_url'] ?? null,
            thumbnailUrl: $p['full_picture'] ?? null,
            publishedAt: isset($p['created_time']) ? Carbon::parse($p['created_time']) : null,
            likes: $p['reactions']['summary']['total_count'] ?? null,
            commentsCount: $p['comments']['summary']['total_count'] ?? null,
            shares: $p['shares']['count'] ?? 0,
        ), $posts);
    }

    public function fetchComments(SocialAccount $account, ContentItem $item): array
    {
        $fields = 'id,message,from{id,name,picture},created_time,like_count';

        $threads = $this->ok($this->graph($this->token($account))->get("{$item->platform_content_id}/comments", [
            'fields' => "{$fields},comments{{$fields}}",
            'filter' => 'toplevel',
            'order' => 'reverse_chronological',
            'limit' => 50,
        ]), 'loading comments')->json('data', []);

        $comments = [];

        foreach ($threads as $thread) {
            $comments[] = $this->mapComment($account, $thread);

            foreach ($thread['comments']['data'] ?? [] as $reply) {
                $comments[] = $this->mapComment($account, $reply, $thread['id']);
            }
        }

        return $comments;
    }

    public function reply(SocialAccount $account, Comment $comment, string $message): CommentData
    {
        $id = $this->ok($this->graph($this->token($account))->post("{$comment->platform_comment_id}/comments", [
            'message' => $message,
        ]), 'posting reply')->json('id') ?? throw new PlatformException('Facebook: reply was not created.');

        return new CommentData(
            platformCommentId: $id,
            body: $message,
            parentPlatformCommentId: $comment->platform_comment_id,
            authorName: $account->name,
            authorAvatarUrl: $account->avatar_url,
            commentedAt: now(),
            isOwn: true,
        );
    }

    private function mapComment(SocialAccount $account, array $c, ?string $parentId = null): CommentData
    {
        return new CommentData(
            platformCommentId: $c['id'],
            body: $c['message'] ?? '',
            parentPlatformCommentId: $parentId,
            authorName: $c['from']['name'] ?? 'Facebook user',
            authorAvatarUrl: $c['from']['picture']['data']['url'] ?? null,
            likes: (int) ($c['like_count'] ?? 0),
            commentedAt: isset($c['created_time']) ? Carbon::parse($c['created_time']) : null,
            isOwn: ($c['from']['id'] ?? null) === $account->platform_user_id,
        );
    }
}
