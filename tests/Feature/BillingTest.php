<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TargetStatus;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\StripeBilling;
use App\Services\Billing\SubscriptionManager;
use App\Services\Social\SocialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function expiredClient(): User
    {
        $client = User::factory()->create();
        app(SubscriptionManager::class)->expire($client);

        return $client->fresh();
    }

    public function test_new_clients_get_the_trial_length_the_admin_set(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 20]);
        Setting::put('billing.trial_days', 21);
        Setting::put('billing.trial_plan_id', $plan->id);

        $client = User::factory()->create();

        $this->assertTrue($client->subscription->onTrial());
        $this->assertSame($plan->id, $client->subscription->plan_id);
        $this->assertEqualsWithDelta(now()->addDays(21)->timestamp, $client->subscription->trial_ends_at->timestamp, 5);
    }

    public function test_zero_trial_days_means_new_clients_must_pick_a_plan(): void
    {
        Setting::put('billing.trial_days', 0);

        $client = User::factory()->create();

        $this->assertFalse($client->hasAccess());
        $this->actingAs($client)->get('/dashboard')->assertRedirect('/billing');
    }

    public function test_clients_without_an_active_plan_are_sent_to_billing_but_can_still_open_it(): void
    {
        $client = $this->expiredClient();

        $this->actingAs($client)->get('/dashboard')->assertRedirect('/billing');
        $this->get('/compose')->assertRedirect('/billing');
        $this->get('/billing')->assertOk()->assertSee('Choose one below');
    }

    public function test_an_expired_trial_blocks_access(): void
    {
        $client = User::factory()->create();
        $client->subscription->update(['trial_ends_at' => now()->subMinute()]);

        $this->actingAs($client->fresh())->get('/inbox')->assertRedirect('/billing');
    }

    public function test_admin_can_give_a_trial_of_any_length(): void
    {
        $client = $this->expiredClient();

        $this->actingAs($this->admin());

        Volt::test('admin.client', ['user' => $client])
            ->set('trialDays', 45)
            ->call('giveTrial')
            ->assertHasNoErrors();

        $sub = $client->fresh()->subscription;
        $this->assertTrue($sub->onTrial());
        $this->assertSame(45, $sub->trialDaysLeft());
    }

    public function test_admin_can_extend_a_running_trial(): void
    {
        $client = User::factory()->create();
        app(SubscriptionManager::class)->startTrial($client, 3);

        $this->actingAs($this->admin());
        Volt::test('admin.client', ['user' => $client])->set('extendDays', 10)->call('extendTrial');

        $this->assertSame(13, $client->fresh()->subscription->trialDaysLeft());
    }

    public function test_admin_can_activate_a_plan_manually_until_a_date(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 20]);
        $client = $this->expiredClient();

        $this->actingAs($this->admin());
        Volt::test('admin.client', ['user' => $client])
            ->set('planId', $plan->id)
            ->set('paidUntil', now()->addMonths(3)->format('Y-m-d'))
            ->set('notes', 'Paid by bank transfer')
            ->call('activate')
            ->assertHasNoErrors();

        $sub = $client->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertTrue($sub->ends_at->isSameDay(now()->addMonths(3)));
        $this->assertSame('Paid by bank transfer', $sub->notes);
        $this->assertTrue($client->fresh()->hasAccess());
    }

    public function test_admin_can_end_access_immediately(): void
    {
        $client = User::factory()->create();

        $this->actingAs($this->admin());
        Volt::test('admin.client', ['user' => $client])->call('expireNow');

        $this->assertFalse($client->fresh()->hasAccess());
    }

    public function test_clients_cannot_use_the_admin_billing_controls(): void
    {
        $client = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($client)->get("/admin/clients/{$other->id}")->assertForbidden();
        $this->get('/admin/plans')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_account_limit_blocks_connecting_more_accounts(): void
    {
        Setting::put('youtube.client_id', 'id');
        Setting::put('youtube.client_secret', 'secret', encrypt: true);
        $plan = Plan::create(['name' => 'Starter', 'price' => 5, 'max_social_accounts' => 1]);

        $client = User::factory()->create();
        app(SubscriptionManager::class)->activateManually($client, $plan, null);
        $client->socialAccounts()->create(['platform' => Platform::YouTube, 'platform_user_id' => 'a', 'name' => 'A', 'access_token' => 't']);

        $this->assertSame(0, $client->fresh()->remainingAccountSlots());

        $this->actingAs($client)->get('/accounts/connect/youtube')
            ->assertRedirect('/accounts')
            ->assertSessionHas('error');

        // Reconnecting an existing account is still allowed.
        $this->get('/accounts/connect/youtube?reconnect=1')->assertRedirectContains('accounts.google.com');
    }

    public function test_oauth_always_uses_the_app_url_as_redirect_uri(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Setting::put('youtube.client_id', 'id');
        Setting::put('youtube.client_secret', 'secret', encrypt: true);

        $this->actingAs(User::factory()->create());

        // Opened on 127.0.0.1: first moved to the APP_URL address so the session survives the round trip.
        $this->get('http://127.0.0.1:8000/accounts/connect/youtube')
            ->assertRedirect('http://localhost:8000/accounts/connect/youtube');

        // On the APP_URL address, Google is given exactly the registered redirect URI.
        $location = $this->get('http://localhost:8000/accounts/connect/youtube')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('http://localhost:8000/accounts/callback/youtube', $query['redirect_uri']);
    }

    public function test_monthly_post_limit_is_enforced(): void
    {
        $plan = Plan::create(['name' => 'Starter', 'price' => 5, 'max_posts_per_month' => 1]);
        $client = User::factory()->create();
        app(SubscriptionManager::class)->activateManually($client, $plan, null);
        $account = $client->socialAccounts()->create(['platform' => Platform::Facebook, 'platform_user_id' => 'p', 'name' => 'Page', 'access_token' => 't']);
        $client->posts()->create(['caption' => 'first', 'status' => PostStatus::Published]);

        $this->actingAs($client);

        Volt::test('posts.compose')
            ->set('accountIds', [(string) $account->id])
            ->set('caption', 'second')
            ->set('when', 'schedule')
            ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasErrors('when');

        $this->assertSame(1, $client->posts()->count());

        // Drafts are still allowed.
        Volt::test('posts.compose')
            ->set('accountIds', [(string) $account->id])
            ->set('caption', 'draft')
            ->set('when', 'draft')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_scheduled_posts_of_expired_clients_are_not_published(): void
    {
        $client = User::factory()->create();
        $account = $client->socialAccounts()->create(['platform' => Platform::Facebook, 'platform_user_id' => 'p', 'name' => 'Page', 'access_token' => 't']);
        $post = $client->posts()->create(['caption' => 'x', 'status' => PostStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);
        $target = $post->targets()->create(['social_account_id' => $account->id]);
        app(SubscriptionManager::class)->expire($client);

        $this->artisan('posts:publish-due')->assertSuccessful();

        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertSame(TargetStatus::Failed, $target->fresh()->status);
    }

    public function test_billing_maintenance_expires_finished_trials(): void
    {
        $client = User::factory()->create();
        $client->subscription->update(['trial_ends_at' => now()->subHour()]);

        $this->artisan('billing:maintain')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $client->fresh()->subscription->status);
    }

    public function test_stripe_subscription_is_synced_onto_the_client(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 20, 'stripe_price_id' => 'price_123']);
        $client = User::factory()->create();
        $client->forceFill(['stripe_customer_id' => 'cus_123'])->save();
        $periodEnd = now()->addMonth()->getTimestamp();

        $stripeSub = StripeSubscription::constructFrom([
            'id' => 'sub_123',
            'object' => 'subscription',
            'customer' => 'cus_123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'ended_at' => null,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'object' => 'subscription_item',
                'current_period_end' => $periodEnd,
                'price' => ['object' => 'price', 'id' => 'price_123'],
            ]]],
        ]);

        app(StripeBilling::class)->sync($stripeSub);

        $sub = $client->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame('stripe', $sub->provider);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertSame($periodEnd, $sub->ends_at->getTimestamp());
    }

    public function test_webhook_rejects_unsigned_requests(): void
    {
        Setting::put('stripe.webhook_secret', 'whsec_test', encrypt: true);

        $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.updated'])->assertStatus(400);
    }

    public function test_admin_saved_platform_keys_are_encrypted_and_used(): void
    {
        $this->actingAs($this->admin());

        Volt::test('admin.settings')
            ->set('platform.tiktok.client_id', 'tt-key')
            ->set('platform.tiktok.client_secret', 'tt-secret')
            ->call('savePlatform', 'tiktok');

        $this->assertNotSame('tt-secret', Setting::firstWhere('key', 'tiktok.client_secret')->value);
        $this->assertSame('tt-secret', SocialManager::credentials('tiktok')['client_secret']);
        $this->assertTrue(app(SocialManager::class)->driver(Platform::TikTok)->configured());

        // Saving again with an empty secret keeps the stored one.
        Volt::test('admin.settings')->set('platform.tiktok.client_id', 'tt-key-2')->call('savePlatform', 'tiktok');
        $this->assertSame('tt-secret', SocialManager::credentials('tiktok')['client_secret']);
    }

    public function test_admin_can_create_plans(): void
    {
        $this->actingAs($this->admin());

        Volt::test('admin.plans')
            ->set('name', 'Business')
            ->set('price', '49.99')
            ->set('maxAccounts', '8')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::firstWhere('name', 'Business');
        $this->assertSame(8, $plan->max_social_accounts);
        $this->assertNull($plan->max_posts_per_month);
    }
}
