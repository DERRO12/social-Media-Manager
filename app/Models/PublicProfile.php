<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A client's opt-in public page on this site (/p/{handle}) listing their videos and posts, with
 * per-video pages and structured data so search engines can index them.
 */
class PublicProfile extends Model
{
    /** Handles that would clash with app pages or look official. */
    public const RESERVED = ['admin', 'api', 'app', 'login', 'register', 'support', 'help', 'about', 'www', 'mail', 'root', 'socialhub', 'gokatale'];

    protected $fillable = ['handle', 'display_name', 'headline', 'bio', 'location', 'website', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
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
            ->whereNotNull('content_items.permalink')
            ->latest('content_items.published_at');
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
