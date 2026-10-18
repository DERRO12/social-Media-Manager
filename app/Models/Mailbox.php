<?php

namespace App\Models;

use App\Services\Mail\SiteMail;
use App\Services\Mail\Smtp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The mailbox a client's newsletters are sent from: their own (Gmail, Outlook, Zoho… with an app password),
 * or, if they haven't connected one, the admin's site mailbox (Admin → Settings → Email) under the client's
 * name with replies going to the client. The shared mailbox has a daily limit per client and in total, so
 * one Gmail account isn't pushed past what Google allows.
 */
class Mailbox extends Model
{
    protected $fillable = ['provider', 'host', 'port', 'username', 'password', 'from_name', 'reply_to', 'daily_limit', 'verified_at'];

    protected $hidden = ['password'];

    /** True for the admin's mailbox lent to a client (never saved). */
    public bool $shared = false;

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The client's own mailbox, else the shared one, else null (nothing to send with). */
    public static function forUser(User $user): ?self
    {
        if ($user->mailbox) {
            return $user->mailbox;
        }

        if (! SiteMail::configured() || self::sharedPerClient() <= 0) {
            return null;
        }

        $box = new self([
            'provider' => Setting::get('mail.provider', 'gmail'),
            'host' => Setting::get('mail.host'),
            'port' => (int) Setting::get('mail.port', 587),
            'username' => Setting::get('mail.username'),
            'password' => Setting::get('mail.password'),
            'from_name' => $user->publicProfile?->name() ?? $user->businessProfile?->name ?? $user->name,
            'reply_to' => $user->email,
            'daily_limit' => self::sharedPerClient(),
        ]);
        $box->user_id = $user->id;
        $box->setRelation('user', $user);
        $box->shared = true;

        return $box;
    }

    public static function sharedPerClient(): int
    {
        return (int) Setting::get('mail.shared_per_client', 100);
    }

    public static function sharedDailyTotal(): int
    {
        return (int) Setting::get('mail.shared_daily_total', 450);
    }

    /** Emails all clients together can still send through the shared mailbox today. */
    public static function sharedRemainingToday(): int
    {
        $used = EmailCampaignSend::where('shared', true)->where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count();

        return max(0, self::sharedDailyTotal() - $used);
    }

    /** Named mailer key, so a changed password is picked up (see Smtp::mailer()). */
    public function mailerName(): string
    {
        return $this->shared ? 'mailbox-shared' : 'mailbox-'.$this->id;
    }

    public function mailerConfig(): array
    {
        return Smtp::config($this->host, (int) $this->port, $this->username, $this->password);
    }

    public function fromName(): string
    {
        return $this->from_name ?: $this->user->publicProfile?->name() ?: $this->user->name;
    }

    /** Emails this client sent today through this kind of mailbox (own or shared). */
    public function sentToday(): int
    {
        return EmailCampaignSend::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $this->user_id))
            ->where('shared', $this->shared)
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }

    /** How many more emails can go out today. */
    public function remainingToday(): int
    {
        $left = max(0, (int) $this->daily_limit - $this->sentToday());

        return $this->shared ? min($left, self::sharedRemainingToday()) : $left;
    }
}
