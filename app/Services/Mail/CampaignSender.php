<?php

namespace App\Services\Mail;

use App\Jobs\SendCampaign;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Services\Sms\EgoSms;
use RuntimeException;

/** Starts a newsletter: picks the recipients and hands the sending to a background job. */
class CampaignSender
{
    public function start(EmailCampaign $campaign): void
    {
        $user = $campaign->user;

        if ($campaign->isSms()) {
            if (! EgoSms::accountFor($user)) {
                throw new RuntimeException('SMS isn’t set up yet. Connect your EgoSMS account in Email & SMS → Settings.');
            }
            if (trim((string) $campaign->body) === '') {
                throw new RuntimeException('Write your message first.');
            }
        } else {
            if (! $user->mailbox) {
                throw new RuntimeException('Connect your mailbox first (Email marketing → Settings).');
            }
            if (trim($campaign->subject) === '' || trim((string) $campaign->body) === '') {
                throw new RuntimeException('Add a subject and a message first.');
            }
        }

        foreach (['{review_link}' => 'Add your Google Place ID in Grow on Google → Business Profile first, so {review_link} works.', '{page_link}' => 'Turn on your public page first, so {page_link} works.'] as $tag => $problem) {
            if (str_contains($campaign->subject.$campaign->body, $tag) && ! isset(Smtp::links($user)[$tag])) {
                throw new RuntimeException($problem);
            }
        }

        $contactIds = $user->contacts()->reachableBy($campaign->channel)->tagged($campaign->tag)->pluck('id');

        if ($contactIds->isEmpty()) {
            $who = $campaign->isSms() ? 'contacts with a phone number' : 'subscribed contacts';
            throw new RuntimeException($campaign->tag ? "No {$who} have the tag “{$campaign->tag}”." : "You have no {$who} yet.");
        }

        if ($campaign->isSms() && $contactIds->count() > EgoSms::remainingFor($user)) {
            $left = EgoSms::remainingFor($user);
            throw new RuntimeException("This goes to {$contactIds->count()} people, but you have {$left} SMS left this month. Connect your own EgoSMS account to send more, or send to a tag.");
        }

        // One row per recipient, so a restart never emails anyone twice.
        foreach ($contactIds->chunk(500) as $chunk) {
            $campaign->sends()->insertOrIgnore($chunk->map(fn ($id) => [
                'email_campaign_id' => $campaign->id,
                'contact_id' => $id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all());
        }

        $campaign->update([
            'status' => 'sending',
            'started_at' => now(),
            'recipients' => $campaign->sends()->count(),
            'error' => null,
        ]);

        SendCampaign::dispatch($campaign->id);
    }

    /** Campaigns whose scheduled time has come. */
    public function startDue(): int
    {
        $count = 0;

        EmailCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->with('user.mailbox')->each(function (EmailCampaign $campaign) use (&$count) {
            try {
                $this->start($campaign);
                $count++;
            } catch (RuntimeException $e) {
                $campaign->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }
        });

        return $count;
    }
}
