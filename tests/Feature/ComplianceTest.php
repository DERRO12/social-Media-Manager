<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** What Google's OAuth verification and the YouTube API audit check. */
class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function youtube(User $user, string $channel = 'UC1', string $refresh = 'refresh-1'): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => Platform::YouTube,
            'platform_user_id' => $channel,
            'name' => "Channel {$channel}",
            'access_token' => "access-{$channel}",
            'refresh_token' => $refresh,
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_privacy_policy_is_public_and_has_the_youtube_required_statements(): void
    {
        Setting::put('legal.owner', 'Gokatale Ltd');
        Setting::put('legal.email', 'privacy@gokatale.tech');
        Setting::put('legal.country', 'Uganda');

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Gokatale Ltd')
            ->assertSee('privacy@gokatale.tech')
            ->assertSee('YouTube API Services')
            ->assertSee('https://www.youtube.com/t/terms', false)
            ->assertSee('https://policies.google.com/privacy', false)
            ->assertSee('https://security.google.com/settings/security/permissions', false)
            ->assertSee('Limited Use');
    }

    public function test_terms_are_public(): void
    {
        Setting::put('legal.country', 'Uganda');

        $this->get('/terms')->assertOk()->assertSee('Terms of Service')->assertSee('laws of Uganda');
    }

    public function test_landing_and_sign_up_link_to_the_legal_pages(): void
    {
        $this->get('/')->assertSee(route('privacy'))->assertSee(route('terms'));
        $this->get('/register')->assertSee(route('privacy'))->assertSee(route('terms'));
    }

    public function test_contact_email_is_escaped(): void
    {
        Setting::put('legal.email', '"><script>alert(1)</script>@x.com');

        $this->get('/privacy')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_disconnecting_youtube_revokes_access_at_google(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);
        $user = User::factory()->create();
        $account = $this->youtube($user);

        $this->actingAs($user);
        Volt::test('accounts.index')->call('disconnect', $account->id);

        $this->assertModelMissing($account);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-1');
    }

    public function test_a_token_shared_by_another_channel_is_not_revoked(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $first = $this->youtube($user, 'UC1', 'same-grant');
        $this->youtube($user, 'UC2', 'same-grant');

        $first->disconnect();

        $this->assertModelMissing($first);
        $this->assertSame(1, $user->socialAccounts()->count());
        Http::assertNothingSent();
    }

    public function test_disconnect_still_works_if_google_is_unreachable(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);
        $account = $this->youtube(User::factory()->create());

        $account->disconnect();

        $this->assertModelMissing($account);
    }

    public function test_deleting_an_account_revokes_access_and_deletes_uploaded_videos(): void
    {
        Storage::fake('public');
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);

        $user = User::factory()->create();
        $this->youtube($user);
        Storage::disk('public')->put("media/{$user->id}/clip.mp4", 'video');
        $user->media()->create(['path' => "media/{$user->id}/clip.mp4", 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 5]);

        $user->delete();

        Storage::disk('public')->assertMissing("media/{$user->id}/clip.mp4");
        $this->assertSame(0, SocialAccount::count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    }
}
