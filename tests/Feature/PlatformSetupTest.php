<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Social\Contracts\PlatformDriver;
use App\Services\Social\SocialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PlatformSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_uses_scopes_by_default_and_config_id_when_set(): void
    {
        Setting::put('meta.client_id', 'app');
        Setting::put('meta.client_secret', 'secret', encrypt: true);

        $url = app(SocialManager::class)->driver(Platform::Facebook)->authorizationUrl('s', 'https://x.test/cb');
        $this->assertStringContainsString('scope=pages_show_list', $url);
        $this->assertStringNotContainsString('config_id', $url);

        Setting::put('meta.config_id', '123456789');

        $url = app(SocialManager::class)->driver(Platform::Instagram)->authorizationUrl('s', 'https://x.test/cb');
        $this->assertStringContainsString('config_id=123456789', $url);
        $this->assertStringNotContainsString('scope=', $url);
    }

    public function test_x_free_plan_only_posts_so_sync_never_flags_the_account(): void
    {
        Http::fake();
        $driver = app(SocialManager::class)->driver(Platform::X);

        $this->assertTrue($driver->supports(PlatformDriver::PUBLISH));
        $this->assertFalse($driver->supports(PlatformDriver::CONTENT));
        $this->assertFalse($driver->supports(PlatformDriver::COMMENTS));

        $account = User::factory()->create()->socialAccounts()->create([
            'platform' => Platform::X, 'platform_user_id' => '1', 'name' => 'Me', 'username' => 'me', 'access_token' => 't',
        ]);

        SyncSocialAccount::dispatchSync($account);

        Http::assertNothingSent();
        $this->assertNull($account->fresh()->last_error);
    }

    public function test_admin_can_turn_on_x_reading_for_paid_plans(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        Volt::actingAs($admin)->test('admin.settings')
            ->set('platform.x.client_id', 'x-id')
            ->set('xReadAccess', true)
            ->call('savePlatform', 'x');

        $this->assertTrue(app(SocialManager::class)->driver(Platform::X)->supports(PlatformDriver::COMMENTS));
    }

    public function test_settings_list_the_urls_each_platform_asks_for(): void
    {
        config(['app.url' => 'https://social.gokatale.tech']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('https://social.gokatale.tech/privacy#data-deletion')
            ->assertSee('https://social.gokatale.tech/terms')
            ->assertSee('https://social.gokatale.tech/accounts/callback/facebook')
            ->assertSee('https://social.gokatale.tech/accounts/callback/instagram')
            ->assertSee('https://social.gokatale.tech/accounts/callback/linkedin')
            ->assertSee('https://social.gokatale.tech/accounts/callback/x')
            ->assertSee('gokatale.tech');
    }

    public function test_privacy_page_has_the_data_deletion_section_anchor(): void
    {
        $this->get('/privacy')->assertSee('id="data-deletion"', false);
    }
}
