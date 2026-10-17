<?php

namespace App\Support;

/**
 * Pulls IDs out of the many shapes of YouTube links people paste.
 */
class YouTube
{
    /** "https://youtu.be/abc", "youtube.com/watch?v=abc", "/shorts/abc", "/embed/abc", "/live/abc" or a bare ID → "abc". */
    public static function videoId(?string $input): ?string
    {
        $input = trim((string) $input);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }

        $patterns = [
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
            '~youtube(?:-nocookie)?\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/.*[?&]v=([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** "youtube.com/playlist?list=PL…" or a bare playlist ID → "PL…". */
    public static function playlistId(?string $input): ?string
    {
        $input = trim((string) $input);

        if (preg_match('~[?&]list=([A-Za-z0-9_-]{10,})~', $input, $m)) {
            return $m[1];
        }

        return preg_match('/^(PL|UU|LL|FL|OL)[A-Za-z0-9_-]{10,}$/', $input) ? $input : null;
    }

    /** Privacy-enhanced embed (no tracking cookies until the viewer presses play). */
    public static function embedUrl(string $id): string
    {
        return "https://www.youtube-nocookie.com/embed/{$id}?rel=0&modestbranding=1";
    }

    public static function watchUrl(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    public static function thumbnail(string $id): string
    {
        return "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
    }
}
