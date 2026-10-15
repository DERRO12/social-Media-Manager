<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\TrialEndingSoon;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;

class BillingMaintenance extends Command
{
    protected $signature = 'billing:maintain';

    protected $description = 'Expire finished trials/plans and email clients whose trial ends within 3 days';

    public function handle(SubscriptionManager $manager): int
    {
        $expired = $manager->sweepExpired();

        $reminded = 0;
        Subscription::with('user')
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('trial_reminder_sent_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(3)])
            ->each(function (Subscription $sub) use (&$reminded) {
                $sub->user->notify(new TrialEndingSoon($sub));
                $sub->update(['trial_reminder_sent_at' => now()]);
                $reminded++;
            });

        $this->info("Expired {$expired}, reminded {$reminded}.");

        return self::SUCCESS;
    }
}
