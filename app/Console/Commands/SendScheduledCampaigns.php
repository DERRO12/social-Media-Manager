<?php

namespace App\Console\Commands;

use App\Services\Mail\CampaignSender;
use Illuminate\Console\Command;

class SendScheduledCampaigns extends Command
{
    protected $signature = 'email:send-scheduled';

    protected $description = 'Start email campaigns whose scheduled time has come';

    public function handle(CampaignSender $sender): int
    {
        $this->info('Started '.$sender->startDue().' campaign(s).');

        return self::SUCCESS;
    }
}
