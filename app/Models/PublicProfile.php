<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A client's opt-in public page on this site (/p/{handle}) listing their videos and posts, with
 * per-video pages and structured data so search engines can index them.
 */
class PublicProfile extends Model
{
    /** Handles that would clash with app pages or look official. */
    public const RESERVED = ['admin', 'api', 'app', 'login', 'register', 'support', 'help', 'about', 'www', 'mail', 'root', 'socialhub', 'gokatale'];

    protected $fillable = ['handle', 'display_name', 'headline', 'bio', 'location', 'website', 'is_public', 'seo_title', 'seo_description', 'avatar_path', 'show_business', 'newsletter_enabled', 'newsletter_text', 'links', 'whatsapp'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'show_business' => 'boolean', 'newsletter_enabled' => 'boolean', 'links' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'handle';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Content shown on the public page: everything with a link, newest first, minus items the owner hid. */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(ContentItem::class, SocialAccount::class, 'user_id', 'social_account_id', 'user_id', 'id')
            ->where('content_items.hidden_from_profile', false)
            ->where('content_items.type', '!=', 'business') // a Google Business review summary, not a post
            ->whereNotNull('content_items.permalink')
            ->latest('content_items.published_at');
    }

    /** Photos uploaded for the page and shown publicly. */
    public function photos(): HasMany
    {
        return $this->hasMany(ProfilePhoto::class, 'user_id', 'user_id')->visible();
    }

    /** The page picture: uploaded logo/photo, else a connected account's picture. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : $this->user->socialAccounts->firstWhere('avatar_url', '!=', null)?->avatar_url;
    }

    /** The business details to show, if the owner turned that on and has filled them in. */
    public function business(): ?BusinessProfile
    {
        return $this->show_business ? $this->user->businessProfile : null;
    }

    /** The title Google shows: the owner's own, or name + headline. */
    public function searchTitle(): string
    {
        return $this->seo_title ?: $this->name().($this->headline ? ': '.$this->headline : '');
    }

    public function searchDescription(): string
    {
        return $this->seo_description ?: ($this->headline ?: Str::limit((string) $this->bio, 155)) ?: 'Videos, posts and photos by '.$this->name().'.';
    }

    /** wa.me chat link for the WhatsApp button, with a friendly first message. */
    public function whatsappUrl(): ?string
    {
        return $this->whatsapp ? 'https://wa.me/'.$this->whatsapp.'?text='.rawurlencode('Hi '.$this->name().', I found you on '.config('app.name').'.') : null;
    }

    public function url(): string
    {
        return route('profiles.show', $this);
    }

    public function name(): string
    {
        return $this->display_name ?: $this->user->name;
    }
}
