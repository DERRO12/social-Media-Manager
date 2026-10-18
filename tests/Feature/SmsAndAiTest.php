<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\Writer;
use App\Services\Mail\CampaignSender;
use App\Services\Sms\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SmsAndAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function platformSms(int $monthly = 200): void
    {
        Setting::put('sms.username', 'gokatale');
        Setting::put('sms.password', 'secret', encrypt: true);
        Setting::put('sms.sender_id', 'GOKATALE');
        Setting::put('sms.monthly_limit', $monthly);
    }

    private function fakeEgoSms(string $status = 'OK', string $message = ''): void
    {
        Http::fake(['comms.egosms.co/*' => Http::response($status === 'OK' ? ['Status' => 'OK', 'Cost' => '35', 'MsgFollowUpUniqueCode' => 'x'] : ['Status' => 'Failed', 'Message' => $message])]);
    }

    // ---------- Phone numbers ----------

    public function test_ugandan_and_international_numbers_are_understood(): void
    {
        $this->assertSame('256772123456', Phone::normalise('0772 123 456'));
        $this->assertSame('256772123456', Phone::normalise('772123456'));
        $this->assertSame('256772123456', Phone::normalise('+256 (772) 123-456'));
        $this->assertSame('254712345678', Phone::normalise('+254 712 345678'));
        $this->assertSame('254712345678', Phone::normalise('00254712345678'));
        $this->assertNull(Phone::normalise('12345'));
        $this->assertNull(Phone::normalise('call me'));

        $this->assertSame(['characters' => 160, 'parts' => 1, 'unicode' => false], Phone::parts(str_repeat('a', 160)));
        $this->assertSame(2, Phone::parts(str_repeat('a', 161))['parts']);
        $this->assertSame(['characters' => 3, 'parts' => 1, 'unicode' => true], Phone::parts('Hi🎉'));
    }

    public function test_clients_import_emails_and_phone_numbers_and_only_see_their_own(): void
    {
        $client = User::factory()->create();
        $other = User::factory()->create();
        $other->contacts()->create(['email' => 'secret@rival.com', 'phone' => '0700999999', 'name' => 'Rival Customer']);

        $csv = "Full Name,Mobile,E-mail\nAma,0772 123456,ama@example.com\nKato,0701 222333,\nNo Contact,,\nDup,+256772123456,\n";
        Volt::actingAs($client)->test('email.contacts')
            ->set('csv', UploadedFile::fake()->createWithContent('phone.csv', $csv))
            ->set('importConsent', true)
            ->call('import')
            ->assertSet('importResult', 'Added 2 contacts, skipped 2 (no valid email or phone, duplicate, or over the limit).')
            ->assertSee('+256 772 123 456')
            ->assertDontSee('Rival Customer')
            ->set('phone', '0701 222 333')->set('consent', true)->call('add')->assertHasErrors('phone')
            ->set('phone', '0755 000111')->set('email', '')->call('add')->assertHasNoErrors();

        $this->assertSame(['256772123456', '256701222333', '256755000111'], $client->contacts()->orderBy('id')->pluck('phone')->all());
        $this->assertSame(3, $client->contacts()->smsSubscribed()->count());
        $this->assertSame(1, $client->contacts()->subscribed()->count()); // only Ama has an email

        Volt::actingAs($client)->test('email.contacts')->call('toggle', $other->contacts()->first()->id, 'sms')->assertNotFound();
    }

    // ---------- SMS campaigns ----------

    public function test_an_sms_campaign_goes_out_through_egosms_with_an_opt_out_link(): void
    {
        $this->platformSms();
        $this->fakeEgoSms();
        $client = User::factory()->create(['name' => 'Amara Studio']);
        $ama = $client->contacts()->create(['phone' => '0772123456', 'name' => 'Ama Owusu']);
        $client->contacts()->create(['phone' => '0701222333', 'sms_status' => Contact::UNSUBSCRIBED]);
        $client->contacts()->create(['email' => 'email-only@example.com']);

        Volt::actingAs($client)->test('email.campaigns')->set('kind', 'sms')->call('create', 'sms_offer');
        $campaign = EmailCampaign::sole();
        $this->assertTrue($campaign->isSms());

        Volt::actingAs($client)->test('email.campaign', ['campaign' => $campaign])
            ->assertSee('This goes to <strong>1</strong> person', false)
            ->call('sendNow')
            ->assertSet('noticeOk', true);

        $campaign->refresh();
        $this->assertSame(['sent', 1, 1], [$campaign->status, $campaign->recipients, $campaign->sent]);
        $this->assertEquals(35, $campaign->sends()->sum('cost'));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($ama) {
            $msg = $r['msgdata'][0];

            return $r['method'] === 'SendSms'
                && $r['userdata'] === ['username' => 'gokatale', 'password' => 'secret']
                && $msg['number'] === '256772123456'
                && $msg['senderid'] === 'GOKATALE'
                && str_starts_with($msg['message'], 'Hi Ama! Amara Studio: 20% off')
                && str_ends_with($msg['message'], "\nOpt out: ".$ama->fresh()->smsOptOutUrl());
        });
    }

    public function test_the_monthly_allowance_on_the_shared_account_is_enforced_but_not_on_the_clients_own(): void
    {
        $this->platformSms(monthly: 1);
        $this->fakeEgoSms();
        $client = User::factory()->create();
        $client->contacts()->create(['phone' => '0772123456']);
        $client->contacts()->create(['phone' => '0701222333']);
        $campaign = $client->emailCampaigns()->create(['channel' => 'sms', 'body' => 'Hello']);

        try {
            app(CampaignSender::class)->start($campaign);
            $this->fail('Should refuse: 2 recipients, 1 SMS left');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('you have 1 SMS left this month', $e->getMessage());
        }

        $client->smsAccount()->create(['username' => 'amara', 'password' => 'pw', 'sender_id' => 'AMARA']);
        app(CampaignSender::class)->start($campaign->fresh());

        $this->assertSame(2, $campaign->fresh()->sent);
        Http::assertSent(fn (HttpRequest $r) => $r['userdata']['username'] === 'amara' && $r['msgdata'][0]['senderid'] === 'AMARA');
    }

    public function test_sending_pauses_when_egosms_credit_runs_out(): void
    {
        $this->platformSms();
        $this->fakeEgoSms('Failed', 'Insufficient balance');
        $client = User::factory()->create();
        $client->contacts()->create(['phone' => '0772123456']);
        $client->contacts()->create(['phone' => '0701222333']);
        $campaign = $client->emailCampaigns()->create(['channel' => 'sms', 'body' => 'Hello']);

        app(CampaignSender::class)->start($campaign);

        $campaign->refresh();
        $this->assertSame('paused', $campaign->status);
        $this->assertStringContainsString('run out of credit', $campaign->error);
        Http::assertSentCount(1); // stopped after the first failure
    }

    public function test_people_opt_out_of_sms_from_the_link(): void
    {
        $client = User::factory()->create();
        $contact = $client->contacts()->create(['phone' => '0772123456', 'email' => 'ama@example.com']);

        $this->get('/s/'.$contact->id.'-wrongcod')->assertNotFound();
        $this->get(parse_url($contact->smsOptOutUrl(), PHP_URL_PATH))->assertOk()->assertSee('You won’t get more SMS', false);

        $contact->refresh();
        $this->assertSame(Contact::UNSUBSCRIBED, $contact->sms_status);
        $this->assertSame(Contact::SUBSCRIBED, $contact->status); // email is unaffected
    }

    public function test_the_signup_form_collects_phone_numbers_too(): void
    {
        $client = User::factory()->create();
        $client->publicProfile()->create(['handle' => 'amara', 'is_public' => true, 'newsletter_enabled' => true]);

        $this->post('/p/amara/subscribe', ['email' => 'esi@example.com', 'phone' => '0772 123456', 'consent' => '1'])->assertSessionHas('newsletter');

        $this->assertSame('256772123456', $client->contacts()->sole()->phone);
    }

    // ---------- AI assistant ----------

    private function enableAi(): void
    {
        Setting::put('ai.api_key', 'sk-ant-test', encrypt: true);
        Setting::put('ai.daily_limit', 2);
    }

    private function fakeClaude(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]])]);
    }

    public function test_ai_writes_captions_with_the_business_details_and_respects_the_daily_limit(): void
    {
        $this->enableAi();
        $this->fakeClaude('Here you go: {"captions": ["New braids!", "Story time", "Which style?"], "hashtags": ["#braids", "kampala hair"], "title": "Bridal braids"}');
        $client = User::factory()->create();
        $client->businessProfile()->create(['name' => 'Amara Studio', 'primary_category' => 'Hair salon', 'city' => 'Kampala']);

        Volt::actingAs($client)->test('posts.compose')
            ->set('aiTopic', 'new bridal braids')
            ->call('writeWithAi')
            ->assertSet('aiResult.captions', ['New braids!', 'Story time', 'Which style?'])
            ->call('useCaption', 2)
            ->assertSet('caption', "Which style?\n\n#braids #kampalahair")
            ->assertSet('title', 'Bridal braids');

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-api-key', 'sk-ant-test')
            && $r['model'] === 'claude-sonnet-5-5'
            && str_contains($r['system'], 'Amara Studio') && str_contains($r['system'], 'Hair salon'));

        $writer = app(Writer::class);
        $writer->reply($client, 'Love it!', 'Ama', null);
        $this->assertSame(0, $writer->remaining($client));
        $this->expectExceptionMessage('You’ve used today’s 2 AI requests');
        $writer->reply($client, 'Again', 'Ama', null);
    }

    public function test_ai_suggests_inbox_replies_and_writes_newsletters_and_sms(): void
    {
        $this->enableAi();
        Setting::put('ai.daily_limit', 10);
        $client = User::factory()->create();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['subject' => 'December news', 'preheader' => 'Offers inside', 'body' => "Hi {first_name},\n\nGreat news!"])]]])
            ->push(['content' => [['type' => 'text', 'text' => '"Weekend deal at Amara: 2 for 1 braids. Book now!"']]]),
        ]);
        $campaign = $client->emailCampaigns()->create(['subject' => 'x', 'body' => 'y']);
        Volt::actingAs($client)->test('email.campaign', ['campaign' => $campaign])
            ->set('aiTopic', 'december offers')->call('writeWithAi')
            ->assertSet('subject', 'December news')->assertSet('body', "Hi {first_name},\n\nGreat news!");

        $sms = $client->emailCampaigns()->create(['channel' => 'sms', 'body' => '']);
        Volt::actingAs($client)->test('email.campaign', ['campaign' => $sms])
            ->set('aiTopic', 'weekend 2 for 1')->call('writeWithAi')
            ->assertSet('body', 'Weekend deal at Amara: 2 for 1 braids. Book now!');
    }

    public function test_without_a_key_ai_is_hidden_and_explains_itself(): void
    {
        $client = User::factory()->create();

        Volt::actingAs($client)->test('posts.compose')->assertDontSee('Write with AI');

        $this->expectExceptionMessage('The AI assistant isn’t switched on yet');
        app(Writer::class)->reply($client, 'Hi', null, null);
    }

    // ---------- Calendar ----------

    public function test_the_calendar_shows_posts_and_marketing_days_and_plans_new_posts(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $client = User::factory()->create(['timezone' => 'Africa/Kampala']);
        $client->posts()->create(['title' => 'Independence promo', 'caption' => 'x', 'status' => 'scheduled', 'scheduled_at' => '2026-10-09 07:00:00']);
        $client->posts()->create(['title' => 'Old post', 'caption' => 'x', 'status' => 'published', 'published_at' => '2026-09-20 10:00:00']);

        Volt::actingAs($client)->test('calendar.index')
            ->assertSee('October 2026')
            ->assertSee('Independence promo')
            ->assertSee('Independence Day (Uganda)')
            ->assertSee('International Coffee Day')
            ->assertDontSee('Old post')
            ->call('go', 1)
            ->assertSee('November 2026')
            ->assertSee('Black Friday');

        $this->actingAs($client)->get('/compose?date=2026-10-20')->assertOk();
        \Livewire\Livewire::withQueryParams(['date' => '2026-10-20'])->actingAs($client)->test('posts.compose')
            ->assertSet('when', 'schedule')->assertSet('scheduledAt', '2026-10-20T10:00');
    }
}
