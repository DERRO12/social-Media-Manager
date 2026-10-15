<?php

namespace App\Console\Commands;

use App\Jobs\SyncSocialAccount;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

class SyncSocialAccounts extends Command
{
    protected $signature = 'social:sync';

    protected $description = 'Queue a content, metrics and comments sync for every connected account';

    public function handle(): int
    {
        SocialAccount::whereHas('user', fn ($q) => $q->whereNull('suspended_at'))
            ->each(fn (SocialAccount $account) => SyncSocialAccount::dispatch($account));

        return self::SUCCESS;
    }
}
