<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Models\Mailbox;
use App\Services\Mail\Smtp;
use App\Services\Sms\EgoSms;
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

        if ($campaign->isSms()) {
            $this->sendSms($campaign, app(EgoSms::class));

            return;
        }

        // The client's own mailbox, or the admin's shared one.
        $mailbox = Mailbox::forUser($campaign->user);
        if (! $mailbox) {
            $campaign->update(['status' => 'paused', 'error' => 'No mailbox to send from. Connect your mailbox and press Resume.']);

            return;
        }

        $allowance = $mailbox->remainingToday();
        if ($allowance === 0) {
            $campaign->update(['error' => $mailbox->shared
                ? 'Today’s limit on the shared mailbox is reached. The rest go out tomorrow (connect your own Gmail to send more).'
                : 'Daily sending limit reached. The rest go out tomorrow.']);

            // (The "sync" queue can't wait, so it would loop; a real queue continues after midnight.)
            if (config('queue.default') !== 'sync') {
                self::dispatch($this->campaignId)->delay(now()->addDay()->startOfDay()->addMinutes(5));
            }

            return;
        }

        $mailer = Smtp::mailer($mailbox->mailerName(), $mailbox->mailerConfig());
        $sends = $campaign->sends()->where('status', 'pending')->with('contact')->limit(min(self::BATCH, $allowance))->get();

        foreach ($sends as $send) {
            if ($send->contact->status !== 'subscribed') {
                $send->update(['status' => 'skipped']); // unsubscribed after the campaign started

                continue;
            }

            try {
                $mailer->to($send->contact->email, $send->contact->name)->send(new CampaignMail($campaign, $send->contact, $mailbox, $send));
                $send->update(['status' => 'sent', 'sent_at' => now(), 'shared' => $mailbox->shared]);
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

    /** Text messages through EgoSMS, one per contact, with a short opt-out link. */
    private function sendSms(EmailCampaign $campaign, EgoSms $sms): void
    {
        $user = $campaign->user;
        $account = EgoSms::accountFor($user);

        if (! $account) {
            $campaign->update(['status' => 'paused', 'error' => 'SMS isn’t set up. Connect EgoSMS and press Resume.']);

            return;
        }

        $allowance = min(self::BATCH, EgoSms::remainingFor($user));
        if ($allowance === 0) {
            $campaign->update(['status' => 'paused', 'error' => 'This month’s SMS allowance is used up. Connect your own EgoSMS account to continue, then press Resume.']);

            return;
        }

        $links = Smtp::links($user);
        $sends = $campaign->sends()->where('status', 'pending')->with('contact')->limit($allowance)->get();

        foreach ($sends as $send) {
            if (! $send->contact->isReachableBy('sms')) {
                $send->update(['status' => 'skipped']);

                continue;
            }

            $message = Smtp::personalise((string) $campaign->body, $send->contact->name, $links)."\nOpt out: ".$send->contact->smsOptOutUrl();
            $result = $sms->send($account, $send->contact->phone, $message);

            if ($result['ok']) {
                $send->update(['status' => 'sent', 'sent_at' => now(), 'cost' => $result['cost']]);
            } else {
                $send->update(['status' => 'failed', 'error' => mb_substr($result['error'], 0, 250)]);

                if (EgoSms::isFatal($result['error'])) {
                    $this->refreshCounts($campaign);
                    $campaign->update(['status' => 'paused', 'error' => $result['error']]);

                    return;
                }
            }

            Sleep::for(200)->milliseconds();
        }

        $this->refreshCounts($campaign);

        if ($campaign->sends()->where('status', 'pending')->exists()) {
            self::dispatch($this->campaignId);
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
