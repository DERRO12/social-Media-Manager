<?php

namespace App\Support;

use App\Models\Setting;

/**
 * How-to videos the admin adds (Admin → Help videos): a YouTube link per topic. Each shows as a
 * "Watch how" button on the page it explains, and all of them on the Help page.
 */
class HelpVideos
{
    /** topic => [title, what it shows, the page it belongs to (route name)] */
    public const TOPICS = [
        'getting_started' => ['Getting started', 'A quick tour of everything you can do.', 'dashboard'],
        'connect_accounts' => ['Connect your social accounts', 'YouTube, Facebook, Instagram, TikTok, LinkedIn and X.', 'accounts'],
        'create_post' => ['Create and schedule a post', 'Upload a video once and post it everywhere.', 'compose'],
        'ai_writer' => ['Write captions with AI', 'Captions, hashtags and replies in seconds.', 'compose'],
        'calendar' => ['Plan with the content calendar', 'Schedule posts around important days.', 'calendar'],
        'inbox' => ['Answer comments and reviews', 'Every comment and Google review in one inbox.', 'inbox'],
        'insights' => ['Read your insights', 'Find your best time to post and your top posts.', 'insights'],
        'app_password' => ['Create a Gmail app password', 'The 16-letter password that lets the app send your emails.', 'email.settings'],
        'email_campaign' => ['Send an email newsletter', 'Write, preview, test and send a campaign.', 'email.campaigns'],
        'contacts_import' => ['Import emails and phone numbers', 'From Excel, Google Sheets or your phone.', 'email.contacts'],
        'egosms_account' => ['Set up EgoSMS', 'Open an account, buy credit and get a sender ID.', 'email.settings'],
        'sms_campaign' => ['Send an SMS campaign', 'Write a short SMS and send it to your contacts.', 'email.campaigns'],
        'google_business' => ['Your Google Business Profile', 'Get your business on Google Maps and collect reviews.', 'grow.business'],
        'public_page' => ['Your public page and photos', 'Show up on Google and in Google Images.', 'grow.page'],
        'links_qr' => ['Short links and QR codes', 'Track clicks and print QR codes.', 'links'],
        'academy' => ['Learn in the Academy', 'Courses, quizzes and certificates.', 'academy'],
    ];

    /** @return array<string, string> topic => YouTube video ID */
    public static function all(): array
    {
        $saved = json_decode((string) Setting::get('help.videos', '{}'), true);

        return array_intersect_key(is_array($saved) ? $saved : [], self::TOPICS);
    }

    public static function get(string $topic): ?string
    {
        return self::all()[$topic] ?? null;
    }

    /** @param array<string, string|null> $videos topic => YouTube ID */
    public static function save(array $videos): void
    {
        Setting::put('help.videos', json_encode(array_filter(array_intersect_key($videos, self::TOPICS))));
    }
}
