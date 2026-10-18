<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\PostStatus;
use App\Services\Billing\SubscriptionManager;
use App\Services\Mail\SiteMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
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

        // Deleting an account removes everything: platform access is revoked, then uploaded video files are
        // deleted (the database rows go via cascading foreign keys, which don't remove files on disk).
        static::deleting(function (User $user) {
            $user->socialAccounts->each(fn (SocialAccount $account) => $account->disconnect());

            $user->media->groupBy('disk')->each(function ($files, $disk) {
                Storage::disk($disk)->delete($files->pluck('path')->all());
            });

            // Public page photos and the page picture are files too.
            $user->photos->each->delete();
            if ($user->publicProfile?->avatar_path) {
                Storage::disk('public')->delete($user->publicProfile->avatar_path);
            }
        });
    }

    /**
     * Email confirmation is only enforced when the admin turns it on (Admin → Settings → Email) and email
     * sending works; otherwise everyone counts as confirmed, so nobody gets locked out.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null || ! SiteMail::requiresVerification();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function paymentClaims(): HasMany
    {
        return $this->hasMany(PaymentClaim::class);
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function publicProfile(): HasOne
    {
        return $this->hasOne(PublicProfile::class);
    }

    public function seoAudits(): HasMany
    {
        return $this->hasMany(SeoAudit::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProfilePhoto::class);
    }

    public function mailbox(): HasOne
    {
        return $this->hasOne(Mailbox::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function emailCampaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    public function completedLessonIds(): array
    {
        return LessonProgress::where('user_id', $this->id)->pluck('lesson_id')->all();
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
