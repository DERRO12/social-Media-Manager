<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'media_id',
        'title',
        'caption',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** Queue a publish job for every target that hasn't gone out yet. */
    public function dispatchPublishing(): void
    {
        $this->update(['status' => PostStatus::Publishing]);

        $this->targets()
            ->whereIn('status', [TargetStatus::Pending, TargetStatus::Failed])
            ->each(function (PostTarget $target) {
                $target->update(['status' => TargetStatus::Publishing, 'error' => null]);
                PublishPostTarget::dispatch($target);
            });
    }

    /** Recompute the overall status from the individual platform targets. */
    public function refreshStatus(): void
    {
        $statuses = $this->targets()->pluck('status');

        if ($statuses->contains(fn ($s) => in_array($s, [TargetStatus::Publishing, TargetStatus::Pending]))) {
            return;
        }

        $published = $statuses->filter(fn ($s) => $s === TargetStatus::Published)->count();

        $this->update([
            'status' => match (true) {
                $published === $statuses->count() => PostStatus::Published,
                $published === 0 => PostStatus::Failed,
                default => PostStatus::PartiallyFailed,
            },
            'published_at' => $published > 0 ? now() : null,
        ]);
    }
}
