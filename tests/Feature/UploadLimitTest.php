<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Large videos are uploaded in pieces so Cloudflare's 100 MB per-request limit doesn't apply. */
class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    /** Bytes that file-type detection recognises as an MP4 video. */
    private function mp4Bytes(int $padding = 4000): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", $padding);
    }

    private function client(): User
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['platform' => Platform::YouTube, 'platform_user_id' => 'UC1', 'name' => 'Channel', 'access_token' => 't']);

        return $user;
    }

    /** Send $bytes in $pieces chunks, then finish the upload. */
    private function upload(User $user, string $bytes, int $pieces = 2, string $name = 'my video.mp4', ?string $uploadId = null)
    {
        $uploadId ??= (string) Str::uuid();
        $size = (int) ceil(strlen($bytes) / $pieces);

        foreach (str_split($bytes, $size) as $i => $piece) {
            $this->actingAs($user)->post('/uploads/chunk', [
                'upload_id' => $uploadId,
                'index' => $i,
                'total' => $pieces,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $piece),
            ])->assertOk();
        }

        return $this->actingAs($user)->postJson('/uploads/complete', [
            'upload_id' => $uploadId,
            'total' => $pieces,
            'filename' => $name,
            'size' => strlen($bytes),
        ]);
    }

    public function test_the_page_shows_the_real_upload_limit(): void
    {
        config(['app.max_upload_mb' => 2048]);

        $this->actingAs($this->client())->get('/compose')
            ->assertOk()
            ->assertSee('up to 2 GB')
            ->assertSee('maxMb: 2048', false)
            ->assertSee(route('uploads.chunk'), false);
    }

    public function test_pieces_are_joined_into_one_video(): void
    {
        $user = $this->client();
        $bytes = $this->mp4Bytes();

        $id = $this->upload($user, $bytes, pieces: 3)->assertOk()->json('id');

        $media = Media::findOrFail($id);
        $this->assertSame($user->id, $media->user_id);
        $this->assertSame('video/mp4', $media->mime_type);
        $this->assertSame(strlen($bytes), (int) $media->size);
        $this->assertSame('my video.mp4', $media->original_name);
        $this->assertSame($bytes, Storage::disk('public')->get($media->path));
        $this->assertSame([], Storage::disk('local')->allFiles('chunks'));
    }

    public function test_a_missing_piece_is_rejected(): void
    {
        $user = $this->client();
        $uploadId = (string) Str::uuid();

        $this->actingAs($user)->post('/uploads/chunk', [
            'upload_id' => $uploadId, 'index' => 0, 'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'part one'),
        ])->assertOk();

        $this->postJson('/uploads/complete', ['upload_id' => $uploadId, 'total' => 2, 'filename' => 'v.mp4', 'size' => 100])
            ->assertStatus(422)
            ->assertJson(['message' => 'Part of the upload is missing. Please try again.']);

        $this->assertSame(0, Media::count());
    }

    public function test_files_that_are_not_videos_are_rejected(): void
    {
        $this->upload($this->client(), str_repeat('not a video ', 500), name: 'virus.mp4')
            ->assertStatus(422)
            ->assertJson(['message' => 'Please upload an MP4, MOV or WebM video.']);

        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_videos_over_the_limit_are_rejected(): void
    {
        config(['app.max_upload_mb' => 1]);

        $this->actingAs($this->client())->postJson('/uploads/complete', [
            'upload_id' => (string) Str::uuid(), 'total' => 1, 'filename' => 'big.mp4', 'size' => 2 * 1024 * 1024,
        ])->assertStatus(422)->assertJsonValidationErrors(['size' => 'The maximum is 1 MB']);
    }

    public function test_a_client_cannot_finish_someone_elses_upload(): void
    {
        $alice = $this->client();
        $bob = $this->client();
        $uploadId = (string) Str::uuid();

        $this->actingAs($alice)->post('/uploads/chunk', [
            'upload_id' => $uploadId, 'index' => 0, 'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $this->mp4Bytes()),
        ])->assertOk();

        $this->actingAs($bob)->postJson('/uploads/complete', [
            'upload_id' => $uploadId, 'total' => 1, 'filename' => 'v.mp4', 'size' => strlen($this->mp4Bytes()),
        ])->assertStatus(422);

        $this->assertSame(0, Media::count());
    }

    public function test_uploaded_video_is_used_in_the_post(): void
    {
        $user = $this->client();
        $mediaId = $this->upload($user, $this->mp4Bytes())->json('id');

        Volt::actingAs($user)->test('posts.compose')
            ->call('useMedia', $mediaId)
            ->set('accountIds', [(string) $user->socialAccounts()->first()->id])
            ->set('when', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($mediaId, $user->posts()->first()->media_id);
    }

    public function test_another_clients_video_cannot_be_attached(): void
    {
        $owner = $this->client();
        $mediaId = $this->upload($owner, $this->mp4Bytes())->json('id');
        $intruder = $this->client();

        Volt::actingAs($intruder)->test('posts.compose')->call('useMedia', $mediaId)->assertNotFound();

        Volt::actingAs($intruder)->test('posts.compose')
            ->set('mediaId', $mediaId)
            ->set('accountIds', [(string) $intruder->socialAccounts()->first()->id])
            ->set('when', 'draft')
            ->call('save')
            ->assertHasErrors('mediaId');
    }

    public function test_cleanup_removes_abandoned_pieces_and_unused_videos_only(): void
    {
        $user = $this->client();

        $old = "chunks/{$user->id}/".Str::uuid();
        $fresh = "chunks/{$user->id}/".Str::uuid();
        Storage::disk('local')->put("{$old}/00000.part", 'x');
        Storage::disk('local')->put("{$fresh}/00000.part", 'x');
        touch(Storage::disk('local')->path("{$old}/00000.part"), now()->subDays(2)->getTimestamp());

        $unused = Media::findOrFail($this->upload($user, $this->mp4Bytes())->json('id'));
        $used = Media::findOrFail($this->upload($user, $this->mp4Bytes(5000))->json('id'));
        $user->posts()->create(['media_id' => $used->id, 'caption' => 'x', 'status' => PostStatus::Draft]);
        Media::query()->update(['created_at' => now()->subDays(3)]);

        $this->artisan('media:cleanup')->assertSuccessful();

        Storage::disk('local')->assertMissing("{$old}/00000.part");
        Storage::disk('local')->assertExists("{$fresh}/00000.part");
        $this->assertModelMissing($unused);
        Storage::disk('public')->assertMissing($unused->path);
        $this->assertModelExists($used);
        Storage::disk('public')->assertExists($used->path);
    }
}
