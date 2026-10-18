<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAudit extends Model
{
    protected $fillable = ['url', 'score', 'results'];

    protected function casts(): array
    {
        return ['results' => 'array', 'score' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scoreColor(): string
    {
        return $this->score >= 80 ? 'green' : ($this->score >= 50 ? 'amber' : 'red');
    }
}
