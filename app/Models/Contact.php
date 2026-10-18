<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Someone on a client's mailing list. */
class Contact extends Model
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    /** Most contacts one client can keep. */
    public const LIMIT = 5000;

    protected $fillable = ['email', 'name', 'tags', 'source', 'status', 'token', 'subscribed_at', 'unsubscribed_at'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            $contact->token ??= Str::random(40);
            $contact->email = strtolower(trim($contact->email));
            $contact->subscribed_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSubscribed(Builder $query): void
    {
        $query->where('status', self::SUBSCRIBED);
    }

    /** Contacts with this tag (tags are stored comma-separated). */
    public function scopeTagged(Builder $query, ?string $tag): void
    {
        // Tags are saved as "a, b, c" (see normaliseTags), so match the tag alone, first, last or in the middle.
        if ($tag) {
            $query->where(fn ($q) => $q->where('tags', $tag)
                ->orWhere('tags', 'like', $tag.', %')
                ->orWhere('tags', 'like', '%, '.$tag)
                ->orWhere('tags', 'like', '%, '.$tag.', %'));
        }
    }

    /** @return string[] */
    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }

    public static function normaliseTags(?string $tags): ?string
    {
        $list = collect(explode(',', (string) $tags))->map(fn ($t) => Str::lower(trim($t)))->filter()->unique()->take(10);

        return $list->isEmpty() ? null : $list->implode(', ');
    }

    public function unsubscribe(): void
    {
        $this->update(['status' => self::UNSUBSCRIBED, 'unsubscribed_at' => now()]);
    }

    public function unsubscribeUrl(): string
    {
        return route('email.unsubscribe', $this->token);
    }
}
