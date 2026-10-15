<?php

namespace App\Services\Social;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PlatformException extends RuntimeException
{
    public static function fromResponse(string $platform, Response $response, string $action): self
    {
        $body = $response->json();
        $detail = data_get($body, 'error.message')
            ?? data_get($body, 'error_description')
            ?? data_get($body, 'error.error_user_msg')
            ?? data_get($body, 'detail')
            ?? data_get($body, 'message')
            ?? (is_string(data_get($body, 'error')) ? data_get($body, 'error') : null)
            ?? mb_substr($response->body(), 0, 300);

        return new self("{$platform}: {$action} failed (HTTP {$response->status()}): {$detail}");
    }

    public static function unsupported(string $platform, string $feature): self
    {
        return new self("{$platform} does not support {$feature} through its API.");
    }
}
