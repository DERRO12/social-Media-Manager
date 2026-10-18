<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prepares a Google Business Profile. Google doesn't let apps create or verify profiles, so this
 * stores the details, scores how complete they are, and guides the owner through Google's own pages.
 */
class BusinessProfile extends Model
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** Things to do on Google after the details are ready: key => [label, help]. */
    public const CHECKLIST = [
        'created' => ['Profile created on Google', 'business.google.com → Add your business.'],
        'verified' => ['Profile verified', 'Follow Google’s verification steps (video, phone, email or postcard).'],
        'photos' => ['10+ real photos added', 'Logo, cover, your place, team, work and products. No stock photos.'],
        'services' => ['Products or services listed', 'With short descriptions and prices where possible.'],
        'first_update' => ['First update (post) published', 'An offer, a new product, an event or a tip, with a photo.'],
        'reviews_asked' => ['Asked 5 customers for reviews', 'Send them your review link from this page.'],
        'reviews_replied' => ['Replied to every review', 'Thank good reviews by name; answer bad ones calmly.'],
        'search_console' => ['Website added to Google Search Console', 'search.google.com/search-console, then submit your sitemap.'],
    ];

    protected $fillable = [
        'name', 'primary_category', 'secondary_categories', 'keywords', 'city', 'address', 'service_area',
        'phone', 'website', 'booking_url', 'description', 'hours', 'services', 'place_id', 'checklist',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'services' => 'array',
            'checklist' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Google's "write a review" link for this business, once the Place ID is known. */
    public function reviewUrl(): ?string
    {
        return $this->place_id ? 'https://search.google.com/local/writereview?placeid='.urlencode($this->place_id) : null;
    }

    /**
     * How ready the profile is, out of 100: half for the details, half for the checklist on Google.
     *
     * @return array{score: int, missing: string[]}
     */
    public function completeness(): array
    {
        $fields = [
            'Business name' => filled($this->name),
            'Primary category' => filled($this->primary_category),
            'Extra categories' => filled($this->secondary_categories),
            'City' => filled($this->city),
            'Address or service area' => filled($this->address) || filled($this->service_area),
            'Phone' => filled($this->phone),
            'Website or booking link' => filled($this->website) || filled($this->booking_url),
            'Description (250+ characters)' => mb_strlen((string) $this->description) >= 250,
            'Opening hours' => collect($this->hours ?? [])->contains(fn ($d) => ! empty($d['open'])),
            'Services' => count(array_filter($this->services ?? [])) > 0,
        ];

        $done = collect($this->checklist ?? [])->filter()->keys()->intersect(array_keys(self::CHECKLIST))->count();
        $score = (int) round(collect($fields)->filter()->count() / count($fields) * 50 + $done / count(self::CHECKLIST) * 50);

        return ['score' => $score, 'missing' => collect($fields)->reject()->keys()->all()];
    }

    /**
     * A natural, keyword-aware description draft (Google allows up to 750 characters).
     * The owner edits it; no keyword stuffing.
     */
    public function draftDescription(): string
    {
        $name = $this->name ?: 'We';
        $category = mb_strtolower((string) $this->primary_category) ?: 'business';
        $where = $this->city ? " in {$this->city}" : '';
        $area = $this->service_area ? " We serve {$this->service_area}." : '';
        $services = collect($this->services ?? [])->filter()->take(4)->values();
        $keywords = collect(explode(',', (string) $this->keywords))->map(fn ($k) => trim($k))->filter()->take(3)->values();

        $text = "{$name} is a {$category}{$where}.{$area}";
        if ($services->isNotEmpty()) {
            $list = $services->count() > 1 ? $services->slice(0, -1)->implode(', ').' and '.$services->last() : $services->first();
            $text .= " We offer {$list}.";
        }
        if ($keywords->isNotEmpty()) {
            $text .= ' Customers choose us for '.$keywords->implode(', ').'.';
        }
        $text .= ' '.($this->booking_url || $this->phone || $this->website
            ? 'Call, message or book online today and we will be happy to help.'
            : 'Get in touch today and we will be happy to help.');

        return mb_substr(trim($text), 0, 750);
    }
}
