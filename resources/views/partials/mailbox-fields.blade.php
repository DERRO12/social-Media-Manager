{{-- Mailbox login fields shared by Admin → Settings → Email and Email marketing → Settings.
     The component provides: mailProvider, mailHost, mailPort, mailUsername, mailPassword, mailFromName.
     Pass $passwordSaved (bool). --}}
@php($provider = \App\Services\Mail\Smtp::PROVIDERS[$mailProvider] ?? \App\Services\Mail\Smtp::PROVIDERS['custom'])

<flux:select wire:model.live="mailProvider" label="Email provider">
    @foreach (\App\Services\Mail\Smtp::PROVIDERS as $key => [$label])
        <option value="{{ $key }}">{{ $label }}</option>
    @endforeach
</flux:select>

@if ($mailProvider === 'gmail')
    <div class="rounded-lg bg-sky-50 p-4 text-sm text-sky-950 dark:bg-sky-950/40 dark:text-sky-100">
        <div class="font-medium">Get a Gmail app password (2 minutes)</div>
        <ol class="mt-2 list-decimal space-y-1 ps-5">
            <li>Turn on <a href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener" class="underline">2-Step Verification</a> for the Google account.</li>
            <li>Open <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" class="underline">App passwords</a>, type a name like “{{ config('app.name') }}” and click <strong>Create</strong>.</li>
            <li>Copy the 16-letter password Google shows and paste it below. (It is not your normal Gmail password.)</li>
        </ol>
        <div class="mt-2 text-xs opacity-80">Gmail sends up to about 500 emails a day (2,000 with Google Workspace).</div>
    </div>
@elseif ($provider[3])
    <flux:text class="text-sm">Create an app password here: <a href="{{ $provider[3] }}" target="_blank" rel="noopener" class="underline">{{ parse_url($provider[3], PHP_URL_HOST) }}</a></flux:text>
@endif

@if ($mailProvider === 'custom')
    <div class="grid gap-4 sm:grid-cols-[1fr_120px]">
        <flux:input wire:model="mailHost" label="SMTP server" placeholder="mail.yourdomain.com" />
        <flux:input wire:model="mailPort" type="number" label="Port" description="587 or 465" />
    </div>
@endif

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input wire:model="mailUsername" type="email" label="Email address" placeholder="you@gmail.com" autocomplete="off" />
    <flux:input wire:model="mailPassword" type="password" label="App password" autocomplete="new-password"
        :placeholder="$passwordSaved ? 'Saved. Leave empty to keep' : 'abcd efgh ijkl mnop'" />
</div>
<flux:input wire:model="mailFromName" label="Sender name" :placeholder="config('app.name')" description="The name people see in their inbox." />
