<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\PostStatus;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Defaults for freshly created users, before the row is re-read from the database. */
    protected $attributes = [
        'is_admin' => false,
        'timezone' => 'UTC',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function scopeWithBillingStatus(Builder $query, string $status): void
    {
        $query->whereHas('subscription', fn ($q) => $q->where('status', $status));
    }

    protected static function booted(): void
    {
        // Every new client starts on the trial the admin configured.
        static::created(function (User $user) {
            if (! $user->isAdmin()) {
                app(SubscriptionManager::class)->startDefaultTrial($user);
            }
        });
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** Admins always have access; clients need a valid trial or paid plan. */
    public function hasAccess(): bool
    {
        return $this->isAdmin() || (bool) $this->subscription?->isValid();
    }

    public function currentPlan(): ?Plan
    {
        return $this->subscription?->isValid() ? $this->subscription->plan : null;
    }

    /** How many more social accounts the plan allows (null = unlimited). */
    public function remainingAccountSlots(): ?int
    {
        if ($this->isAdmin()) {
            return null;
        }

        $max = $this->currentPlan()?->max_social_accounts;

        if (! $this->hasAccess()) {
            return 0;
        }

        return $max === null ? null : max(0, $max - $this->socialAccounts()->count());
    }

    /** Non-draft posts created this calendar month (what the monthly post limit counts). */
    public function postsThisMonth(): int
    {
        return $this->posts()
            ->where('status', '!=', PostStatus::Draft)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** How many more posts can be published this month (null = unlimited). */
    public function remainingPosts(): ?int
    {
        if ($this->isAdmin()) {
            return null;
        }

        if (! $this->hasAccess()) {
            return 0;
        }

        $max = $this->currentPlan()?->max_posts_per_month;

        return $max === null ? null : max(0, $max - $this->postsThisMonth());
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Format a timestamp in this user's own timezone. */
    public function localTime(?CarbonInterface $time, string $format = 'M j, Y g:i A'): string
    {
        return $time ? $time->clone()->setTimezone($this->timezone ?: 'UTC')->format($format) : '—';
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function contentItems(): HasManyThrough
    {
        return $this->hasManyThrough(ContentItem::class, SocialAccount::class);
    }

    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(Comment::class, SocialAccount::class);
    }

    public function scopeClients(Builder $query): void
    {
        $query->where('is_admin', false);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
