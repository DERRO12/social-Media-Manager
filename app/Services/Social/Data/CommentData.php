<?php

namespace App\Services\Social\Data;

use Carbon\CarbonInterface;

final readonly class CommentData
{
    public function __construct(
        public string $platformCommentId,
        public string $body,
        public ?string $parentPlatformCommentId = null,
        public ?string $authorName = null,
        public ?string $authorHandle = null,
        public ?string $authorAvatarUrl = null,
        public int $likes = 0,
        public ?CarbonInterface $commentedAt = null,
        public bool $isOwn = false,
    ) {}

    public function toAttributes(): array
    {
        return [
            'parent_platform_comment_id' => $this->parentPlatformCommentId,
            'author_name' => $this->authorName,
            'author_handle' => $this->authorHandle,
            'author_avatar_url' => $this->authorAvatarUrl,
            'body' => $this->body,
            'likes' => $this->likes,
            'commented_at' => $this->commentedAt,
            'is_own' => $this->isOwn,
        ];
    }
}
