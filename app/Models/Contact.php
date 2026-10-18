<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Sms\Phone;
use Illuminate\Support\Str;

/** Someone on a client's mailing list. */
class Contact extends Model
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    /** Most contacts one client can keep. */
    public const LIMIT = 5000;

    protected $fillable = ['email', 'phone', 'name', 'tags', 'source', 'status', 'sms_status', 'token', 'subscribed_at', 'unsubscribed_at'];

    protected $attributes = ['status' => self::SUBSCRIBED, 'sms_status' => self::SUBSCRIBED, 'source' => 'manual'];

    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            $contact->token ??= Str::random(40);
            $contact->subscribed_at ??= now();
        });

        static::saving(function (Contact $contact) {
            $contact->email = filled($contact->email) ? strtolower(trim($contact->email)) : null;
            $contact->phone = Phone::normalise($contact->phone);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Can be emailed: has an address and hasn't opted out of email. */
    public function scopeSubscribed(Builder $query): void
    {
        $query->where('status', self::SUBSCRIBED)->whereNotNull('email');
    }

    /** Can be texted: has a phone number and hasn't opted out of SMS. */
    public function scopeSmsSubscribed(Builder $query): void
    {
        $query->where('sms_status', self::SUBSCRIBED)->whereNotNull('phone');
    }

    /** Reachable on a channel ("email" or "sms"). */
    public function scopeReachableBy(Builder $query, string $channel): void
    {
        $channel === 'sms' ? $query->smsSubscribed() : $query->subscribed();
    }

    public function isReachableBy(string $channel): bool
    {
        return $channel === 'sms'
            ? $this->phone && $this->sms_status === self::SUBSCRIBED
            : $this->email && $this->status === self::SUBSCRIBED;
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

    /** Short opt-out link for SMS, where every character counts: /s/{id}-{first 8 of the token}. */
    public function smsOptOutUrl(): string
    {
        return route('sms.optout', $this->id.'-'.substr($this->token, 0, 8));
    }

    public function unsubscribeSms(): void
    {
        $this->update(['sms_status' => self::UNSUBSCRIBED]);
    }
}
