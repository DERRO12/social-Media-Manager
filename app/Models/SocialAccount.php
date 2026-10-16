<?php

namespace App\Models;

use App\Enums\Platform;
use App\Services\Social\Contracts\PlatformDriver;
use App\Services\Social\SocialManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'platform',
        'platform_user_id',
        'name',
        'username',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'meta',
        'last_synced_at',
        'last_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function postTargets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function driver(): PlatformDriver
    {
        return app(SocialManager::class)->driver($this->platform);
    }

    /**
     * Revoke our access at the platform (when supported), then delete the account and everything imported from it.
     * One Google login can cover several channels that share a token; it is only revoked with the last of them.
     */
    public function disconnect(): void
    {
        $sharedWithOthers = static::where('user_id', $this->user_id)
            ->where('platform', $this->platform)
            ->whereKeyNot($this->getKey())
            ->get()
            ->contains(fn (SocialAccount $other) => $this->refresh_token
                ? $other->refresh_token === $this->refresh_token
                : $other->access_token === $this->access_token);

        if (! $sharedWithOthers) {
            try {
                $this->driver()->revoke($this);
            } catch (\Throwable $e) {
                report($e); // never block disconnecting; the token is deleted below either way
            }
        }

        $this->delete();
    }

    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->subMinutes(5)->isPast();
    }
}
