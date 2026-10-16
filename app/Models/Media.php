<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function deleteWithFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
        $this->delete();
    }

    /** "245 MB", "1.2 GB" */
    public function humanSize(): string
    {
        return $this->size >= 1024 ** 3
            ? round($this->size / 1024 ** 3, 1).' GB'
            : max(1, round($this->size / 1024 ** 2)).' MB';
    }

    /** Absolute path on the local filesystem, used when uploading bytes to a platform. */
    public function localPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /** Public URL. Instagram (and Facebook "file_url") fetch the video from here, so APP_URL must be reachable from the internet. */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }
}
