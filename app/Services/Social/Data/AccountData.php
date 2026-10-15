<?php

namespace App\Services\Social\Data;

use App\Enums\Platform;
use Carbon\CarbonInterface;

final readonly class AccountData
{
    public function __construct(
        public Platform $platform,
        public string $platformUserId,
        public string $name,
        public string $accessToken,
        public ?string $username = null,
        public ?string $avatarUrl = null,
        public ?string $refreshToken = null,
        public ?CarbonInterface $expiresAt = null,
        public array $scopes = [],
        public array $meta = [],
    ) {}
}
