<?php

namespace Tests\Feature;

use App\Mail\CampaignMail;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NewClientSignedUp;
use App\Notifications\PlanExpired;
use App\Notifications\Welcome;
use App\Services\Billing\SubscriptionManager;
use App\Services\Mail\SiteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Livewire\Volt\Volt;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function clientWithMailbox(): User
    {
        $client = User::factory()->create(['name' => 'Amara Studio']);
        $client->mailbox()->create(['provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'amara@gmail.com', 'password' => 'abcdefghijklmnop']);
        $client->publicProfile()->create(['handle' => 'amara', 'is_public' => true, 'newsletter_enabled' => true]);

        return $client;
    }

    // ---------- Site email (Admin → Settings) ----------

    public function test_admin_connects_gmail_with_an_app_password(): void
    {
        Mail::fake();

        Volt::actingAs($this->admin())->test('admin.settings')
            ->set('mailProvider', 'gmail')
            ->set('mailUsername', 'Hello@Gokatale.com')
            ->set('mailPassword', 'abcd efgh ijkl mnop')
            ->set('mailFromName', 'Gokatale Social')
            ->call('saveMail')
            ->assertHasNoErrors()
            ->assertSet('mailPassword', '')
            ->call('testMail')
            ->assertSet('mailOk', true);

        $this->assertSame('smtp.gmail.com', Setting::get('mail.host'));
        $this->assertSame('hello@gokatale.com', Setting::get('mail.username'));
        $this->assertSame('abcdefghijklmnop', Setting::get('mail.password'));
        $this->assertNotSame('abcdefghijklmnop', Setting::where('key', 'mail.password')->value('value')); // stored encrypted

        SiteMail::apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('Gokatale Social', config('mail.from.name'));
    }

    public function test_email_confirmation_can_only_be_required_once_email_works_and_never_locks_out_existing_users(): void
    {
        $admin = $this->admin();
        $old = User::factory()->unverified()->create();

        Volt::actingAs($admin)->test('admin.settings')->set('requireVerification', true)->call('saveVerification')->assertHasErrors('requireVerification');
        $this->assertTrue($old->fresh()->hasVerifiedEmail()); // not enforced

        Setting::put('mail.host', 'smtp.gmail.com');
        Setting::put('mail.username', 'a@gmail.com');
        Setting::put('mail.password', 'x', encrypt: true);
        Volt::actingAs($admin)->test('admin.settings')->set('requireVerification', true)->call('saveVerification')->assertHasNoErrors();

        $this->assertNotNull($old->fresh()->email_verified_at);
        $this->assertFalse(User::factory()->unverified()->create()->hasVerifiedEmail());
    }

    // ---------- Account emails ----------

    public function test_sign_up_sends_a_welcome_email_and_alerts_the_admin(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Volt::test('auth.register')
            ->set('name', 'Kofi')->set('email', 'kofi@example.com')
            ->set('password', 'password123!')->set('password_confirmation', 'password123!')
            ->call('register');

        $kofi = User::firstWhere('email', 'kofi@example.com');
        Notification::assertSentTo($kofi, Welcome::class);
        Notification::assertSentTo($admin, NewClientSignedUp::class, fn ($n) => $n->client->is($kofi));
    }

    public function test_clients_are_told_when_their_trial_ends(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $client->subscription->update(['trial_ends_at' => now()->subHour()]);

        app(SubscriptionManager::class)->sweepExpired();

        Notification::assertSentTo($client, PlanExpired::class, fn ($n) => $n->wasTrial);
    }

    // ---------- Contacts ----------

    public function test_contacts_can_be_added_imported_and_tagged(): void
    {
        $client = $this->clientWithMailbox();
        $page = Volt::actingAs($client)->test('email.contacts');

        $page->set('email', 'ama@example.com')->set('name', 'Ama')->set('tags', 'VIP, customers')->call('add')->assertHasErrors('consent')
            ->set('consent', true)->call('add')->assertHasNoErrors()
            ->set('email', 'AMA@example.com')->set('consent', true)->call('add')->assertHasErrors('email');

        $csv = "Name,Email Address\nKwame,kwame@example.com\nBad,not-an-email\nAma again,ama@example.com\n,esi@example.com\n";
        $page->set('csv', UploadedFile::fake()->createWithContent('list.csv', $csv))
            ->set('importTags', 'Market Day')
            ->set('importConsent', true)
            ->call('import')
            ->assertSet('importResult', 'Added 2 contacts, skipped 2 (invalid, duplicate or over the limit).');

        $this->assertSame('vip, customers', $client->contacts()->firstWhere('email', 'ama@example.com')->tags);
        $this->assertSame(['kwame@example.com', 'esi@example.com'], $client->contacts()->tagged('market day')->pluck('email')->all());
        $this->assertSame(1, $client->contacts()->tagged('vip')->count());
        $this->assertSame(0, $client->contacts()->tagged('vi')->count());

        Volt::actingAs(User::factory()->create())->test('email.contacts')->call('delete', $client->contacts()->first()->id)->assertNotFound();
    }

    public function test_visitors_join_the_mailing_list_from_the_public_page(): void
    {
        $client = $this->clientWithMailbox();

        $this->get('/p/amara')->assertSee('Join Amara Studio’s mailing list', false);

        $this->post('/p/amara/subscribe', ['email' => 'Esi@Example.com', 'name' => 'Esi'])->assertSessionHasErrors('consent', null, 'newsletter');
        $this->post('/p/amara/subscribe', ['email' => 'bot@spam.com', 'consent' => '1', 'company' => 'Spam Inc'])->assertSessionHas('newsletter');
        $this->post('/p/amara/subscribe', ['email' => 'Esi@Example.com', 'name' => 'Esi', 'consent' => '1'])->assertSessionHas('newsletter');

        $this->assertSame(['esi@example.com'], $client->contacts()->pluck('email')->all());
        $this->assertSame('form', $client->contacts()->first()->source);

        $client->publicProfile->update(['newsletter_enabled' => false]);
        $this->post('/p/amara/subscribe', ['email' => 'x@example.com', 'consent' => '1'])->assertNotFound();
    }

    // ---------- Campaigns ----------

    public function test_a_campaign_goes_to_subscribed_contacts_in_the_chosen_tag_from_the_clients_mailbox(): void
    {
        Mail::fake();
        $client = $this->clientWithMailbox();
        $client->contacts()->create(['email' => 'ama@example.com', 'name' => 'Ama Owusu', 'tags' => 'vip']);
        $client->contacts()->create(['email' => 'kofi@example.com', 'tags' => 'vip'])->unsubscribe();
        $client->contacts()->create(['email' => 'esi@example.com']); // no tag

        Volt::actingAs($client)->test('email.campaigns')->call('create', 'offer');
        $campaign = EmailCampaign::sole();
        $this->assertSame('{first_name}, 20% off this week only', $campaign->subject);

        Volt::actingAs($client)->test('email.campaign', ['campaign' => $campaign])
            ->set('tag', 'vip')
            ->assertSee('This goes to <strong>1</strong> person', false)
            ->call('sendNow')
            ->assertSet('noticeOk', true);

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame([1, 1], [$campaign->recipients, $campaign->sent]);

        Mail::assertSent(CampaignMail::class, 1);
        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
            $mail->assertHasSubject('Ama, 20% off this week only');
            $mail->assertFrom('amara@gmail.com', 'Amara Studio');
            $mail->assertSeeInHtml('Hi Ama,', false);
            $mail->assertSeeInHtml('/unsubscribe/', false);
            $mail->assertSeeInHtml('/e/o/', false);

            return $mail->hasTo('ama@example.com')
                && str_contains($mail->headers()->text['List-Unsubscribe'], '/unsubscribe/')
                && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });
    }

    public function test_sending_stops_at_the_mailboxs_daily_limit(): void
    {
        Mail::fake();
        $client = $this->clientWithMailbox();
        $client->mailbox->update(['daily_limit' => 2]);
        foreach (['a', 'b', 'c'] as $n) {
            $client->contacts()->create(['email' => "{$n}@example.com"]);
        }
        $campaign = $client->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hello']);

        app(\App\Services\Mail\CampaignSender::class)->start($campaign);

        $campaign->refresh();
        Mail::assertSent(CampaignMail::class, 2);
        $this->assertSame('sending', $campaign->status);
        $this->assertSame(1, $campaign->sends()->where('status', 'pending')->count());
        $this->assertStringContainsString('Daily sending limit', $campaign->error);
    }

    public function test_campaigns_need_a_mailbox_and_contacts_and_are_private(): void
    {
        $client = User::factory()->create();
        $campaign = $client->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'Hello']);

        Volt::actingAs($client)->test('email.campaign', ['campaign' => $campaign])->call('sendNow')->assertSet('notice', 'Connect your mailbox first (Email marketing → Settings).');

        $this->actingAs(User::factory()->create())->get(route('email.campaign', $campaign))->assertNotFound();
    }

    public function test_scheduled_campaigns_are_sent_when_due(): void
    {
        Mail::fake();
        $client = $this->clientWithMailbox();
        $client->contacts()->create(['email' => 'ama@example.com']);
        $later = $client->emailCampaigns()->create(['subject' => 'Later', 'body' => 'x', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
        $due = $client->emailCampaigns()->create(['subject' => 'Now', 'body' => 'x', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);

        $this->artisan('email:send-scheduled')->assertSuccessful();

        $this->assertSame('sent', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
    }

    // ---------- Unsubscribe and opens ----------

    public function test_people_can_unsubscribe_in_one_click_and_change_their_mind(): void
    {
        $client = $this->clientWithMailbox();
        $contact = $client->contacts()->create(['email' => 'ama@example.com']);

        $this->get("/unsubscribe/{$contact->token}")->assertOk()->assertSee('Unsubscribe from Amara Studio?');
        $this->assertSame(Contact::SUBSCRIBED, $contact->fresh()->status); // just opening the link changes nothing

        $this->post("/unsubscribe/{$contact->token}")->assertOk()->assertSee('You’re unsubscribed', false); // no CSRF token, like mail apps
        $this->assertSame(Contact::UNSUBSCRIBED, $contact->fresh()->status);

        $this->post("/unsubscribe/{$contact->token}/resubscribe")->assertOk();
        $this->assertSame(Contact::SUBSCRIBED, $contact->fresh()->status);

        $this->get('/unsubscribe/not-a-token')->assertNotFound();
    }

    public function test_opens_are_counted_once_from_a_signed_link(): void
    {
        $client = $this->clientWithMailbox();
        $contact = $client->contacts()->create(['email' => 'ama@example.com']);
        $campaign = $client->emailCampaigns()->create(['subject' => 'Hi', 'body' => 'x', 'status' => 'sent', 'sent' => 1]);
        $send = $campaign->sends()->create(['contact_id' => $contact->id, 'status' => 'sent', 'sent_at' => now()]);

        $this->get("/e/o/{$send->id}")->assertForbidden();
        $url = URL::signedRoute('email.open', ['send' => $send->id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($url)->assertOk();

        $this->assertSame(1, $campaign->fresh()->opens);
        $this->assertSame(100, $campaign->fresh()->openRate());
    }
}
