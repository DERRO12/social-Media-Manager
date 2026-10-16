<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupUploads extends Command
{
    protected $signature = 'media:cleanup';

    protected $description = 'Delete pieces of unfinished uploads and uploaded videos that were never used in a post';

    /** Unfinished uploads older than this are abandoned. */
    private const CHUNK_HOURS = 24;

    /** Videos uploaded but never attached to a post are removed after this. */
    private const ORPHAN_HOURS = 48;

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(self::CHUNK_HOURS)->getTimestamp();
        $chunkDirs = 0;

        foreach ($disk->directories('chunks') as $userDir) {
            foreach ($disk->directories($userDir) as $uploadDir) {
                $files = $disk->files($uploadDir);
                $newest = collect($files)->map(fn ($f) => $disk->lastModified($f))->max() ?? 0;

                if ($newest < $cutoff) {
                    $disk->deleteDirectory($uploadDir);
                    $chunkDirs++;
                }
            }
        }

        $orphans = 0;
        Media::doesntHave('posts')
            ->where('created_at', '<', now()->subHours(self::ORPHAN_HOURS))
            ->each(function (Media $media) use (&$orphans) {
                $media->deleteWithFile();
                $orphans++;
            });

        $this->info("Removed {$chunkDirs} unfinished upload(s) and {$orphans} unused video(s).");

        return self::SUCCESS;
    }
}
