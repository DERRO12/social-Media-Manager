<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sending email through a normal mailbox (Gmail, Outlook, Zoho…) over SMTP, logged in with an app password.
 * Used for the site's own emails (Admin → Settings) and for each client's mailbox (Email marketing).
 */
class Smtp
{
    /** provider => [label, host, port, where to create an app password] */
    public const PROVIDERS = [
        'gmail' => ['Gmail or Google Workspace', 'smtp.gmail.com', 587, 'https://myaccount.google.com/apppasswords'],
        'outlook' => ['Outlook or Microsoft 365', 'smtp.office365.com', 587, 'https://account.live.com/proofs/AppPassword'],
        'zoho' => ['Zoho Mail', 'smtp.zoho.com', 587, 'https://accounts.zoho.com/home#security/app_password'],
        'custom' => ['Other (enter server details)', '', 587, null],
    ];

    /** Mailer config for Laravel's SMTP transport. Port 465 uses SSL; others upgrade with STARTTLS. */
    public static function config(string $host, int $port, string $username, string $password): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $port === 465 ? 'smtps' : 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'timeout' => 20,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ];
    }

    /**
     * A mailer for this SMTP login, registered under a name and rebuilt each time so changed passwords
     * apply even in long-running queue workers.
     */
    public static function mailer(string $name, array $config): \Illuminate\Contracts\Mail\Mailer
    {
        config(["mail.mailers.{$name}" => $config]);
        Mail::purge($name);

        return Mail::mailer($name);
    }

    /** Google shows app passwords as "abcd efgh ijkl mnop"; the spaces aren't part of it. */
    public static function cleanPassword(string $password): string
    {
        return preg_replace('/\s+/', '', $password);
    }

    /** Send a short test email. Returns null when it worked, or an explanation of what went wrong. */
    public static function test(array $config, string $fromAddress, string $fromName, string $to): ?string
    {
        try {
            self::mailer('smtp-test', $config)->raw(
                'This is a test email from '.config('app.name').".\n\nIf you can read this, email sending works.",
                fn ($message) => $message->from($fromAddress, $fromName)->to($to)->subject('Test email from '.config('app.name')),
            );

            return null;
        } catch (Throwable $e) {
            return self::explain($e);
        }
    }

    /** Turn SMTP errors into advice a non-technical person can act on. */
    public static function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, '535') || str_contains($message, 'Username and Password not accepted') || str_contains($message, 'authentication failed')
                => 'The email provider rejected the login. Use an app password (not your normal password), make sure 2-Step Verification is on, and check the email address.',
            str_contains($message, '534') => 'Google wants an app password for this account. Turn on 2-Step Verification, then create an app password.',
            str_contains($message, 'Connection could not be established') || str_contains($message, 'timed out')
                => 'Could not reach the mail server. Check the server name and port (Gmail: smtp.gmail.com, port 587).',
            str_contains($message, '550') || str_contains($message, '553') => 'The mail server refused the sender address. Send from the same address you log in with.',
            default => 'Sending failed: '.mb_substr($message, 0, 300),
        };
    }

    /** Merge tags people can use in emails. */
    public static function personalise(string $text, ?string $name): string
    {
        $name = trim((string) $name);

        return strtr($text, [
            '{name}' => $name ?: 'there',
            '{first_name}' => $name ? strtok($name, ' ') : 'there',
        ]);
    }
}
