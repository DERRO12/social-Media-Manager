<?php

namespace App\Services\Social;

use App\Enums\Platform;
use App\Models\Setting;
use App\Services\Social\Contracts\PlatformDriver;
use App\Services\Social\Drivers\FacebookDriver;
use App\Services\Social\Drivers\GoogleBusinessDriver;
use App\Services\Social\Drivers\InstagramDriver;
use App\Services\Social\Drivers\LinkedInDriver;
use App\Services\Social\Drivers\TikTokDriver;
use App\Services\Social\Drivers\XDriver;
use App\Services\Social\Drivers\YouTubeDriver;

class SocialManager
{
    /** @var array<string, PlatformDriver> */
    private array $drivers = [];

    public function driver(Platform $platform): PlatformDriver
    {
        return $this->drivers[$platform->value] ??= match ($platform) {
            Platform::YouTube => new YouTubeDriver(self::credentials('youtube')),
            Platform::Facebook => new FacebookDriver(self::credentials('meta')),
            Platform::Instagram => new InstagramDriver(self::credentials('meta')),
            Platform::TikTok => new TikTokDriver(self::credentials('tiktok')),
            Platform::LinkedIn => new LinkedInDriver(self::credentials('linkedin')),
            Platform::X => new XDriver(self::credentials('x')),
            Platform::GoogleBusiness => new GoogleBusinessDriver(self::credentials('youtube')),
        };
    }

    /**
     * The OAuth redirect URL registered with each platform. Always built from APP_URL, so it is the same
     * whether the app was opened at localhost, 127.0.0.1 or a LAN address (platforms match it exactly).
     */
    public static function callbackUrl(Platform $platform): string
    {
        return rtrim(config('app.url'), '/').route('accounts.callback', $platform, absolute: false);
    }

    /** Which credentials group each platform uses (Facebook and Instagram share one Meta app). */
    public static function credentialGroup(Platform $platform): string
    {
        return match ($platform) {
            Platform::Facebook, Platform::Instagram => 'meta',
            // One Google Cloud app (Admin → Settings → YouTube) covers YouTube and Business Profile.
            Platform::GoogleBusiness => 'youtube',
            default => $platform->value,
        };
    }

    /** Keys saved by the admin in Admin → Settings win over the .env values. */
    public static function credentials(string $group): array
    {
        $config = array_merge(config("services.{$group}", []), Setting::group($group));

        // Switches saved in Admin → Settings come back as "1"/"0" strings.
        foreach (['community_api', 'read_access'] as $flag) {
            if (array_key_exists($flag, $config)) {
                $config[$flag] = filter_var($config[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $config;
    }
}
