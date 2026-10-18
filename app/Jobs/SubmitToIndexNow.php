<?php

namespace App\Jobs;

use App\Services\Seo\IndexNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Sends new or changed public page addresses to IndexNow. Best effort: failures are only logged. */
class SubmitToIndexNow implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public array $urls) {}

    public function handle(IndexNow $indexNow): void
    {
        $response = Http::timeout(15)->acceptJson()->post('https://api.indexnow.org/indexnow', [
            'host' => parse_url(config('app.url'), PHP_URL_HOST),
            'key' => $indexNow->key(),
            'keyLocation' => $indexNow->keyUrl(),
            'urlList' => $this->urls,
        ]);

        if ($response->failed()) {
            Log::warning('IndexNow submission failed', ['status' => $response->status(), 'urls' => count($this->urls)]);
        }
    }
}
