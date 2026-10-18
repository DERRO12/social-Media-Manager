<?php

use App\Jobs\SendCampaign;
use App\Mail\CampaignMail;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Services\Ai\Writer;
use App\Services\Mail\CampaignSender;
use App\Services\Mail\Smtp;
use App\Services\Sms\EgoSms;
use App\Services\Sms\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    /** Longest SMS we allow: 4 parts. */
    public const SMS_MAX = 600;

    public EmailCampaign $campaign;

    public string $subject = '';
    public string $preheader = '';
    public string $body = '';
    public string $tag = '';
    public string $scheduleAt = '';
    public string $testPhone = '';
    public ?string $notice = null;
    public bool $noticeOk = true;
    public string $aiTopic = '';

    public function mount(EmailCampaign $campaign): void
    {
        abort_unless($campaign->user_id === auth()->id(), 404);

        $this->campaign = $campaign;
        $this->subject = $campaign->subject;
        $this->preheader = (string) $campaign->preheader;
        $this->body = (string) $campaign->body;
        $this->tag = (string) $campaign->tag;
        $this->scheduleAt = $campaign->scheduled_at
            ? $campaign->scheduled_at->setTimezone(auth()->user()->timezone ?: 'UTC')->format('Y-m-d\TH:i')
            : '';
    }

    private function persist(): void
    {
        abort_unless($this->campaign->isEditable(), 403);
        $sms = $this->campaign->isSms();

        $this->validate([
            'subject' => [$sms ? 'nullable' : 'required', 'string', 'max:150'],
            'preheader' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:'.($sms ? self::SMS_MAX : 50000)],
            'tag' => ['nullable', 'string', 'max:50'],
        ], ['body.max' => 'SMS can be up to '.self::SMS_MAX.' characters (4 messages).']);

        $this->campaign->update([
            'subject' => $this->subject,
            'preheader' => $sms ? null : ($this->preheader ?: null),
            'body' => $this->body,
            'tag' => $this->tag ?: null,
        ]);
    }

    public function save(): void
    {
        $this->persist();
        $this->dispatch('campaign-saved');
    }

    public function writeWithAi(Writer $writer): void
    {
        abort_unless($this->campaign->isEditable(), 403);

        if (mb_strlen(trim($this->aiTopic)) < 3) {
            [$this->noticeOk, $this->notice] = [false, 'Say in a few words what the message is about.'];

            return;
        }

        try {
            if ($this->campaign->isSms()) {
                $this->body = $writer->sms(auth()->user(), mb_substr(trim($this->aiTopic), 0, 300));
            } else {
                $draft = $writer->newsletter(auth()->user(), mb_substr(trim($this->aiTopic), 0, 500));
                $this->subject = $draft['subject'] ?: $this->subject;
                $this->preheader = $draft['preheader'];
                $this->body = $draft['body'] ?: $this->body;
            }
            $this->notice = null;
        } catch (RuntimeException $e) {
            [$this->noticeOk, $this->notice] = [false, $e->getMessage()];
        }
    }

    public function sendTest(EgoSms $sms): void
    {
        $this->persist();
        $user = auth()->user();

        if ($this->campaign->isSms()) {
            $phone = Phone::normalise($this->testPhone);
            $account = EgoSms::accountFor($user);

            if (! $phone || ! $account || EgoSms::remainingFor($user) <= 0) {
                [$this->noticeOk, $this->notice] = [false, ! $phone ? 'Enter your phone number for the test.' : (! $account ? 'Set up SMS first (Settings & sign-up form).' : 'You have no SMS left this month.')];

                return;
            }

            $result = $sms->send($account, $phone, Smtp::personalise($this->body, $user->name, Smtp::links($user)));
            [$this->noticeOk, $this->notice] = [$result['ok'], $result['ok'] ? 'Test SMS sent to '.Phone::display($phone).'.' : $result['error']];

            return;
        }

        if (! $user->mailbox) {
            [$this->noticeOk, $this->notice] = [false, 'Connect your mailbox first (Settings & sign-up form).'];

            return;
        }

        try {
            $me = new Contact(['email' => $user->email, 'name' => $user->name]);
            Smtp::mailer('mailbox-'.$user->mailbox->id, $user->mailbox->mailerConfig())
                ->to($user->email, $user->name)
                ->send(new CampaignMail($this->campaign, $me, $user->mailbox));
            [$this->noticeOk, $this->notice] = [true, 'Test sent to '.$user->email.'.'];
        } catch (Throwable $e) {
            [$this->noticeOk, $this->notice] = [false, Smtp::explain($e)];
        }
    }

    public function sendNow(CampaignSender $sender): void
    {
        $this->persist();

        try {
            $sender->start($this->campaign);
            [$this->noticeOk, $this->notice] = [true, 'Sending started. You can leave this page; it continues in the background.'];
        } catch (RuntimeException $e) {
            [$this->noticeOk, $this->notice] = [false, $e->getMessage()];
        }
    }

    public function schedule(): void
    {
        $this->persist();
        $this->validate(['scheduleAt' => ['required', 'date']], ['scheduleAt.required' => 'Pick a date and time.']);

        $when = Carbon::parse($this->scheduleAt, auth()->user()->timezone ?: 'UTC')->utc();
        if ($when->isPast()) {
            $this->addError('scheduleAt', 'Pick a time in the future.');

            return;
        }

        $ready = $this->campaign->isSms() ? EgoSms::accountFor(auth()->user()) !== null : auth()->user()->mailbox !== null;
        if (! $ready) {
            $this->addError('scheduleAt', $this->campaign->isSms() ? 'Set up SMS first.' : 'Connect your mailbox first.');

            return;
        }

        $this->campaign->update(['status' => 'scheduled', 'scheduled_at' => $when]);
        [$this->noticeOk, $this->notice] = [true, 'Scheduled. It will be sent automatically.'];
    }

    public function unschedule(): void
    {
        if ($this->campaign->status === 'scheduled') {
            $this->campaign->update(['status' => 'draft', 'scheduled_at' => null]);
        }
    }

    /** Continue a paused campaign (e.g. after fixing the password or topping up SMS credit). */
    public function resume(): void
    {
        if (in_array($this->campaign->status, ['paused', 'failed'], true) && $this->campaign->sends()->where('status', 'pending')->exists()) {
            $this->campaign->update(['status' => 'sending', 'error' => null]);
            SendCampaign::dispatch($this->campaign->id);
        }
    }

    public function duplicate(): void
    {
        $copy = auth()->user()->emailCampaigns()->create($this->campaign->only(['channel', 'subject', 'preheader', 'body', 'tag']));

        $this->redirectRoute('email.campaign', $copy, navigate: true);
    }

    public function delete(): void
    {
        abort_if($this->campaign->status === 'sending', 403);
        $this->campaign->delete();
        $this->redirectRoute('email.campaigns', navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();
        $this->campaign->refresh();
        $isSms = $this->campaign->isSms();
        $links = Smtp::links($user);
        $smsText = Smtp::personalise($this->body, $user->name, $links)."\nOpt out: ".url('/s/123-abcd1234');

        return [
            'isSms' => $isSms,
            'editable' => $this->campaign->isEditable(),
            'audience' => $user->contacts()->reachableBy($this->campaign->channel)->tagged($this->tag ?: null)->count(),
            'tags' => $user->contacts()->whereNotNull('tags')->pluck('tags')->flatMap(fn ($t) => explode(', ', $t))->unique()->sort()->values(),
            'preview' => $isSms ? null : Str::markdown(Smtp::personalise($this->body, $user->name, $links), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'smsPreview' => $isSms ? Smtp::personalise($this->body, $user->name, $links) : null,
            'smsParts' => Phone::parts($smsText),
            'smsLeft' => $isSms ? EgoSms::remainingFor($user) : null,
            'smsSender' => $isSms ? (EgoSms::accountFor($user)['sender_id'] ?? 'SMS') : null,
            'smsCost' => $isSms ? (float) $this->campaign->sends()->sum('cost') : 0,
            'aiEnabled' => app(Writer::class)->enabled(),
            'missingLinks' => array_values(array_filter(['{review_link}', '{page_link}'], fn ($tag) => str_contains($this->body, $tag) && ! isset($links[$tag]))),
            'pending' => $this->campaign->sends()->where('status', 'pending')->count(),
            'failures' => $this->campaign->sends()->where('status', 'failed')->with('contact')->latest('updated_at')->limit(10)->get(),
            'tz' => $user->timezone ?: 'UTC',
        ];
    }
}; ?>

<div class="flex flex-col gap-6" @if ($campaign->status === 'sending') wire:poll.5s @endif>
    @include('partials.email-tabs')

    <div class="flex flex-wrap items-center gap-3">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('email.campaigns')" wire:navigate>Campaigns</flux:button>
        <flux:badge :icon="$isSms ? 'device-phone-mobile' : 'envelope'">{{ $isSms ? 'SMS' : 'Email' }}</flux:badge>
        <flux:badge :color="$campaign->statusColor()" class="capitalize">{{ $campaign->status }}</flux:badge>
        <flux:spacer />
        <flux:button size="sm" icon="document-duplicate" wire:click="duplicate">Duplicate</flux:button>
        @if ($campaign->status !== 'sending')
            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete" wire:confirm="Delete this campaign?">Delete</flux:button>
        @endif
    </div>

    @if ($notice)
        <flux:callout :variant="$noticeOk ? 'success' : 'danger'" :heading="$notice" />
    @endif

    @unless ($editable)
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @foreach ([
                ['Recipients', $campaign->recipients],
                ['Delivered', $campaign->sent],
                $isSms ? ['Cost (UGX)', $smsCost ? number_format($smsCost) : '—'] : ['Opened', $campaign->openRate() !== null ? $campaign->opens.' ('.$campaign->openRate().'%)' : '—'],
                ['Failed', $campaign->failed],
            ] as [$label, $value])
                <div class="rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                    <div class="text-xs text-zinc-500 sm:text-sm">{{ $label }}</div>
                    <div class="mt-1 text-xl font-semibold tabular-nums sm:text-2xl">{{ is_int($value) ? number_format($value) : $value }}</div>
                </div>
            @endforeach
        </div>
        @if ($campaign->status === 'sending')
            <flux:text class="flex items-center gap-2 text-sm"><flux:icon.loading variant="micro" /> Sending… {{ number_format($pending) }} to go. {{ $campaign->error }}</flux:text>
        @endif
        @if (in_array($campaign->status, ['paused', 'failed'], true))
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$campaign->error ?: 'Sending stopped.'">
                @if ($pending)
                    <x-slot name="actions"><flux:button size="sm" wire:click="resume">Resume sending ({{ number_format($pending) }} left)</flux:button></x-slot>
                @endif
            </flux:callout>
        @endif
        @if ($failures->isNotEmpty())
            <details class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <summary class="cursor-pointer font-medium">Messages that failed</summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($failures as $f)<li><span class="font-medium">{{ $isSms ? Phone::display($f->contact->phone) : $f->contact->email }}</span>: <span class="text-zinc-500">{{ $f->error }}</span></li>@endforeach
                </ul>
            </details>
        @endif
    @endunless

    <div class="grid gap-6 xl:grid-cols-2">
        <form wire:submit="save" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            @if ($editable && $aiEnabled)
                <div class="flex flex-col gap-2 rounded-lg border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-900 dark:bg-indigo-950/20">
                    <div class="flex items-center gap-2 text-sm font-medium"><flux:icon name="sparkles" variant="mini" class="text-indigo-500" /> Write with AI</div>
                    <div class="flex gap-2">
                        <flux:input wire:model="aiTopic" size="sm" :placeholder="$isSms ? 'e.g. weekend offer: 2 for 1 on all juices' : 'e.g. our December offers and new opening hours'" class="flex-1" />
                        <flux:button size="sm" variant="primary" wire:click="writeWithAi">Write</flux:button>
                    </div>
                    <span wire:loading wire:target="writeWithAi" class="text-xs text-zinc-500">Writing…</span>
                </div>
            @endif
            <fieldset @disabled(! $editable) class="flex flex-col gap-4">
                @if ($isSms)
                    <flux:input wire:model="subject" label="Campaign name" description="Only you see this." maxlength="150" />
                    <flux:field>
                        <flux:label>Message <span class="ms-auto text-xs font-normal tabular-nums text-zinc-500">{{ $smsParts['characters'] }} characters · {{ $smsParts['parts'] }} SMS{{ $smsParts['unicode'] ? ' · has emoji/special characters' : '' }}</span></flux:label>
                        <flux:textarea wire:model.live.debounce.300ms="body" rows="5" maxlength="600" />
                        <flux:description>
                            Keep it under 160 characters (with the opt-out link) to pay for 1 SMS per person. Emoji make each SMS shorter (70 characters).
                            Use {first_name}, {review_link} or {page_link}. An opt-out link is added at the end automatically.
                        </flux:description>
                        <flux:error name="body" />
                    </flux:field>
                @else
                    <flux:input wire:model.live.debounce.500ms="subject" label="Subject" placeholder="What’s new this month" maxlength="150" />
                    <flux:input wire:model="preheader" label="Preview text (optional)" placeholder="The grey line people see next to the subject" maxlength="150" />
                    <flux:textarea wire:model.live.debounce.500ms="body" label="Message" rows="16"
                        description="Write normally. **bold**, ## heading, [link text](https://…), ![photo](https://…image.jpg). Use {first_name} or {name} to greet people, {review_link} for your Google review link and {page_link} for your public page." />
                @endif
                @foreach ($missingLinks as $tag)
                    <flux:text class="text-sm text-amber-600">{{ $tag === '{review_link}' ? 'Add your Google Place ID in Grow on Google → Business Profile (or connect Google Business Profile) so {review_link} works.' : 'Turn on your public page so {page_link} works.' }}</flux:text>
                @endforeach
                <flux:select wire:model.live="tag" label="Send to">
                    <option value="">{{ $isSms ? 'Everyone with a phone number' : 'Everyone subscribed' }}</option>
                    @foreach ($tags as $t)
                        <option value="{{ $t }}">Only contacts tagged “{{ $t }}”</option>
                    @endforeach
                </flux:select>
                <flux:text class="text-sm">
                    This goes to <strong>{{ number_format($audience) }}</strong> {{ Str::plural('person', $audience) }}@if ($isSms) ({{ number_format($audience * $smsParts['parts']) }} SMS)@endif.
                    @if ($isSms && $smsLeft !== null && $smsLeft !== PHP_INT_MAX)
                        You have {{ number_format($smsLeft) }} SMS left this month.
                    @endif
                </flux:text>
            </fieldset>

            @if ($editable)
                <div class="flex flex-wrap items-end gap-2">
                    <flux:button type="submit">Save draft</flux:button>
                    @if ($isSms)
                        <flux:input wire:model="testPhone" type="tel" size="sm" placeholder="Your phone" class="max-w-36" aria-label="Phone for the test SMS" />
                    @endif
                    <flux:button wire:click="sendTest" icon="beaker">{{ $isSms ? 'Test SMS' : 'Send me a test' }}</flux:button>
                    <flux:button variant="primary" icon="paper-airplane" wire:click="sendNow"
                        wire:confirm="Send this {{ $isSms ? 'SMS' : 'email' }} to {{ number_format($audience) }} {{ Str::plural('person', $audience) }} now?">Send now</flux:button>
                    <x-action-message on="campaign-saved">Saved.</x-action-message>
                    <span wire:loading wire:target="sendTest,sendNow" class="text-sm text-zinc-500">Working…</span>
                </div>
                <div class="flex flex-wrap items-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:input type="datetime-local" wire:model="scheduleAt" label="Or schedule for ({{ $tz }})" class="max-w-60" />
                    <flux:button wire:click="schedule" icon="clock">Schedule</flux:button>
                    @if ($campaign->status === 'scheduled')
                        <flux:button variant="ghost" wire:click="unschedule">Cancel schedule</flux:button>
                    @endif
                </div>
                <flux:error name="scheduleAt" />
            @endif
        </form>

        <section class="flex flex-col gap-2">
            <flux:heading>Preview</flux:heading>
            @if ($isSms)
                <div class="flex justify-center rounded-xl border border-zinc-200 bg-zinc-100 p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="w-full max-w-xs rounded-[2rem] border-4 border-zinc-800 bg-white p-4 shadow-lg">
                        <div class="text-center text-xs font-semibold text-zinc-500">{{ $smsSender }}</div>
                        <div class="mt-3 max-w-[90%] whitespace-pre-line break-words rounded-2xl rounded-bl-sm bg-zinc-200 px-3 py-2 text-sm text-zinc-900">{{ $smsPreview ?: 'Your message…' }}
Opt out: {{ parse_url(url('/'), PHP_URL_HOST) }}/s/…</div>
                    </div>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 p-3 sm:p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mx-auto max-w-[600px] rounded-lg bg-white p-5 text-zinc-900 shadow-xs sm:p-7">
                        <div class="mb-4 border-b border-zinc-100 pb-3 text-sm">
                            <div class="font-semibold">{{ Smtp::personalise($subject, auth()->user()->name, Smtp::links(auth()->user())) ?: 'No subject' }}</div>
                            @if ($preheader)<div class="text-zinc-500">{{ $preheader }}</div>@endif
                        </div>
                        <div class="break-anywhere text-[15px] leading-relaxed [&_a]:text-indigo-600 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-zinc-200 [&_blockquote]:ps-3 [&_blockquote]:italic [&_h1]:mb-3 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mb-2 [&_h2]:mt-5 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:font-semibold [&_img]:my-3 [&_img]:rounded-lg [&_li]:ms-5 [&_ol]:list-decimal [&_p]:my-3 [&_ul]:list-disc">
                            {!! $preview !!}
                        </div>
                        <div class="mt-6 border-t border-zinc-100 pt-3 text-xs text-zinc-500">You are receiving this because you joined the mailing list. <span class="underline">Unsubscribe</span></div>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
