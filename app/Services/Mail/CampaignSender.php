<?php

namespace App\Services\Mail;

use App\Jobs\SendCampaign;
use App\Models\Contact;
use App\Models\EmailCampaign;
use RuntimeException;

/** Starts a newsletter: picks the recipients and hands the sending to a background job. */
class CampaignSender
{
    public function start(EmailCampaign $campaign): void
    {
        $user = $campaign->user;

        if (! $user->mailbox) {
            throw new RuntimeException('Connect your mailbox first (Email marketing → Settings).');
        }

        if (trim($campaign->subject) === '' || trim((string) $campaign->body) === '') {
            throw new RuntimeException('Add a subject and a message first.');
        }

        $contactIds = $user->contacts()->subscribed()->tagged($campaign->tag)->pluck('id');

        if ($contactIds->isEmpty()) {
            throw new RuntimeException($campaign->tag ? "No subscribed contacts have the tag “{$campaign->tag}”." : 'You have no subscribed contacts yet.');
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
