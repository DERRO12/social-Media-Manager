<?php

namespace App\Services\Academy;

use App\Services\Social\SocialManager;
use DateInterval;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Looks up YouTube videos for course lessons. Titles work for any public video (oEmbed, no key needed);
 * lengths and whole-playlist imports need the YouTube API key from Admin → Settings → YouTube.
 */
class YouTubeCatalog
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function hasApiKey(): bool
    {
        return filled($this->apiKey());
    }

    private function apiKey(): ?string
    {
        return SocialManager::credentials('youtube')['api_key'] ?? null;
    }

    /** @return array{title: ?string, minutes: ?int} */
    public function lookup(string $videoId): array
    {
        if ($this->hasApiKey()) {
            $video = $this->videos([$videoId])[0] ?? null;
            if ($video) {
                return ['title' => $video['title'], 'minutes' => $video['minutes']];
            }
        }

        try {
            $title = Http::timeout(10)->get('https://www.youtube.com/oembed', [
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'format' => 'json',
            ])->json('title');
        } catch (Throwable) {
            $title = null;
        }

        return ['title' => $title, 'minutes' => null];
    }

    /**
     * Every video in a playlist, in playlist order (up to 200).
     *
     * @return array<int, array{id: string, title: string, minutes: ?int}>
     */
    public function playlist(string $playlistId): array
    {
        if (! $this->hasApiKey()) {
            throw new RuntimeException('Importing a playlist needs a YouTube API key. Add one in Admin → Settings → YouTube.');
        }

        $ids = [];
        $pageToken = null;

        do {
            $response = Http::timeout(20)->get(self::API.'/playlistItems', array_filter([
                'part' => 'contentDetails',
                'playlistId' => $playlistId,
                'maxResults' => 50,
                'pageToken' => $pageToken,
                'key' => $this->apiKey(),
            ]));

            if ($response->failed()) {
                throw new RuntimeException('YouTube could not load that playlist. Check that it exists and is public or unlisted.');
            }

            foreach ($response->json('items', []) as $item) {
                $ids[] = $item['contentDetails']['videoId'];
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken && count($ids) < 200);

        // Look up titles and lengths 50 at a time; private/deleted videos simply don't come back.
        $videos = collect(array_chunk($ids, 50))->flatMap(fn ($chunk) => $this->videos($chunk))->keyBy('id');

        return collect($ids)->map(fn ($id) => $videos->get($id))->filter()->values()->all();
    }

    /** @return array<int, array{id: string, title: string, minutes: ?int}> */
    private function videos(array $ids): array
    {
        $response = Http::timeout(20)->get(self::API.'/videos', [
            'part' => 'snippet,contentDetails',
            'id' => implode(',', $ids),
            'key' => $this->apiKey(),
        ]);

        if ($response->failed()) {
            return [];
        }

        return array_map(fn (array $v) => [
            'id' => $v['id'],
            'title' => $v['snippet']['title'] ?? 'Untitled',
            'minutes' => $this->minutes($v['contentDetails']['duration'] ?? null),
        ], $response->json('items', []));
    }

    private function minutes(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }

        try {
            $i = new DateInterval($iso);
            $seconds = $i->d * 86400 + $i->h * 3600 + $i->i * 60 + $i->s;

            return $seconds ? max(1, (int) round($seconds / 60)) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
