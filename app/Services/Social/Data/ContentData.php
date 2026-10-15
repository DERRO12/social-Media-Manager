<?php

namespace App\Services\Social\Data;

use Carbon\CarbonInterface;

final readonly class ContentData
{
    public function __construct(
        public string $platformContentId,
        public string $type = 'video',
        public ?string $title = null,
        public ?string $caption = null,
        public ?string $permalink = null,
        public ?string $thumbnailUrl = null,
        public ?CarbonInterface $publishedAt = null,
        public ?int $views = null,
        public ?int $likes = null,
        public ?int $commentsCount = null,
        public ?int $shares = null,
    ) {}

    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title ? mb_substr($this->title, 0, 255) : null,
            'caption' => $this->caption,
            'permalink' => $this->permalink,
            'thumbnail_url' => $this->thumbnailUrl,
            'published_at' => $this->publishedAt,
            'views' => $this->views,
            'likes' => $this->likes,
            'comments_count' => $this->commentsCount,
            'shares' => $this->shares,
        ];
    }
}
