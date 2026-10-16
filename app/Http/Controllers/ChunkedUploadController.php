<?php

namespace App\Http\Controllers;

use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Large video uploads in pieces. Cloudflare (free plan) rejects any single request over 100 MB, so the
 * browser sends the file in CHUNK_MB pieces and the server joins them back together.
 */
class ChunkedUploadController extends Controller
{
    /** Must stay well under Cloudflare's 100 MB per-request limit and PHP's upload_max_filesize. */
    public const CHUNK_MB = 50;

    /** Detected type => file extension. */
    private const VIDEO_TYPES = [
        'video/mp4' => 'mp4',
        'video/x-m4v' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'total' => ['required', 'integer', 'min:1', 'max:'.$this->maxChunks()],
            'index' => ['required', 'integer', 'min:0', 'lt:total'],
            'chunk' => ['required', 'file', 'max:'.(self::CHUNK_MB + 1) * 1024],
        ]);

        $request->file('chunk')->storeAs(
            $this->dir($request->user()->id, $data['upload_id']),
            sprintf('%05d.part', $data['index']),
            'local',
        );

        return response()->json(['received' => (int) $data['index']]);
    }

    public function complete(Request $request): JsonResponse
    {
        $maxBytes = config('app.max_upload_mb') * 1024 * 1024;

        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'total' => ['required', 'integer', 'min:1', 'max:'.$this->maxChunks()],
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
        ], [
            'size.max' => 'This video is too large. The maximum is '.config('app.max_upload_mb').' MB.',
        ]);

        $user = $request->user();
        $chunks = Storage::disk('local');
        $dir = $this->dir($user->id, $data['upload_id']);

        for ($i = 0; $i < $data['total']; $i++) {
            if (! $chunks->exists(sprintf('%s/%05d.part', $dir, $i))) {
                return $this->reject('Part of the upload is missing. Please try again.');
            }
        }

        // Join the pieces into the final file, streaming so large videos never sit in memory.
        $public = Storage::disk('public');
        $base = "media/{$user->id}/".Str::uuid();
        $public->makeDirectory("media/{$user->id}");
        $target = $public->path("{$base}.part");

        $out = fopen($target, 'wb');
        for ($i = 0; $i < $data['total']; $i++) {
            $in = fopen($chunks->path(sprintf('%s/%05d.part', $dir, $i)), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        $chunks->deleteDirectory($dir);

        $size = filesize($target);
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($target);

        if ($size !== (int) $data['size'] || $size > $maxBytes) {
            @unlink($target);

            return $this->reject('The upload was incomplete. Please try again.');
        }

        if (! isset(self::VIDEO_TYPES[$type])) {
            @unlink($target);

            return $this->reject('Please upload an MP4, MOV or WebM video.');
        }

        $path = "{$base}.".self::VIDEO_TYPES[$type];
        rename($target, $public->path($path));

        $media = $user->media()->create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => mb_substr(basename($data['filename']), 0, 255),
            'mime_type' => $type,
            'size' => $size,
        ]);

        return response()->json(['id' => $media->id]);
    }

    private function maxChunks(): int
    {
        return (int) ceil(config('app.max_upload_mb') / self::CHUNK_MB);
    }

    /** Each client's pieces live in their own folder, so nobody can add to or finish someone else's upload. */
    private function dir(int $userId, string $uploadId): string
    {
        return "chunks/{$userId}/".strtolower($uploadId);
    }

    private function reject(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
