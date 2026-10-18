<?php

namespace App\Services\Seo;

use App\Jobs\SubmitToIndexNow;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * IndexNow tells Bing, Yandex, Seznam, Naver and other search engines about new or changed pages within
 * minutes instead of waiting for them to crawl. (Google doesn't use it; it reads the sitemap instead.)
 * On by default in production; set INDEXNOW_ENABLED=false in .env to turn it off.
 */
class IndexNow
{
    public function enabled(): bool
    {
        return (bool) config('services.indexnow.enabled');
    }

    /** The site's key, created on first use. It is public by design: search engines read it at keyUrl(). */
    public function key(): string
    {
        $key = Setting::get('seo.indexnow_key');

        if (! $key) {
            $key = Str::lower(Str::random(32));
            Setting::put('seo.indexnow_key', $key);
        }

        return $key;
    }

    public function keyUrl(): string
    {
        return rtrim(config('app.url'), '/').'/indexnow.txt';
    }

    /** Queue a notice for these pages (only pages on this site are sent). */
    public function submit(array $urls): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $urls = array_values(array_unique(array_filter($urls, fn ($u) => parse_url($u, PHP_URL_HOST) === $host)));

        if ($this->enabled() && $urls) {
            SubmitToIndexNow::dispatch(array_slice($urls, 0, 100));
        }
    }
}
