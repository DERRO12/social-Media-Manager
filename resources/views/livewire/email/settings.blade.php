<?php

use App\Http\Controllers\PublicProfileController;
use App\Services\Mail\Smtp;
use Livewire\Volt\Component;

new class extends Component {
    public string $mailProvider = 'gmail';
    public string $mailHost = '';
    public $mailPort = 587;
    public string $mailUsername = '';
    public string $mailPassword = '';
    public string $mailFromName = '';
    public string $replyTo = '';
    public $dailyLimit = 400;
    public ?string $mailResult = null;
    public bool $mailOk = false;

    public bool $newsletterEnabled = false;
    public string $newsletterText = '';

    public function mount(): void
    {
        $user = auth()->user();
        $box = $user->mailbox;

        $this->mailProvider = $box->provider ?? 'gmail';
        $this->mailHost = (string) ($box->host ?? '');
        $this->mailPort = (int) ($box->port ?? 587);
        $this->mailUsername = (string) ($box->username ?? '');
        $this->mailFromName = (string) ($box->from_name ?? $user->publicProfile?->name() ?? $user->name);
        $this->replyTo = (string) ($box->reply_to ?? '');
        $this->dailyLimit = (int) ($box->daily_limit ?? 400);
        $this->newsletterEnabled = (bool) $user->publicProfile?->newsletter_enabled;
        $this->newsletterText = (string) ($user->publicProfile?->newsletter_text ?? '');
    }

    private function server(): array
    {
        $preset = Smtp::PROVIDERS[$this->mailProvider] ?? Smtp::PROVIDERS['custom'];

        return $this->mailProvider === 'custom' ? [trim($this->mailHost), (int) $this->mailPort] : [$preset[1], $preset[2]];
    }

    public function saveMail(): void
    {
        $box = auth()->user()->mailbox;

        $this->validate([
            'mailProvider' => ['required', 'in:'.implode(',', array_keys(Smtp::PROVIDERS))],
            'mailHost' => ['required_if:mailProvider,custom', 'nullable', 'string', 'max:255'],
            'mailPort' => ['required_if:mailProvider,custom', 'nullable', 'integer', 'between:1,65535'],
            'mailUsername' => ['required', 'email', 'max:255'],
            'mailPassword' => [$box ? 'nullable' : 'required', 'string', 'max:255'],
            'mailFromName' => ['nullable', 'string', 'max:100'],
            'replyTo' => ['nullable', 'email', 'max:255'],
            'dailyLimit' => ['required', 'integer', 'between:1,2000'],
        ], ['mailPassword.required' => 'Paste the app password.']);

        [$host, $port] = $this->server();

        $attributes = [
            'provider' => $this->mailProvider,
            'host' => $host,
            'port' => $port,
            'username' => strtolower(trim($this->mailUsername)),
            'from_name' => $this->mailFromName ?: null,
            'reply_to' => $this->replyTo ?: null,
            'daily_limit' => (int) $this->dailyLimit,
        ];
        if ($this->mailPassword !== '') { // empty keeps the saved one
            $attributes['password'] = Smtp::cleanPassword($this->mailPassword);
        }

        auth()->user()->mailbox()->updateOrCreate([], $attributes);

        $this->mailPassword = '';
        $this->mailResult = null;
        $this->dispatch('saved-mail');
    }

    public function testMail(): void
    {
        $box = auth()->user()->mailbox;
        [$host, $port] = $this->server();
        $password = $this->mailPassword !== '' ? Smtp::cleanPassword($this->mailPassword) : (string) $box?->password;

        if (! $host || ! $this->mailUsername || ! $password) {
            [$this->mailOk, $this->mailResult] = [false, 'Fill in your email address and app password first.'];

            return;
        }

        $error = Smtp::test(Smtp::config($host, $port, trim($this->mailUsername), $password), trim($this->mailUsername), $this->mailFromName ?: auth()->user()->name, auth()->user()->email);
        $this->mailOk = $error === null;
        $this->mailResult = $error ?? 'Test email sent to '.auth()->user()->email.'. Check the inbox (and spam folder).';

        if ($this->mailOk && $box && $this->mailPassword === '') {
            $box->update(['verified_at' => now()]);
        }
    }

    public function disconnect(): void
    {
        auth()->user()->mailbox?->delete();
        $this->reset('mailUsername', 'mailPassword', 'mailResult');
    }

    public function saveForm(): void
    {
        $this->validate(['newsletterText' => ['nullable', 'string', 'max:200']]);
        $profile = auth()->user()->publicProfile;

        if (! $profile) {
            $this->addError('newsletterEnabled', 'Set up your public page first.');

            return;
        }

        $profile->update(['newsletter_enabled' => $this->newsletterEnabled, 'newsletter_text' => $this->newsletterText ?: null]);
        $this->dispatch('saved-form');
    }

    public function with(): array
    {
        $user = auth()->user();
        $profile = $user->publicProfile;

        return [
            'box' => $user->mailbox,
            'profile' => $profile,
            'live' => $profile && PublicProfileController::isLive($profile),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.email-tabs')

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">Your mailbox</flux:heading>
                @if ($box)
                    <flux:badge color="green" icon="check">Connected</flux:badge>
                @else
                    <flux:badge>Not connected</flux:badge>
                @endif
            </div>
            <flux:text class="text-sm">
                Newsletters are sent from your own email address, so replies come straight to you and people recognise the sender.
                Your app password is stored encrypted and only used to send your emails.
            </flux:text>
            <form wire:submit="saveMail" class="flex flex-col gap-4">
                @include('partials.mailbox-fields', ['passwordSaved' => (bool) $box])
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="replyTo" type="email" label="Reply-to (optional)" placeholder="Same as the address above" />
                    <flux:input wire:model="dailyLimit" type="number" min="1" max="2000" label="Emails per day, at most" description="Gmail: 400 is safe. Workspace: up to 1,800." />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary">Save</flux:button>
                    <flux:button wire:click="testMail" icon="paper-airplane">Send me a test</flux:button>
                    @if ($box)
                        <flux:button variant="ghost" wire:click="disconnect" wire:confirm="Disconnect your mailbox? Campaigns can’t be sent until you connect one again.">Disconnect</flux:button>
                    @endif
                    <x-action-message on="saved-mail">Saved.</x-action-message>
                    <span wire:loading wire:target="testMail" class="text-sm text-zinc-500">Sending…</span>
                </div>
                @if ($mailResult)
                    <flux:callout :variant="$mailOk ? 'success' : 'danger'" :icon="$mailOk ? 'check-circle' : 'exclamation-triangle'" :heading="$mailResult" />
                @endif
            </form>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">Sign-up form on your public page</flux:heading>
            <flux:text class="text-sm">Visitors can join your mailing list from your public page. Share the link in your bios, posts and WhatsApp status to grow your list.</flux:text>
            @if ($profile)
                <form wire:submit="saveForm" class="flex flex-col gap-4">
                    <flux:switch wire:model="newsletterEnabled" label="Show the sign-up form" />
                    <flux:input wire:model="newsletterText" label="Invitation" placeholder="Get my new styles and offers first, once a month." maxlength="200" />
                    <flux:error name="newsletterEnabled" />
                    <div class="flex items-center gap-3">
                        <flux:button type="submit" variant="primary">Save</flux:button>
                        <x-action-message on="saved-form">Saved.</x-action-message>
                    </div>
                </form>
                @if ($live && $profile->newsletter_enabled)
                    <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
                        Share this link: <code class="select-all">{{ $profile->url() }}#subscribe</code>
                    </div>
                @elseif (! $live)
                    <flux:text class="text-sm text-amber-600">Your public page is off, so the form isn’t visible yet. <a href="{{ route('grow.page') }}" wire:navigate class="underline">Turn it on</a></flux:text>
                @endif
            @else
                <flux:button :href="route('grow.page')" wire:navigate icon="globe-alt">Set up your public page first</flux:button>
            @endif

            <flux:separator />
            <flux:heading>Good habits that keep you out of spam</flux:heading>
            <ul class="list-disc space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>Only email people who agreed to hear from you.</li>
                <li>Every email has an unsubscribe link. Never remove people’s choice.</li>
                <li>Send regularly (weekly or monthly), not in sudden bursts.</li>
                <li>Write like a person: a clear subject, one main message, one link.</li>
            </ul>
        </section>
    </div>
</div>
