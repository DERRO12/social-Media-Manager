<?php

namespace App\Models;

use App\Support\YouTube;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class Lesson extends Model
{
    protected $fillable = ['course_module_id', 'title', 'youtube_id', 'content', 'duration_minutes', 'is_preview', 'sort'];

    protected function casts(): array
    {
        return [
            'is_preview' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function isVideo(): bool
    {
        return filled($this->youtube_id);
    }

    public function embedUrl(): ?string
    {
        return $this->isVideo() ? YouTube::embedUrl($this->youtube_id) : null;
    }

    public function thumbnailUrl(): ?string
    {
        return $this->isVideo() ? YouTube::thumbnail($this->youtube_id) : null;
    }

    /** Lesson notes are written in Markdown by the admin; raw HTML is stripped. */
    public function contentHtml(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
