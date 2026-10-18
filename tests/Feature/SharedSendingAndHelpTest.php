<?php

namespace Tests\Feature;

use App\Mail\CampaignMail;
use App\Models\Mailbox;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\CampaignSender;
use App\Support\HelpVideos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SharedSendingAndHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function siteMailbox(int $perClient = 100, int $total = 450): void
    {
        Setting::put('mail.host', 'smtp.gmail.com');
        Setting::put('mail.port', 587);
        Setting::put('mail.username', 'hello@gokatale.com');
        Setting::put('mail.password', 'abcdefghijklmnop', encrypt: true);
        Setting::put('mail.shared_per_client', $perClient);
        Setting::put('mail.shared_daily_total', $total);
    }

    private function client(string $name = 'Amara Studio'): User
    {
        $client = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com']);
        $client->contacts()->create(['email' => 'ama@example.com', 'name' => 'Ama']);
        $client->contacts()->create(['email' => 'kofi@example.com', 'name' => 'Kofi']);

        return $client;
    }

    public function test_clients_without_a_mailbox_send_through_the_admins_under_their_own_name(): void
    {
        Mail::fake();
        $this->siteMailbox();
        $client = $this->client();

        Volt::actingAs($client)->test('email.settings')->assertSee('Using '.config('app.name').'’s mailbox', false);

        $campaign = $client->emailCampaigns()->create(['subject' => 'Hello {first_name}', 'body' => 'Hi']);
        app(CampaignSender::class)->start($campaign);

        $this->assertSame(2, $campaign->fresh()->sent);
        $this->assertSame(2, $campaign->sends()->where('shared', true)->count());
        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            $mail->assertFrom('hello@gokatale.com', 'Amara Studio');
            $mail->assertHasReplyTo('amarastudio@example.com');

            return true;
        });
    }

    public function test_the_clients_own_mailbox_wins_over_the_shared_one(): void
    {
        Mail::fake();
        $this->siteMailbox();
        $client = $this->client();
        $client->mailbox()->create(['provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'amara@gmail.com', 'password' => 'pw']);

        $box = Mailbox::forUser($client->fresh());
        $this->assertFalse($box->shared);

        $campaign = $client->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hi']);
        app(CampaignSender::class)->start($campaign);

        $this->assertSame(0, $campaign->sends()->where('shared', true)->count());
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasFrom('amara@gmail.com'));
    }

    public function test_shared_sending_respects_the_per_client_and_total_daily_limits(): void
    {
        Mail::fake();
        $this->siteMailbox(perClient: 1, total: 2);

        $first = $this->client('First Shop');
        $c1 = $first->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hi']);
        app(CampaignSender::class)->start($c1);
        $this->assertSame(1, $c1->fresh()->sent); // 2 contacts, 1 allowed per client
        $this->assertStringContainsString('shared mailbox is reached', $c1->fresh()->error);

        $second = $this->client('Second Shop');
        $c2 = $second->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hi']);
        app(CampaignSender::class)->start($c2);
        $this->assertSame(1, $c2->fresh()->sent); // total of 2 now used

        $third = $this->client('Third Shop');
        $c3 = $third->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hi']);
        app(CampaignSender::class)->start($c3);
        $this->assertSame(0, $c3->fresh()->sent);
    }

    public function test_without_a_site_mailbox_or_with_a_zero_allowance_clients_must_connect_their_own(): void
    {
        $client = $this->client();
        $this->assertNull(Mailbox::forUser($client));

        $this->siteMailbox(perClient: 0);
        $this->assertNull(Mailbox::forUser($client));
    }

    public function test_admin_sets_the_shared_limits(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        Volt::actingAs($admin)->test('admin.settings')
            ->set('sharedPerClient', 50)->set('sharedTotal', 400)->call('saveSharedLimits')->assertHasNoErrors();

        $this->assertSame(50, Mailbox::sharedPerClient());
        $this->assertSame(400, Mailbox::sharedDailyTotal());
    }

    // ---------- Help videos ----------

    public function test_admin_adds_help_videos_that_clients_see_on_the_right_pages(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $client = User::factory()->create();

        Volt::actingAs($admin)->test('admin.help')
            ->set('links.app_password', 'not a video')
            ->call('save')
            ->assertHasErrors('links.app_password')
            ->set('links.app_password', 'https://youtu.be/dQw4w9WgXcQ')
            ->set('links.sms_campaign', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['app_password' => 'dQw4w9WgXcQ', 'sms_campaign' => 'aqz-KE-bpKQ'], HelpVideos::all());

        Volt::actingAs($client)->test('email.settings')->assertSee('Watch how: Create a Gmail app password');
        Volt::actingAs($client)->test('email.campaigns')->assertSee('Watch how: Send an SMS campaign')->assertDontSee('Watch how: Send an email newsletter');
        Volt::actingAs($client)->test('help.index')->assertSee('Create a Gmail app password')->assertSee('aqz-KE-bpKQ')->assertDontSee('Read your insights');

        $this->actingAs($client)->get(route('admin.help'))->assertForbidden();
    }

    public function test_help_page_explains_when_no_videos_are_added(): void
    {
        Volt::actingAs(User::factory()->create())->test('help.index')->assertSee('Tutorial videos are coming soon');
    }

    public function test_new_courses_install_with_the_app_name(): void
    {
        $library = app(\App\Services\Academy\CourseLibrary::class);
        $course = $library->install('complete-guide-to-the-platform');

        $this->assertSame('The Complete Guide to '.config('app.name'), $course->title);
        $this->assertSame(11, $course->lessons()->count());
        $this->assertStringNotContainsString('APPNAME', $course->lessons()->pluck('content')->implode(' '));

        $this->assertSame(10, $library->install('email-and-sms-marketing')->lessons()->count());
        $this->assertSame(7, $library->install('whatsapp-business-marketing')->lessons()->count());
    }
}
