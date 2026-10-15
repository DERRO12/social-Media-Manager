<?php

namespace App\Services\Social\Data;

use Carbon\CarbonInterface;

final readonly class TrendingVideo
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public ?string $channel = null,
        public ?string $thumbnailUrl = null,
        public ?CarbonInterface $publishedAt = null,
        public ?int $views = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $durationSeconds = null,
    ) {}

    /** "4:13", "1:02:45" */
    public function duration(): ?string
    {
        if ($this->durationSeconds === null || $this->durationSeconds === 0) {
            return null;
        }

        $h = intdiv($this->durationSeconds, 3600);
        $m = intdiv($this->durationSeconds % 3600, 60);
        $s = $this->durationSeconds % 60;

        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** YouTube Shorts are up to 3 minutes long. */
    public function isShortForm(): bool
    {
        return $this->durationSeconds !== null && $this->durationSeconds > 0 && $this->durationSeconds <= 180;
    }
}
