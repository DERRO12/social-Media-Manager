<?php

namespace App\Services\Social\Data;

final readonly class PublishResult
{
    public function __construct(
        public string $platformPostId,
        public ?string $permalink = null,
    ) {}
}
