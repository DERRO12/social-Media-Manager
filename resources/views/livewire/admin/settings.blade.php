<?php

use App\Enums\Platform;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\Billing\StripeBilling;
use App\Services\Social\SocialManager;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Platform app credentials the admin can enter here.
     * group => [label, platforms it powers, fields => [key => [label, secret?]]]
     */
    public const GROUPS = [
        'youtube' => ['YouTube', ['youtube'], 'https://console.cloud.google.com/apis/credentials', [
            'client_id' => ['Client ID', false],
            'client_secret' => ['Client secret', true],
        ]],
        'meta' => ['Facebook & Instagram (one Meta app)', ['facebook', 'instagram'], 'https://developers.facebook.com/apps', [
            'client_id' => ['App ID', false],
            'client_secret' => ['App secret', true],
            'graph_version' => ['Graph API version', false],
        ]],
        'tiktok' => ['TikTok', ['tiktok'], 'https://developers.tiktok.com/apps', [
            'client_id' => ['Client key', false],
            'client_secret' => ['Client secret', true],
            'default_privacy' => ['Default privacy (SELF_ONLY until audited)', false],
        ]],
        'linkedin' => ['LinkedIn', ['linkedin'], 'https://www.linkedin.com/developers/apps', [
            'client_id' => ['Client ID', false],
            'client_secret' => ['Client secret', true],
            'api_version' => ['API version (YYYYMM)', false],
        ]],
        'x' => ['X (Twitter)', ['x'], 'https://developer.x.com/en/portal/dashboard', [
            'client_id' => ['OAuth 2.0 Client ID', false],
            'client_secret' => ['OAuth 2.0 Client secret', true],
        ]],
    ];

    public $trialDays = 14;
    public $trialPlanId = null;
    public string $supportContact = '';

    public string $stripeSecret = '';
    public string $stripeWebhookSecret = '';

    /** @var array<string, array<string, string>> */
    public array $platform = [];
    public bool $linkedinCommunityApi = false;

    public function mount(): void
    {
        $this->trialDays = (int) Setting::get('billing.trial_days', 14);
        $this->trialPlanId = Setting::get('billing.trial_plan_id');
        $this->supportContact = (string) Setting::get('billing.support_contact', '');

        foreach (self::GROUPS as $group => [, , , $fields]) {
            $current = SocialManager::credentials($group);

            foreach ($fields as $key => [, $secret]) {
                // Never send saved secrets back to the browser.
                $this->platform[$group][$key] = $secret ? '' : (string) ($current[$key] ?? '');
            }
        }

        $this->linkedinCommunityApi = (bool) SocialManager::credentials('linkedin')['community_api'];
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

        $this->dispatch("saved-{$group}");
    }

    public function with(): array
    {
        $social = app(SocialManager::class);

        return [
            'groups' => self::GROUPS,
            'plans' => Plan::orderBy('sort')->get(),
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
        <flux:text>Control trials, payments and which social platforms your clients can connect.</flux:text>
    </div>

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

                <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
                    Redirect / callback URL{{ count($platforms) > 1 ? 's' : '' }} to paste into the app:
                    @foreach ($platforms as $p)
                        <div><code class="select-all">{{ route('accounts.callback', $p) }}</code></div>
                    @endforeach
                </div>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">Save {{ explode(' ', $label)[0] }}</flux:button>
                    <x-action-message on="saved-{{ $group }}">Saved.</x-action-message>
                </div>
            </form>
        @endforeach
    </div>
</div>
