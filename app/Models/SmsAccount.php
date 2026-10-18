<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's own EgoSMS account, so their SMS show their sender ID and use their credit. */
class SmsAccount extends Model
{
    protected $fillable = ['username', 'password', 'sender_id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
