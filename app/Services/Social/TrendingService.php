<?php

namespace App\Services\Social;

use App\Enums\Platform;
use App\Models\User;
use App\Services\Social\Data\TrendingVideo;
use App\Services\Social\Drivers\YouTubeDriver;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public "what's trending" data. Only YouTube offers this through an official API;
 * other platforms are linked to their own trend pages in the UI.
 */
class TrendingService
{
    /** Trending charts change slowly; caching also keeps YouTube quota use tiny. */
    private const CACHE_MINUTES = 30;

    /** YouTube category IDs (10 = Music, which doubles as "trending songs"). */
    public const YOUTUBE_CATEGORIES = [
        '' => 'All',
        '10' => 'Music',
        '24' => 'Entertainment',
        '23' => 'Comedy',
        '20' => 'Gaming',
        '17' => 'Sports',
        '22' => 'People & Blogs',
        '26' => 'How-to & Style',
        '27' => 'Education',
        '28' => 'Science & Tech',
        '25' => 'News',
        '1' => 'Film & Animation',
    ];

    public const COUNTRIES = [
        'UG' => 'Uganda', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'RW' => 'Rwanda', 'NG' => 'Nigeria', 'GH' => 'Ghana',
        'ZA' => 'South Africa', 'EG' => 'Egypt', 'MA' => 'Morocco', 'SN' => 'Senegal', 'ET' => 'Ethiopia',
        'US' => 'United States', 'CA' => 'Canada', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina',
        'GB' => 'United Kingdom', 'IE' => 'Ireland', 'FR' => 'France', 'DE' => 'Germany', 'ES' => 'Spain',
        'IT' => 'Italy', 'NL' => 'Netherlands', 'SE' => 'Sweden', 'PL' => 'Poland', 'TR' => 'Turkey',
        'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IN' => 'India', 'PK' => 'Pakistan',
        'BD' => 'Bangladesh', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'MY' => 'Malaysia', 'SG' => 'Singapore',
        'JP' => 'Japan', 'KR' => 'South Korea', 'AU' => 'Australia', 'NZ' => 'New Zealand',
    ];

    public function __construct(private SocialManager $social) {}

    /** Best-guess country for the user, from their timezone (e.g. Africa/Kampala → UG). */
    public function defaultCountry(User $user): string
    {
        try {
            $code = (new DateTimeZone($user->timezone ?: 'UTC'))->getLocation()['country_code'] ?? null;
        } catch (Throwable) {
            $code = null;
        }

        return isset(self::COUNTRIES[$code]) ? $code : 'US';
    }

    /**
     * @return array{videos: TrendingVideo[], available: bool, error: ?string}
     */
    public function youtube(User $user, string $country, ?string $category = null): array
    {
        $country = isset(self::COUNTRIES[$country]) ? $country : 'US';
        $category = array_key_exists((string) $category, self::YOUTUBE_CATEGORIES) ? (string) $category : '';

        /** @var YouTubeDriver $driver */
        $driver = $this->social->driver(Platform::YouTube);
        $account = $user->socialAccounts()->where('platform', Platform::YouTube)->first();

        if (! $driver->canFetchTrending($account)) {
            return ['videos' => [], 'available' => false, 'error' => null];
        }

        try {
            $videos = Cache::remember(
                "trending:youtube:{$country}:{$category}",
                now()->addMinutes(self::CACHE_MINUTES),
                fn () => $driver->fetchTrending($account, $country, $category ?: null),
            );

            return ['videos' => $videos, 'available' => true, 'error' => null];
        } catch (Throwable $e) {
            report($e);

            return ['videos' => [], 'available' => true, 'error' => 'YouTube trends could not be loaded right now. Please try again in a moment.'];
        }
    }

    /** Where to see trends for platforms that don't offer them through an API. */
    public static function externalSources(): array
    {
        return [
            [
                'platform' => Platform::TikTok,
                'title' => 'TikTok trending sounds, hashtags & videos',
                'text' => 'TikTok publishes its trending songs, hashtags, creators and videos by country in the free TikTok Creative Center. TikTok does not let apps read this data, so open it there.',
                'links' => [
                    ['Trending songs', 'https://ads.tiktok.com/business/creativecenter/inspiration/popular/music/pc/en'],
                    ['Trending hashtags', 'https://ads.tiktok.com/business/creativecenter/inspiration/popular/hashtag/pc/en'],
                    ['Trending videos', 'https://ads.tiktok.com/business/creativecenter/inspiration/popular/pc/en'],
                ],
            ],
            [
                'platform' => Platform::Instagram,
                'title' => 'Instagram trending audio for Reels',
                'text' => 'Instagram only shows trending audio inside the app: open the Instagram app → Professional dashboard → “Trending audio”, or tap Reels → camera → Audio and look for the ↗ arrow next to a sound.',
                'links' => [
                    ['Instagram Reels', 'https://www.instagram.com/reels/'],
                ],
            ],
            [
                'platform' => Platform::X,
                'title' => 'X (Twitter) trending topics',
                'text' => 'X shows what is trending by location on its Explore page. Reading trends through the X API requires an expensive paid plan.',
                'links' => [
                    ['Explore → Trending', 'https://x.com/explore/tabs/trending'],
                ],
            ],
            [
                'platform' => Platform::Facebook,
                'title' => 'Facebook Reels ideas',
                'text' => 'Facebook has no public trends feed. Meta Business Suite → Inspiration hub shows trending Reels and audio for your Page.',
                'links' => [
                    ['Meta Business Suite', 'https://business.facebook.com/'],
                ],
            ],
        ];
    }
}
