<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Certificate extends Model
{
    protected $fillable = ['code', 'user_id', 'course_id', 'recipient_name', 'course_title', 'score', 'issued_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    /** Readable, hard-to-guess code like "GS-7K2M-9QXP" (no 0/O/1/I to avoid misreading). */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $part = fn () => collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
            $code = static::prefix().'-'.$part().'-'.$part();
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Two-letter prefix from the app name: "Gokatale Social" → "GS", "SocialHub" → "SO". */
    public static function prefix(): string
    {
        $words = collect(preg_split('/\s+/', config('app.name')))
            ->map(fn ($w) => Str::upper(preg_replace('/[^A-Za-z]/', '', $w)))->filter()->values();

        $prefix = $words->count() >= 2
            ? $words[0][0].$words[1][0]
            : Str::substr($words->first() ?? '', 0, 2);

        return strlen($prefix) === 2 ? $prefix : 'SH';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null;
    }

    public function verifyUrl(): string
    {
        return rtrim(config('app.url'), '/').route('certificates.show', $this, absolute: false);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** "Add to profile" link that pre-fills a LinkedIn Licenses & certifications entry. */
    public function linkedInUrl(): string
    {
        return 'https://www.linkedin.com/profile/add?'.http_build_query([
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $this->course_title,
            'organizationName' => Setting::get('academy.issuer', Setting::get('legal.owner', config('app.name'))),
            'issueYear' => $this->issued_at->year,
            'issueMonth' => $this->issued_at->month,
            'certUrl' => $this->verifyUrl(),
            'certId' => $this->code,
        ]);
    }
}
