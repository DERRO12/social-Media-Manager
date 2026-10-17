<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client saying "I've paid" through a payment link; the admin checks it and approves or rejects it. */
class PaymentClaim extends Model
{
    public const METHODS = [
        'paypal' => 'PayPal',
        'yo' => 'Mobile Money (Yo! Payments)',
        'other' => 'Other (bank, cash…)',
    ];

    protected $fillable = ['plan_id', 'method', 'reference', 'amount', 'currency', 'note', 'status', 'reviewed_by', 'reviewed_at', 'admin_note'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst($this->method);
    }

    public function statusColor(): string
    {
        return ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red'][$this->status] ?? 'zinc';
    }
}
