@php
    $app = config('app.name');
    $owner = \App\Models\Setting::get('legal.owner', $app);
    $email = \App\Models\Setting::get('legal.email');
    $country = \App\Models\Setting::get('legal.country');
    $contact = $email ? '<a href="mailto:'.e($email).'">'.e($email).'</a>' : 'the contact details on our website';
@endphp

<x-layouts.legal title="Privacy Policy" :updated="\App\Models\Setting::get('legal.updated', 'October 16, 2026')">
    <p>
        This Privacy Policy explains how {{ $owner }} (“we”, “us”) collects, uses, stores and deletes information when you use
        {{ $app }} at {{ url('/') }} (the “Service”). {{ $app }} lets you connect your social media accounts, publish and schedule posts,
        view your content and statistics, and read and reply to comments in one place.
    </p>

    <h2>1. Information we collect</h2>
    <h3>Information you give us</h3>
    <ul>
        <li><strong>Account details:</strong> your name, email address and password (stored only as a secure hash), and your timezone.</li>
        <li><strong>Content you create:</strong> videos you upload, titles, captions, schedules and replies you write in the Service.</li>
        <li><strong>Billing details:</strong> your plan and subscription status. If you pay by card, payment is handled by Stripe; we never see or store your card number.</li>
    </ul>

    <h3>Information from social platforms you connect</h3>
    <p>
        When you click “Connect” you sign in on the platform’s own page (for example Google/YouTube, Facebook, Instagram, TikTok, LinkedIn or X)
        and choose to grant {{ $app }} access. <strong>We never see or store your social media passwords.</strong> Depending on the platform and the
        permissions you grant, we receive:
    </p>
    <ul>
        <li><strong>Access tokens</strong> that let the Service act on your behalf. They are stored encrypted.</li>
        <li><strong>Profile information:</strong> account or channel ID, name, username and profile picture.</li>
        <li><strong>Your content and statistics:</strong> titles, descriptions, thumbnails, links, publish dates, and counts of views, likes, comments and shares.</li>
        <li><strong>Comments on your content:</strong> the comment text, the commenter’s public display name and profile picture, and the time it was posted.</li>
    </ul>

    <h3>Information collected automatically</h3>
    <ul>
        <li>Essential cookies that keep you logged in and protect forms from abuse. We do not use advertising cookies.</li>
        <li>Basic technical logs (such as error messages) to keep the Service running and secure.</li>
    </ul>

    <h2>2. How we use your information</h2>
    <p>We use your information only to provide the Service to you:</p>
    <ul>
        <li>to publish and schedule the posts you create to the accounts you choose;</li>
        <li>to show your content, statistics and comments in your dashboard and inbox, and to post the replies you write;</li>
        <li>to show you publicly trending videos on the Trending page;</li>
        <li>to manage your subscription and send you service emails (for example, that your trial is ending);</li>
        <li>to keep the Service secure and fix problems.</li>
    </ul>
    <p>
        <strong>We do not sell your information, we do not use it for advertising, and we do not share it with anyone except as described in this policy.</strong>
        We never post anything to your accounts unless you ask us to.
    </p>

    <h2 id="google">3. YouTube API Services and Google user data</h2>
    <p>
        {{ $app }} uses <strong>YouTube API Services</strong> to upload videos you choose, show your channel’s videos and statistics, read and reply to
        comments on your videos, and show trending videos. By connecting a YouTube account you agree to be bound by the
        <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a>.
        Google’s use of your information is described in the
        <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google Privacy Policy</a>.
    </p>
    <p>
        {{ $app }}’s use and transfer of information received from Google APIs adheres to the
        <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>,
        including the Limited Use requirements. We do not use Google user data for advertising, we do not sell it, and no person at {{ $owner }}
        reads it except with your permission, for security reasons, or where the law requires.
    </p>
    <p>
        You can remove {{ $app }}’s access to your Google account at any time: click <strong>Disconnect</strong> on the Connected accounts page
        (this also revokes our access at Google), or visit the
        <a href="https://security.google.com/settings/security/permissions" target="_blank" rel="noopener">Google security settings page</a>.
    </p>

    <h2>4. Other platforms</h2>
    <p>When you connect other platforms, their own terms and privacy policies also apply:</p>
    <ul>
        <li>Meta (Facebook and Instagram): <a href="https://www.facebook.com/privacy/policy" target="_blank" rel="noopener">Meta Privacy Policy</a></li>
        <li>TikTok: <a href="https://www.tiktok.com/legal/privacy-policy" target="_blank" rel="noopener">TikTok Privacy Policy</a></li>
        <li>LinkedIn: <a href="https://www.linkedin.com/legal/privacy-policy" target="_blank" rel="noopener">LinkedIn Privacy Policy</a></li>
        <li>X: <a href="https://x.com/en/privacy" target="_blank" rel="noopener">X Privacy Policy</a></li>
    </ul>
    <p>You can also remove access from each platform’s own security or app settings.</p>

    <h2>5. Who we share information with</h2>
    <p>We share information only with service providers we need to run the Service, and only for that purpose:</p>
    <ul>
        <li>the social platforms you connect, when you ask us to post, reply or load your content;</li>
        <li>our hosting provider, and Cloudflare, which delivers the website securely;</li>
        <li>Stripe, if you pay by card;</li>
        <li>an email provider, to send service emails.</li>
    </ul>
    <p>We may also disclose information if the law requires it, or to protect the rights and safety of our users and the Service.</p>

    <h2 id="data-deletion">6. How long we keep information, and how to delete it</h2>
    <ul>
        <li>Content, statistics and comments from your connected accounts are refreshed automatically while the account is connected, so stored copies stay up to date.</li>
        <li><strong>Disconnecting an account</strong> immediately deletes that account’s access tokens and all content, statistics and comments we imported from it, and revokes our access where the platform supports it (including Google).</li>
        <li><strong>Deleting your {{ $app }} account</strong> (Settings → Profile → Delete account) permanently deletes your profile, posts, uploaded videos, connected accounts and all imported data.</li>
        <li>Basic billing records may be kept for as long as the law requires.</li>
    </ul>
    <p>You can also ask us to delete your data, or for a copy of it, by contacting us at {!! $contact !!}.</p>

    <h2>7. Security</h2>
    <p>
        All connections to the Service use HTTPS. Passwords are stored as secure hashes, and social media access tokens are encrypted.
        Each client can only see their own workspace. No method of storage is 100% secure, but we work to protect your information.
    </p>

    <h2>8. Your rights</h2>
    <p>
        Depending on where you live, you may have the right to access, correct, delete or export your information, or to object to how we use it.
        Most of this you can do yourself in the Service; for anything else, contact us at {!! $contact !!}.
    </p>

    <h2>9. Children</h2>
    <p>The Service is not intended for children under 16, and we do not knowingly collect information from them.</p>

    <h2>10. Changes to this policy</h2>
    <p>If we make important changes, we will update the date at the top of this page and, where appropriate, tell you by email or in the Service.</p>

    <h2>11. Contact</h2>
    <p>
        {{ $owner }}@if ($country), {{ $country }}@endif.
        Questions about privacy: {!! $contact !!}.
    </p>
</x-layouts.legal>
