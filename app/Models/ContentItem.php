<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends Model
{
    protected $fillable = [
        'post_target_id',
        'platform_content_id',
        'type',
        'title',
        'caption',
        'permalink',
        'thumbnail_url',
        'published_at',
        'views',
        'likes',
        'comments_count',
        'comments_synced_count',
        'comments_synced_at',
        'shares',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'comments_synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
