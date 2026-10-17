<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\PaymentClaim;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\PaymentClaimReviewed;
use App\Notifications\PaymentClaimSubmitted;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PaymentLinksTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->plan = Plan::create([
            'name' => 'Pro', 'price' => 39, 'currency' => 'USD', 'interval' => 'month',
            'paypal_url' => 'https://www.paypal.com/ncp/payment/ABC123',
            'yo_url' => 'https://payments.yo.co.ug/pay/xyz',
        ]);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    public function test_admin_sets_payment_links_on_a_plan(): void
    {
        Volt::actingAs($this->admin)->test('admin.plans')
            ->call('edit', $this->plan->id)
            ->set('paypalUrl', 'https://paypal.me/gokatale/39')
            ->set('yoUrl', 'not a link')
            ->call('save')
            ->assertHasErrors('yoUrl')
            ->set('yoUrl', 'https://payments.yo.co.ug/new')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['paypal' => 'https://paypal.me/gokatale/39', 'yo' => 'https://payments.yo.co.ug/new'], $this->plan->fresh()->paymentLinks());
    }

    public function test_clients_see_pay_buttons_and_can_report_a_payment(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/billing')
            ->assertSee('Pay with PayPal')
            ->assertSee('https://www.paypal.com/ncp/payment/ABC123', false)
            ->assertSee('Pay with Mobile Money');

        Volt::actingAs($client)->test('billing.index')
            ->call('startClaim', $this->plan->id, 'yo')
            ->set('claimReference', 'MTN-998877')
            ->set('claimNote', 'Paid from 0772000000')
            ->call('submitClaim')
            ->assertHasNoErrors();

        $claim = PaymentClaim::sole();
        $this->assertSame('pending', $claim->status);
        $this->assertSame('yo', $claim->method);
        $this->assertEquals(39, $claim->amount);
        Notification::assertSentTo($this->admin, PaymentClaimSubmitted::class);
        Notification::assertNotSentTo($client, PaymentClaimSubmitted::class);
    }

    public function test_a_reference_is_required(): void
    {
        Volt::actingAs(User::factory()->create())->test('billing.index')
            ->call('startClaim', $this->plan->id, 'paypal')
            ->call('submitClaim')
            ->assertHasErrors('claimReference');
    }

    public function test_approving_activates_the_plan_for_one_period_and_emails_the_client(): void
    {
        $client = User::factory()->create();
        app(SubscriptionManager::class)->expire($client);
        $claim = $client->paymentClaims()->create(['plan_id' => $this->plan->id, 'method' => 'paypal', 'reference' => 'PP-1']);

        Volt::actingAs($this->admin)->test('admin.payments')->call('approve', $claim->id);

        $sub = $client->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame($this->plan->id, $sub->plan_id);
        $this->assertTrue($sub->ends_at->isSameDay(now()->addMonth()));
        $this->assertStringContainsString('ref PP-1', $sub->notes);
        $this->assertSame('approved', $claim->fresh()->status);
        Notification::assertSentTo($client, PaymentClaimReviewed::class);
    }

    public function test_paying_early_adds_the_new_period_on_top(): void
    {
        $client = User::factory()->create();
        app(SubscriptionManager::class)->activateManually($client, $this->plan, now()->addDays(10));
        $claim = $client->paymentClaims()->create(['plan_id' => $this->plan->id, 'method' => 'yo', 'reference' => 'Y-2']);

        Volt::actingAs($this->admin)->test('admin.payments')->call('approve', $claim->id);

        $this->assertTrue($client->fresh()->subscription->ends_at->isSameDay(now()->addDays(10)->addMonth()));
    }

    public function test_rejecting_tells_the_client_why(): void
    {
        $client = User::factory()->create();
        $claim = $client->paymentClaims()->create(['plan_id' => $this->plan->id, 'method' => 'paypal', 'reference' => 'FAKE']);

        Volt::actingAs($this->admin)->test('admin.payments')
            ->call('startReject', $claim->id)
            ->set('rejectReason', 'No payment with this reference.')
            ->call('reject');

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertSame('No payment with this reference.', $claim->fresh()->admin_note);
        $this->actingAs($client)->get('/billing')->assertSee('No payment with this reference.');
    }

    public function test_clients_cannot_review_payments(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/payments')->assertForbidden();
    }
}
