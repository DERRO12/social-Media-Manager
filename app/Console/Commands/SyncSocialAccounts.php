<?php

namespace App\Console\Commands;

use App\Jobs\SyncSocialAccount;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

class SyncSocialAccounts extends Command
{
    protected $signature = 'social:sync';

    protected $description = 'Queue a content, metrics and comments sync for every connected account of clients with access';

    public function handle(): int
    {
        SocialAccount::with('user.subscription')
            ->whereHas('user', fn ($q) => $q->whereNull('suspended_at'))
            ->each(function (SocialAccount $account) {
                if ($account->user->hasAccess()) {
                    SyncSocialAccount::dispatch($account);
                }
            });

        return self::SUCCESS;
    }
}
