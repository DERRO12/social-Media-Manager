<?php

namespace App\Models;

use App\Services\Mail\Smtp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's own mailbox their newsletters are sent from (Gmail, Outlook, Zoho… with an app password). */
class Mailbox extends Model
{
    protected $fillable = ['provider', 'host', 'port', 'username', 'password', 'from_name', 'reply_to', 'daily_limit', 'verified_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailerConfig(): array
    {
        return Smtp::config($this->host, (int) $this->port, $this->username, $this->password);
    }

    public function fromName(): string
    {
        return $this->from_name ?: $this->user->publicProfile?->name() ?: $this->user->name;
    }

    /** Emails already sent today from this mailbox (providers cap sending per day). */
    public function sentToday(): int
    {
        return EmailCampaignSend::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $this->user_id))
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }
}
