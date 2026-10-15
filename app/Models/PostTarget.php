<?php

namespace App\Models;

use App\Enums\TargetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTarget extends Model
{
    protected $fillable = [
        'social_account_id',
        'caption',
        'options',
        'status',
        'platform_post_id',
        'permalink',
        'error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'options' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** Per-platform caption override, falling back to the post's caption. */
    public function effectiveCaption(): string
    {
        return (string) ($this->caption ?: $this->post->caption);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
