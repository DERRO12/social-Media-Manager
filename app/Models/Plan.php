<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'currency',
        'interval',
        'max_social_accounts',
        'max_posts_per_month',
        'stripe_price_id',
        'paypal_url',
        'yo_url',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'max_social_accounts' => 'integer',
            'max_posts_per_month' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort')->orderBy('price');
    }

    public function formattedPrice(): string
    {
        return $this->currency.' '.number_format((float) $this->price, 2).' / '.$this->interval;
    }

    /** Monthly-equivalent price, for revenue estimates. */
    public function monthlyPrice(): float
    {
        return $this->interval === 'year' ? (float) $this->price / 12 : (float) $this->price;
    }

    /** Payment links the admin set for this plan, e.g. ['paypal' => 'https://paypal.me/…', 'yo' => 'https://…']. */
    public function paymentLinks(): array
    {
        return array_filter(['paypal' => $this->paypal_url, 'yo' => $this->yo_url]);
    }

    /** End of a paid period starting at $from: one month or one year later. */
    public function periodEnd(\Carbon\CarbonInterface $from): \Carbon\CarbonInterface
    {
        return $this->interval === 'year' ? $from->clone()->addYear() : $from->clone()->addMonth();
    }

    public function limitLabel(?int $limit): string
    {
        return $limit === null ? 'Unlimited' : (string) $limit;
    }
}
