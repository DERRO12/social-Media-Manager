<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email of a campaign to one contact. */
class EmailCampaignSend extends Model
{
    protected $fillable = ['email_campaign_id', 'contact_id', 'status', 'error', 'cost', 'sent_at', 'opened_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
