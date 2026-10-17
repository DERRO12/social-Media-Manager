<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = ['user_id', 'course_id', 'score', 'passed', 'answers'];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'answers' => 'array',
            'score' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
