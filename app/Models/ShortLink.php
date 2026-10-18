<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A short, trackable link (/go/{code}) to any page, with optional campaign (UTM) tags so the destination's
 * analytics (e.g. Google Analytics) can tell where visitors came from.
 */
class ShortLink extends Model
{
    /** Where a link is shared: label => [utm_source, utm_medium]. */
    public const PLACES = [
        'WhatsApp status or chat' => ['whatsapp', 'social'],
        'Instagram bio or story' => ['instagram', 'social'],
        'TikTok bio' => ['tiktok', 'social'],
        'Facebook post' => ['facebook', 'social'],
        'X post' => ['x', 'social'],
        'YouTube description' => ['youtube', 'video'],
        'Email newsletter' => ['newsletter', 'email'],
        'SMS' => ['sms', 'sms'],
        'Flyer, poster or business card (QR)' => ['print', 'qr'],
    ];

    protected $fillable = ['code', 'url', 'title', 'utm_source', 'utm_medium', 'utm_campaign', 'clicks', 'last_clicked_at'];

    protected function casts(): array
    {
        return ['last_clicked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ShortLink $link) {
            while (! $link->code || static::where('code', $link->code)->exists()) {
                $link->code = Str::lower(Str::random(6));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyClicks(): HasMany
    {
        return $this->hasMany(ShortLinkClick::class);
    }

    public function shortUrl(): string
    {
        return route('go', $this->code);
    }

    /** The real address with the campaign tags added (existing query parameters are kept). */
    public function destination(): string
    {
        $tags = array_filter(['utm_source' => $this->utm_source, 'utm_medium' => $this->utm_medium, 'utm_campaign' => $this->utm_campaign]);

        if (! $tags) {
            return $this->url;
        }

        [$base, $fragment] = array_pad(explode('#', $this->url, 2), 2, null);

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($tags).($fragment !== null ? '#'.$fragment : '');
    }

    public function recordClick(): void
    {
        $this->increment('clicks', 1, ['last_clicked_at' => now()]);

        $today = now()->toDateString();

        // One row per day; if two clicks arrive at once, the second insert loses the race and just counts.
        if (! $this->dailyClicks()->whereDate('date', $today)->increment('clicks')) {
            try {
                $this->dailyClicks()->create(['date' => $today, 'clicks' => 1]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $this->dailyClicks()->whereDate('date', $today)->increment('clicks');
            }
        }
    }
}
