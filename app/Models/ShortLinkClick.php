<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortLinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['date', 'clicks'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
