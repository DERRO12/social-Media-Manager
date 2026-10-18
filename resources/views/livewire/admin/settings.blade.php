<?php

use App\Enums\Platform;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\StripeBilling;
use App\Services\Mail\SiteMail;
use App\Services\Mail\Smtp;
use Illuminate\Support\Facades\Artisan;
use App\Services\Social\SocialManager;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Platform app credentials the admin can enter here.
     * group => [label, platforms it powers, fields => [key => [label, secret?]]]
     */
    public const GROUPS = [
        'youtube' => ['Google (YouTube and Business Profile)', ['youtube', 'google_business'], 'https://console.cloud.google.com/apis/credentials', [
            'client_id' => ['Client ID', false],
            'client_secret' => ['Client secret', true],
            'api_key' => ['API key (optional: Trending for clients without a channel)', true],
        ]],
        'meta' => ['Facebook & Instagram (one Meta app)', ['facebook', 'instagram'], 'https://developers.facebook.com/apps', [
            'client_id' => ['App ID', false],
            'client_secret' => ['App secret', true],
            'graph_version' => ['Graph API version', false],
            'config_id' => ['Login configuration ID (Facebook Login for Business)', false],
        ]],
        'tiktok' => ['TikTok', ['tiktok'], 'https://developers.tiktok.com/apps', [
            'client_id' => ['Client key', false],
            'client_secret' => ['Client secret', true],
            'default_privacy' => ['Default privacy (SELF_ONLY until audited)', false],
        ]],
        'linkedin' => ['LinkedIn', ['linkedin'], 'https://www.linkedin.com/developers/apps', [
            'client_id' => ['Client ID', false],
            'client_secret' => ['Client secret', true],
            'api_version' => ['API version (YYYYMM, leave empty for automatic)', false],
        ]],
        'x' => ['X (Twitter)', ['x'], 'https://developer.x.com/en/portal/dashboard', [
            'client_id' => ['OAuth 2.0 Client ID', false],
            'client_secret' => ['OAuth 2.0 Client secret', true],
        ]],
    ];

    public $trialDays = 14;
    public $trialPlanId = null;
    public string $supportContact = '';

    public string $legalOwner = '';
    public string $legalEmail = '';
    public string $legalCountry = '';

    public string $stripeSecret = '';
    public string $stripeWebhookSecret = '';

    /** @var array<string, array<string, string>> */
    public array $platform = [];
    public bool $linkedinCommunityApi = false;
    public bool $xReadAccess = false;

    public string $mailProvider = 'gmail';
    public string $mailHost = '';
    public $mailPort = 587;
    public string $mailUsername = '';
    public string $mailPassword = '';
    public string $mailFromName = '';
    public bool $requireVerification = false;
    public ?string $mailResult = null;
    public bool $mailOk = false;

    public function mount(): void
    {
        $this->trialDays = (int) Setting::get('billing.trial_days', 14);
        $this->trialPlanId = Setting::get('billing.trial_plan_id');
        $this->supportContact = (string) Setting::get('billing.support_contact', '');
        $this->legalOwner = (string) Setting::get('legal.owner', '');
        $this->legalEmail = (string) Setting::get('legal.email', '');
        $this->legalCountry = (string) Setting::get('legal.country', '');

        foreach (self::GROUPS as $group => [, , , $fields]) {
            $current = SocialManager::credentials($group);

            foreach ($fields as $key => [, $secret]) {
                // Never send saved secrets back to the browser.
                $this->platform[$group][$key] = $secret ? '' : (string) ($current[$key] ?? '');
            }
        }

        $this->linkedinCommunityApi = (bool) SocialManager::credentials('linkedin')['community_api'];
        $this->xReadAccess = (bool) SocialManager::credentials('x')['read_access'];

        $this->mailProvider = (string) Setting::get('mail.provider', 'gmail');
        $this->mailHost = (string) Setting::get('mail.host', '');
        $this->mailPort = (int) Setting::get('mail.port', 587);
        $this->mailUsername = (string) Setting::get('mail.username', '');
        $this->mailFromName = (string) Setting::get('mail.from_name', config('app.name'));
        $this->requireVerification = Setting::get('mail.require_verification') === '1';
    }

    /** Server details from the chosen provider, or the custom ones typed in. */
    private function mailServer(): array
    {
        $preset = Smtp::PROVIDERS[$this->mailProvider] ?? Smtp::PROVIDERS['custom'];

        return $this->mailProvider === 'custom' ? [trim($this->mailHost), (int) $this->mailPort] : [$preset[1], $preset[2]];
    }

    public function saveMail(): void
    {
        $this->validate([
            'mailProvider' => ['required', 'in:'.implode(',', array_keys(Smtp::PROVIDERS))],
            'mailHost' => ['required_if:mailProvider,custom', 'nullable', 'string', 'max:255'],
            'mailPort' => ['required_if:mailProvider,custom', 'nullable', 'integer', 'between:1,65535'],
            'mailUsername' => ['required', 'email', 'max:255'],
            'mailPassword' => [Setting::get('mail.password') ? 'nullable' : 'required', 'string', 'max:255'],
            'mailFromName' => ['nullable', 'string', 'max:100'],
        ], ['mailPassword.required' => 'Paste the app password.']);

        [$host, $port] = $this->mailServer();
        Setting::put('mail.provider', $this->mailProvider);
        Setting::put('mail.host', $host);
        Setting::put('mail.port', $port);
        Setting::put('mail.username', strtolower(trim($this->mailUsername)));
        Setting::put('mail.from_name', $this->mailFromName ?: config('app.name'));
        if ($this->mailPassword !== '') {
            Setting::put('mail.password', Smtp::cleanPassword($this->mailPassword), encrypt: true);
            $this->mailPassword = '';
        }

        $this->restartWorkers();
        $this->mailResult = null;
        $this->dispatch('saved-mail');
    }

    /** Send a test email to the admin with the saved (or just typed) details. */
    public function testMail(): void
    {
        [$host, $port] = $this->mailServer();
        $password = $this->mailPassword !== '' ? Smtp::cleanPassword($this->mailPassword) : (string) Setting::get('mail.password');

        if (! $host || ! $this->mailUsername || ! $password) {
            [$this->mailOk, $this->mailResult] = [false, 'Fill in the email address and app password first.'];

            return;
        }

        $error = Smtp::test(Smtp::config($host, $port, trim($this->mailUsername), $password), trim($this->mailUsername), $this->mailFromName ?: config('app.name'), auth()->user()->email);
        $this->mailOk = $error === null;
        $this->mailResult = $error ?? 'Test email sent to '.auth()->user()->email.'. Check the inbox (and spam folder).';
    }

    public function saveVerification(): void
    {
        if ($this->requireVerification && ! SiteMail::configured()) {
            $this->requireVerification = false;
            $this->addError('requireVerification', 'Connect a mailbox first, or people could not receive the confirmation email.');

            return;
        }

        // Existing accounts are treated as confirmed, so turning this on never locks anyone out.
        if ($this->requireVerification) {
            User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        }

        Setting::put('mail.require_verification', $this->requireVerification ? '1' : '0');
        $this->dispatch('saved-verification');
    }

    public function removeMail(): void
    {
        foreach (['provider', 'host', 'port', 'username', 'password', 'from_name', 'require_verification'] as $key) {
            Setting::put("mail.{$key}", null);
        }

        $this->restartWorkers();
        $this->reset('mailUsername', 'mailPassword', 'mailResult', 'requireVerification');
    }

    /** Background workers read mail settings when they start; restart them so they pick up the change. */
    private function restartWorkers(): void
    {
        rescue(fn () => Artisan::call('queue:restart'), report: false);
    }

    public function saveBilling(): void
    {
        $this->validate([
            'trialDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'trialPlanId' => ['nullable', 'exists:plans,id'],
            'supportContact' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::put('billing.trial_days', (int) $this->trialDays);
        Setting::put('billing.trial_plan_id', $this->trialPlanId);
        Setting::put('billing.support_contact', $this->supportContact);

        $this->dispatch('saved-billing');
    }

    public function saveLegal(): void
    {
        $this->validate([
            'legalOwner' => ['nullable', 'string', 'max:150'],
            'legalEmail' => ['nullable', 'email', 'max:150'],
            'legalCountry' => ['nullable', 'string', 'max:100'],
        ]);

        Setting::put('legal.owner', $this->legalOwner);
        Setting::put('legal.email', $this->legalEmail);
        Setting::put('legal.country', $this->legalCountry);
        Setting::put('legal.updated', now()->format('F j, Y'));

        $this->dispatch('saved-legal');
    }

    public function saveStripe(): void
    {
        $this->validate([
            'stripeSecret' => ['nullable', 'string', 'regex:/^(sk|rk)_(test|live)_/'],
            'stripeWebhookSecret' => ['nullable', 'string', 'starts_with:whsec_'],
        ], [
            'stripeSecret.regex' => 'Stripe secret keys start with sk_test_ or sk_live_.',
            'stripeWebhookSecret.starts_with' => 'Webhook signing secrets start with whsec_.',
        ]);

        // Empty field = keep the saved value.
        if ($this->stripeSecret !== '') {
            Setting::put('stripe.secret', $this->stripeSecret, encrypt: true);
        }
        if ($this->stripeWebhookSecret !== '') {
            Setting::put('stripe.webhook_secret', $this->stripeWebhookSecret, encrypt: true);
        }

        $this->reset('stripeSecret', 'stripeWebhookSecret');
        $this->dispatch('saved-stripe');
    }

    public function disconnectStripe(): void
    {
        Setting::put('stripe.secret', null);
        Setting::put('stripe.webhook_secret', null);
    }

    public function savePlatform(string $group): void
    {
        abort_unless(isset(self::GROUPS[$group]), 404);

        foreach (self::GROUPS[$group][3] as $key => [, $secret]) {
            $value = trim((string) ($this->platform[$group][$key] ?? ''));

            if ($secret && $value === '') {
                continue; // keep the saved secret
            }

            Setting::put("{$group}.{$key}", $value, encrypt: $secret);

            if ($secret) {
                $this->platform[$group][$key] = '';
            }
        }

        if ($group === 'linkedin') {
            Setting::put('linkedin.community_api', $this->linkedinCommunityApi ? '1' : '0');
        }

        if ($group === 'x') {
            Setting::put('x.read_access', $this->xReadAccess ? '1' : '0');
        }

        $this->dispatch("saved-{$group}");
    }

    public function with(): array
    {
        $social = app(SocialManager::class);

        return [
            'groups' => self::GROUPS,
            'plans' => Plan::orderBy('sort')->get(),
            'mailConfigured' => SiteMail::configured(),
            'mailPasswordSaved' => filled(Setting::get('mail.password')),
            'stripeEnabled' => app(StripeBilling::class)->enabled(),
            'stripeWebhookSaved' => filled(app(StripeBilling::class)->webhookSecret()),
            'saved' => collect(self::GROUPS)->mapWithKeys(fn ($g, $group) => [
                $group => collect($g[3])->filter(fn ($f) => $f[1])->keys()
                    ->mapWithKeys(fn ($key) => [$key => filled(SocialManager::credentials($group)[$key] ?? null)]),
            ]),
            'configured' => collect(self::GROUPS)->mapWithKeys(fn ($g, $group) => [
                $group => $social->driver(Platform::from($g[1][0]))->configured(),
            ]),
        ];
    }
}; ?>

<div class="flex max-w-4xl flex-col gap-8">
    <div>
        <flux:heading size="xl">Settings</flux:heading>
        <flux:text>Control email, trials, payments and which social platforms your clients can connect.</flux:text>
    </div>

    {{-- Site email --}}
    <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">Email (Gmail with app password)</flux:heading>
            @if ($mailConfigured)
                <flux:badge color="green" icon="check">Connected: {{ $mailUsername }}</flux:badge>
            @else
                <flux:badge color="amber">Not connected: emails are not being sent</flux:badge>
            @endif
        </div>
        <flux:text class="text-sm">
            Sends the site’s emails: password resets, email confirmation, welcome emails, trial and plan reminders, payment updates,
            failed post alerts and certificates. Use a mailbox you own, e.g. a Gmail address for {{ config('app.name') }}.
        </flux:text>
        <form wire:submit="saveMail" class="flex flex-col gap-4">
            @include('partials.mailbox-fields', ['passwordSaved' => $mailPasswordSaved])
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary">Save</flux:button>
                <flux:button wire:click="testMail" icon="paper-airplane">Send test email to me</flux:button>
                @if ($mailConfigured)
                    <flux:button variant="ghost" wire:click="removeMail" wire:confirm="Disconnect the mailbox? The site will stop sending emails.">Disconnect</flux:button>
                @endif
                <x-action-message on="saved-mail">Saved.</x-action-message>
                <span wire:loading wire:target="testMail" class="text-sm text-zinc-500">Sending…</span>
            </div>
            @if ($mailResult)
                <flux:callout :variant="$mailOk ? 'success' : 'danger'" :icon="$mailOk ? 'check-circle' : 'exclamation-triangle'" :heading="$mailResult" />
            @endif
        </form>
        <flux:separator />
        <div class="flex flex-col gap-2">
            <flux:switch wire:model="requireVerification" wire:change="saveVerification" label="New sign-ups must confirm their email"
                description="Sends a confirmation link at sign-up and blocks fake addresses. Existing accounts are not affected." />
            <flux:error name="requireVerification" />
            <x-action-message on="saved-verification">Saved.</x-action-message>
        </div>
    </section>

    {{-- Trials --}}
    <form wire:submit="saveBilling" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">Free trial for new sign-ups</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input type="number" min="0" wire:model="trialDays" label="Trial length (days)" description="0 = no free trial; new clients must choose a plan first." />
            <flux:select wire:model="trialPlanId" label="Trial gives the limits of" description="Which plan's limits apply during the trial.">
                <option value="">First available plan</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                @endforeach
            </flux:select>
        </div>
        <flux:input wire:model="supportContact" label="How clients pay you manually" placeholder="e.g. WhatsApp +233 20 000 0000 or billing@yourdomain.com"
            description="Shown on the client billing page for bank transfer / mobile money payments." />
        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save</flux:button>
            <x-action-message on="saved-billing">Saved.</x-action-message>
        </div>
    </form>

    {{-- Legal pages --}}
    <form wire:submit="saveLegal" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">Privacy Policy &amp; Terms</flux:heading>
            <div class="flex gap-3 text-sm">
                <flux:link :href="route('privacy')" target="_blank">View Privacy Policy</flux:link>
                <flux:link :href="route('terms')" target="_blank">View Terms</flux:link>
            </div>
        </div>
        <flux:text class="text-sm">
            Shown on your public Privacy Policy and Terms pages, which Google, Meta and TikTok require before approving your app.
            Use the links above as the “Privacy policy URL” and “Terms of service URL” in each platform’s app settings.
        </flux:text>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="legalOwner" label="Business or owner name" :placeholder="config('app.name')" />
            <flux:input wire:model="legalEmail" type="email" label="Contact email" placeholder="privacy@yourdomain.com" />
            <flux:input wire:model="legalCountry" label="Country" placeholder="Uganda" />
        </div>
        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save</flux:button>
            <x-action-message on="saved-legal">Saved. The “last updated” date was set to today.</x-action-message>
        </div>
    </form>

    {{-- Stripe --}}
    <form wire:submit="saveStripe" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Online card payments (Stripe)</flux:heading>
            @if ($stripeEnabled)
                <flux:badge color="green">Connected</flux:badge>
            @else
                <flux:badge>Off</flux:badge>
            @endif
        </div>
        <flux:text class="text-sm">
            Optional. Without Stripe, you activate clients manually from their client page.
            With Stripe, also add a Stripe Price ID to each plan on the Plans page.
        </flux:text>
        <flux:input wire:model="stripeSecret" type="password" label="Secret key" :placeholder="$stripeEnabled ? 'Saved — leave empty to keep' : 'sk_test_…'" />
        <flux:input wire:model="stripeWebhookSecret" type="password" label="Webhook signing secret" :placeholder="$stripeWebhookSaved ? 'Saved — leave empty to keep' : 'whsec_…'" />
        <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
            Webhook URL to add in Stripe: <code class="select-all">{{ route('stripe.webhook') }}</code><br>
            Events: <code>checkout.session.completed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed</code>
        </div>
        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save Stripe keys</flux:button>
            @if ($stripeEnabled)
                <flux:button variant="ghost" wire:click="disconnectStripe" wire:confirm="Remove the Stripe keys? Existing Stripe subscriptions keep billing in Stripe, but this app will stop receiving updates.">Remove keys</flux:button>
            @endif
            <x-action-message on="saved-stripe">Saved.</x-action-message>
        </div>
    </form>

    {{-- Social platforms --}}
    <div class="flex flex-col gap-4">
        <div>
            <flux:heading size="lg">Social platform apps</flux:heading>
            <flux:text class="text-sm">
                Create one developer app per platform (your SaaS's app, not your clients'). Clients then just click “Connect”
                and log in on the platform's own page — they never give you their passwords.
                Keys saved here override the .env file.
            </flux:text>
        </div>

        @foreach ($groups as $group => [$label, $platforms, $consoleUrl, $fields])
            <form wire:submit="savePlatform('{{ $group }}')" wire:key="group-{{ $group }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>{{ $label }}</flux:heading>
                    <div class="flex items-center gap-2">
                        @if ($configured[$group])
                            <flux:badge size="sm" color="green">Ready — clients can connect</flux:badge>
                        @else
                            <flux:badge size="sm">Not set up</flux:badge>
                        @endif
                        <flux:link :href="$consoleUrl" target="_blank" class="text-sm">Developer console</flux:link>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $key => [$fieldLabel, $secret])
                        <flux:input wire:model="platform.{{ $group }}.{{ $key }}" :label="$fieldLabel"
                            :type="$secret ? 'password' : 'text'"
                            :placeholder="$secret && $saved[$group][$key] ? 'Saved — leave empty to keep' : ''" />
                    @endforeach
                </div>

                @if ($group === 'linkedin')
                    <flux:checkbox wire:model="linkedinCommunityApi" label="LinkedIn approved my app for the Community Management API (enables reading posts and comments)" />
                @endif

                @if ($group === 'x')
                    <flux:checkbox wire:model="xReadAccess" label="My X API plan includes reading (Basic or higher). Leave off on the Free plan, which can only post." />
                @endif

                @if ($group === 'youtube')
                    <div class="rounded-lg bg-sky-50 p-3 text-sm text-sky-950 dark:bg-sky-950/40 dark:text-sky-100">
                        <div class="font-medium">Google Business Profile (create businesses, Google reviews in the inbox)</div>
                        <ol class="mt-1 list-decimal space-y-1 ps-5">
                            <li>In the same Google Cloud project, request access: <a href="https://developers.google.com/my-business/content/prereqs#request-access" target="_blank" rel="noopener" class="underline">Business Profile API access form</a>. Google usually answers within a few days to two weeks.</li>
                            <li>Once approved, enable: My Business Account Management API, My Business Business Information API and Google My Business API.</li>
                            <li>Add the <code>business.manage</code> scope to the OAuth consent screen and the second redirect URL below.</li>
                        </ol>
                        <div class="mt-1 text-xs opacity-80">Until Google approves access, clients see a clear message when they try to connect.</div>
                    </div>
                @endif

                <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
                    Redirect / callback URL{{ count($platforms) > 1 ? 's' : '' }} to paste into the app:
                    @foreach ($platforms as $p)
                        <div><code class="select-all">{{ \App\Services\Social\SocialManager::callbackUrl(\App\Enums\Platform::from($p)) }}</code></div>
                    @endforeach
                    @if ($group === 'youtube')
                        <div class="mt-2">Also add this one, for <strong>“Continue with Google”</strong> on the login and sign-up pages (same OAuth client):</div>
                        <div><code class="select-all">{{ \App\Http\Controllers\GoogleLoginController::callbackUrl() }}</code></div>
                    @endif
                    <div class="mt-3 grid gap-1 border-t border-zinc-200 pt-3 sm:grid-cols-[auto_1fr] sm:gap-x-3 dark:border-zinc-700">
                        <span class="text-zinc-500">Website</span><code class="select-all">{{ rtrim(config('app.url'), '/') }}</code>
                        <span class="text-zinc-500">Privacy policy</span><code class="select-all">{{ rtrim(config('app.url'), '/').route('privacy', absolute: false) }}</code>
                        <span class="text-zinc-500">Terms of service</span><code class="select-all">{{ rtrim(config('app.url'), '/').route('terms', absolute: false) }}</code>
                        @if ($group === 'meta')
                            <span class="text-zinc-500">Data deletion instructions</span><code class="select-all">{{ rtrim(config('app.url'), '/').route('privacy', absolute: false) }}#data-deletion</code>
                        @endif
                        <span class="text-zinc-500">App domain</span><code class="select-all">{{ \Illuminate\Support\Str::after(parse_url(config('app.url'), PHP_URL_HOST), '.') ?: parse_url(config('app.url'), PHP_URL_HOST) }}</code>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">Save {{ explode(' ', $label)[0] }}</flux:button>
                    <x-action-message on="saved-{{ $group }}">Saved.</x-action-message>
                </div>
            </form>
        @endforeach
    </div>
</div>
