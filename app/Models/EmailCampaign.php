<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A newsletter a client writes and sends to their contacts. */
class EmailCampaign extends Model
{
    protected $fillable = ['channel', 'subject', 'preheader', 'body', 'tag', 'status', 'scheduled_at', 'started_at', 'finished_at', 'recipients', 'sent', 'failed', 'opens', 'error'];

    protected $attributes = ['status' => 'draft', 'subject' => '', 'channel' => 'email'];

    public function isSms(): bool
    {
        return $this->channel === 'sms';
    }

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailCampaignSend::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function openRate(): ?int
    {
        return $this->sent ? (int) round(100 * $this->opens / $this->sent) : null;
    }

    public function statusColor(): string
    {
        return ['draft' => 'zinc', 'scheduled' => 'sky', 'sending' => 'amber', 'paused' => 'amber', 'sent' => 'green', 'failed' => 'red'][$this->status] ?? 'zinc';
    }
}
