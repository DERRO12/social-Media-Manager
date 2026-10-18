<?php

namespace App\Models;

use App\Services\Seo\ImageOptimizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** A photo a client uploads to their public page, prepared so it can show in Google Images. */
class ProfilePhoto extends Model
{
    protected $fillable = ['path', 'thumb_path', 'width', 'height', 'size', 'mime', 'title', 'alt', 'caption', 'keywords', 'location', 'is_public', 'sort'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (ProfilePhoto $photo) => app(ImageOptimizer::class)->delete($photo->path, $photo->thumb_path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_public', true)->orderBy('sort')->latest('id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function thumbUrl(): string
    {
        return Storage::disk('public')->url($this->thumb_path);
    }

    public function slug(): string
    {
        return Str::slug(Str::limit($this->title, 70, '')) ?: 'photo';
    }

    public function pageUrl(PublicProfile $profile): string
    {
        return route('profiles.photo', [$profile, $this, $this->slug()]);
    }

    /** What Google reads as the image's description; falls back to the title. */
    public function altText(): string
    {
        return $this->alt ?: $this->title;
    }

    /** @return string[] */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->keywords))));
    }

    /**
     * How ready this photo is for image search, with tips.
     *
     * @return array{score: int, checks: array<int, array{ok: bool, label: string, tip: string}>}
     */
    public function seoChecks(): array
    {
        $checks = [
            [str_word_count($this->title) >= 3, 'Descriptive title', 'Use 3+ words people search for, e.g. “Bridal braids in Kampala”.'],
            [mb_strlen((string) $this->alt) >= 10 && mb_strlen((string) $this->alt) <= 125, 'Alt text (10–125 characters)', 'Describe what is in the photo, as you would to someone who can’t see it.'],
            [mb_strlen((string) $this->caption) >= 50, 'Caption of 50+ characters', 'A sentence or two of context. Google reads the text around images.'],
            [count($this->keywordList()) > 0, 'Keywords', 'A few comma-separated search terms.'],
            [filled($this->location), 'Location', 'Helps you appear in local searches like “… near me”.'],
            [(int) $this->width >= 1200, 'Large image (1200px+ wide)', 'Google shows large images more prominently, including in Discover.'],
        ];

        $checks = array_map(fn ($c) => ['ok' => (bool) $c[0], 'label' => $c[1], 'tip' => $c[2]], $checks);

        return [
            'score' => (int) round(100 * count(array_filter($checks, fn ($c) => $c['ok'])) / count($checks)),
            'checks' => $checks,
        ];
    }
}
