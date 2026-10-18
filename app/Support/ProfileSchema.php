<?php

namespace App\Support;

use App\Enums\Platform;
use App\Models\BusinessProfile;
use App\Models\ProfilePhoto;
use App\Models\PublicProfile;

/**
 * Structured data (schema.org JSON-LD) for public pages: the details Google reads to understand who a page
 * is about, show rich results, and connect the page with the owner's social profiles and business.
 */
class ProfileSchema
{
    /** The owner's social profile links (Google uses them to connect the page with those accounts). */
    public static function sameAs(PublicProfile $profile): array
    {
        return $profile->user->socialAccounts->map(fn ($a) => match ($a->platform) {
            Platform::YouTube => 'https://www.youtube.com/channel/'.$a->platform_user_id,
            Platform::TikTok => $a->username ? 'https://www.tiktok.com/@'.ltrim($a->username, '@') : null,
            Platform::Instagram => $a->username ? 'https://www.instagram.com/'.ltrim($a->username, '@') : null,
            Platform::X => $a->username ? 'https://x.com/'.ltrim($a->username, '@') : null,
            Platform::Facebook => 'https://www.facebook.com/'.$a->platform_user_id,
            default => null,
        })->filter()->unique()->values()->all();
    }

    /** Who the page is about: a LocalBusiness when the owner shows business details, otherwise a Person. */
    public static function entity(PublicProfile $profile, bool $full = true): array
    {
        $business = $profile->business();

        if (! $full) {
            return ['@type' => $business ? 'LocalBusiness' : 'Person', 'name' => $profile->name(), 'url' => $profile->url()];
        }

        $base = [
            '@type' => $business ? 'LocalBusiness' : 'Person',
            '@id' => $profile->url().'#owner',
            'name' => $profile->name(),
            'description' => $profile->headline ?: $profile->bio,
            'image' => $profile->avatarUrl(),
            'url' => $profile->url(),
            'sameAs' => array_values(array_filter([...self::sameAs($profile), $profile->website])) ?: null,
        ];

        if ($business) {
            $base += self::business($business, $profile);
        }

        return self::clean($base);
    }

    private static function business(BusinessProfile $b, PublicProfile $profile): array
    {
        $hours = collect($b->hours ?? [])
            ->filter(fn ($h) => ($h['open'] ?? false) && ! empty($h['from']) && ! empty($h['to']))
            ->map(fn ($h, $day) => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/'.ucfirst($day),
                'opens' => $h['from'],
                'closes' => $h['to'],
            ])->values()->all();

        return [
            'description' => $b->description ?: $profile->headline ?: $profile->bio,
            'telephone' => $b->phone,
            'address' => ($b->address || $b->city) ? array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $b->address,
                'addressLocality' => $b->city,
            ]) : null,
            'areaServed' => $b->service_area,
            'openingHoursSpecification' => $hours ?: null,
            'hasMap' => self::mapUrl($b, $profile),
            'makesOffer' => collect($b->services ?? [])->filter()->take(20)->map(fn ($s) => [
                '@type' => 'Offer',
                'itemOffered' => ['@type' => 'Service', 'name' => $s],
            ])->values()->all() ?: null,
            'knowsAbout' => $b->primary_category,
        ];
    }

    /** A Google Maps link for directions. */
    public static function mapUrl(BusinessProfile $b, PublicProfile $profile): ?string
    {
        $query = trim(implode(' ', array_filter([$b->name ?: $profile->name(), $b->address, $b->city])));

        if (! $b->address && ! $b->city && ! $b->place_id) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($query).($b->place_id ? '&query_place_id='.urlencode($b->place_id) : '');
    }

    /** ImageObject: what lets a photo show in Google Images with its title, creator and credit. */
    public static function photo(ProfilePhoto $photo, PublicProfile $profile): array
    {
        return self::clean([
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'contentUrl' => $photo->url(),
            'thumbnailUrl' => $photo->thumbUrl(),
            'url' => $photo->pageUrl($profile),
            'name' => $photo->title,
            'caption' => $photo->altText(),
            'description' => $photo->caption,
            'keywords' => $photo->keywordList() ? implode(', ', $photo->keywordList()) : null,
            'width' => $photo->width ? ['@type' => 'QuantitativeValue', 'value' => $photo->width, 'unitCode' => 'E37'] : null,
            'height' => $photo->height ? ['@type' => 'QuantitativeValue', 'value' => $photo->height, 'unitCode' => 'E37'] : null,
            'encodingFormat' => $photo->mime,
            'uploadDate' => $photo->created_at?->toIso8601String(),
            'datePublished' => $photo->created_at?->toIso8601String(),
            'contentLocation' => $photo->location ? ['@type' => 'Place', 'name' => $photo->location] : null,
            'creator' => self::entity($profile, full: false),
            'creditText' => $profile->name(),
            'copyrightNotice' => '© '.($photo->created_at?->year ?? now()->year).' '.$profile->name(),
            'representativeOfPage' => true,
        ]);
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    }

    /** Drop empty values so the markup only states what we know. */
    private static function clean(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
