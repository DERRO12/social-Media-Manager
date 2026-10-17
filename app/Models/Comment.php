<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = [
        'social_account_id',
        'platform_comment_id',
        'parent_platform_comment_id',
        'author_name',
        'author_handle',
        'author_avatar_url',
        'body',
        'likes',
        'is_own',
        'commented_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_own' => 'boolean',
            'commented_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** Replies in the same thread (platform comment IDs are unique per platform). */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_platform_comment_id', 'platform_comment_id')
            ->orderBy('commented_at');
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at')->where('is_own', false);
    }
}
