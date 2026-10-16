<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithYouTube(): array
    {
        $user = User::factory()->create();
        $account = $user->socialAccounts()->create([
            'platform' => Platform::YouTube,
            'platform_user_id' => 'UC1',
            'name' => 'Channel',
            'access_token' => 't',
        ]);

        return [$user, $account];
    }

    public function test_the_page_shows_the_real_upload_limit(): void
    {
        config(['app.max_upload_mb' => 100]);
        [$user] = $this->clientWithYouTube();

        $this->actingAs($user)->get('/compose')
            ->assertOk()
            ->assertSee('up to 100 MB')
            ->assertSee('maxMb: 100', false)
            ->assertDontSee('1 GB');
    }

    public function test_videos_over_the_limit_are_rejected_with_a_clear_message(): void
    {
        Storage::fake('public');
        config(['app.max_upload_mb' => 1]);
        [$user, $account] = $this->clientWithYouTube();

        Volt::actingAs($user)->test('posts.compose')
            ->set('accountIds', [(string) $account->id])
            ->set('video', UploadedFile::fake()->create('big.mp4', 2048, 'video/mp4'))
            ->set('when', 'draft')
            ->call('save')
            ->assertHasErrors(['video' => 'max'])
            ->assertSee('The maximum is 1 MB');

        $this->assertSame(0, $user->posts()->count());
    }

    public function test_videos_within_the_limit_are_accepted(): void
    {
        Storage::fake('public');
        config(['app.max_upload_mb' => 5]);
        [$user, $account] = $this->clientWithYouTube();

        Volt::actingAs($user)->test('posts.compose')
            ->set('accountIds', [(string) $account->id])
            ->set('video', UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'))
            ->set('when', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $user->posts()->count());
        $this->assertNotNull($user->posts()->first()->media_id);
    }
}
