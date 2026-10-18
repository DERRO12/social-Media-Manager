<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Services\Mail\Smtp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sends a campaign's pending emails through the client's own mailbox, a batch at a time, slowly enough
 * to look like a person (not a spammer) and never over the mailbox's daily limit. When the limit is
 * reached it continues the next day.
 */
class SendCampaign implements ShouldQueue
{
    use Queueable;

    public const BATCH = 50;

    public int $timeout = 900;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::with('user.mailbox', 'user.publicProfile')->find($this->campaignId);

        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }

        $mailbox = $campaign->user->mailbox;
        if (! $mailbox) {
            $campaign->update(['status' => 'failed', 'error' => 'The mailbox was disconnected. Reconnect it and press Resume.']);

            return;
        }

        $allowance = max(0, $mailbox->daily_limit - $mailbox->sentToday());
        if ($allowance === 0) {
            $campaign->update(['error' => 'Daily sending limit reached. The rest go out tomorrow.']);

            // (The "sync" queue can't wait, so it would loop; a real queue continues after midnight.)
            if (config('queue.default') !== 'sync') {
                self::dispatch($this->campaignId)->delay(now()->addDay()->startOfDay()->addMinutes(5));
            }

            return;
        }

        $mailer = Smtp::mailer('mailbox-'.$mailbox->id, $mailbox->mailerConfig());
        $sends = $campaign->sends()->where('status', 'pending')->with('contact')->limit(min(self::BATCH, $allowance))->get();

        foreach ($sends as $send) {
            if ($send->contact->status !== 'subscribed') {
                $send->update(['status' => 'skipped']); // unsubscribed after the campaign started

                continue;
            }

            try {
                $mailer->to($send->contact->email, $send->contact->name)->send(new CampaignMail($campaign, $send->contact, $mailbox, $send));
                $send->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $e) {
                $reason = Smtp::explain($e);
                $send->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 250)]);

                // A login problem will fail every email: stop and tell the client instead.
                if (str_contains($reason, 'rejected the login') || str_contains($reason, 'app password') || str_contains($reason, 'Could not reach')) {
                    $this->refreshCounts($campaign);
                    $campaign->update(['status' => 'paused', 'error' => $reason]);

                    return;
                }
            }

            Sleep::for(300)->milliseconds();
        }

        $this->refreshCounts($campaign);

        if ($campaign->sends()->where('status', 'pending')->exists()) {
            self::dispatch($this->campaignId); // next batch (or tomorrow, if today's limit is used up)
        } else {
            $campaign->update(['status' => 'sent', 'finished_at' => now()]);
        }
    }

    private function refreshCounts(EmailCampaign $campaign): void
    {
        $counts = $campaign->sends()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $campaign->update([
            'sent' => (int) ($counts['sent'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'opens' => EmailCampaignSend::where('email_campaign_id', $campaign->id)->whereNotNull('opened_at')->count(),
        ]);
    }
}
