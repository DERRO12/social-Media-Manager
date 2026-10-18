<?php

namespace App\Enums;

enum Platform: string
{
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case GoogleBusiness = 'google_business';

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X (Twitter)',
            self::GoogleBusiness => 'Google Business Profile',
        };
    }

    /** Flux badge color for this platform. */
    public function color(): string
    {
        return match ($this) {
            self::YouTube => 'red',
            self::Facebook => 'blue',
            self::Instagram => 'pink',
            self::TikTok => 'zinc',
            self::LinkedIn => 'sky',
            self::X => 'slate',
            self::GoogleBusiness => 'emerald',
        };
    }

    /** What the client needs before clicking Connect. */
    public function requirements(): array
    {
        return match ($this) {
            self::YouTube => ['A Google account with a YouTube channel.', 'If you manage several channels, pick the right one on Google’s screen.'],
            self::Facebook => ['A Facebook Page you are an admin of (personal profiles can’t be posted to by apps).', 'Tick every Page you want to manage when Facebook asks.'],
            self::Instagram => ['An Instagram Business or Creator account (Instagram app → Settings → Account type).', 'It must be linked to a Facebook Page. You log in with Facebook, and Instagram is connected automatically.'],
            self::TikTok => ['A TikTok account. Business or Creator accounts work best.', 'Until the app passes TikTok’s audit, new posts are published as private (“Only me”).'],
            self::LinkedIn => ['Your LinkedIn profile login.', 'Posts go to your personal profile feed.'],
            self::X => ['Your X (Twitter) account login.'],
            self::GoogleBusiness => ['The Google account that owns or manages the business on Google Maps.', 'No profile yet? Connect anyway, then create it from Grow on Google → Business Profile.'],
        };
    }

    /** Short description of what the connection gives you. */
    public function description(): string
    {
        return match ($this) {
            self::YouTube => 'Upload videos, read stats, read and reply to comments.',
            self::Facebook => 'Post videos to Pages, read stats, read and reply to comments.',
            self::Instagram => 'Publish Reels, read stats, read and reply to comments. Needs a Business or Creator account linked to a Facebook Page.',
            self::TikTok => 'Publish videos and read like/comment/view counts. TikTok does not expose comment text to apps.',
            self::LinkedIn => 'Post videos to your profile. Reading posts and comments needs LinkedIn Community Management approval.',
            self::X => 'Post text and videos. Importing posts, metrics and replies needs a paid X API plan.',
            self::GoogleBusiness => 'Create your business on Google Maps, keep its details up to date, and read and reply to Google reviews in the inbox.',
        };
    }

    /** Whether videos can be posted here from Create post. */
    public function canPublish(): bool
    {
        return $this !== self::GoogleBusiness;
    }

    /** @return string[] values of the platforms Create post can publish to */
    public static function publishable(): array
    {
        return array_map(fn (self $p) => $p->value, array_filter(self::cases(), fn (self $p) => $p->canPublish()));
    }
}
