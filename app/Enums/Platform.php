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

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X (Twitter)',
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
            self::X => 'Post videos, read metrics and replies. Requires a paid X API tier.',
        };
    }
}
