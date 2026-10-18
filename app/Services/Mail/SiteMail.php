<?php

namespace App\Services\Mail;

use App\Models\Setting;

/**
 * The site's own email (password resets, verification, trial and payment emails, alerts), sent through the
 * mailbox the admin connects in Admin → Settings → Email. Falls back to the .env MAIL_* settings when none is set.
 */
class SiteMail
{
    public static function configured(): bool
    {
        return filled(Setting::get('mail.host')) && filled(Setting::get('mail.username')) && filled(Setting::get('mail.password'));
    }

    /** Point Laravel's mailer at the admin's mailbox. Called on every boot. */
    public static function apply(): void
    {
        if (! self::configured()) {
            return;
        }

        $username = Setting::get('mail.username');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => Smtp::config(Setting::get('mail.host'), (int) Setting::get('mail.port', 587), $username, Setting::get('mail.password')),
            'mail.from.address' => Setting::get('mail.from_address', $username),
            'mail.from.name' => Setting::get('mail.from_name', config('app.name')),
        ]);
    }

    /** New accounts must confirm their email before using the app (admin switch, only when email works). */
    public static function requiresVerification(): bool
    {
        return Setting::get('mail.require_verification') === '1' && self::configured();
    }
}
