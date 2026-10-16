@php
    $app = config('app.name');
    $owner = \App\Models\Setting::get('legal.owner', $app);
    $email = \App\Models\Setting::get('legal.email');
    $country = \App\Models\Setting::get('legal.country');
    $contact = $email ? '<a href="mailto:'.e($email).'">'.e($email).'</a>' : 'the contact details on our website';
@endphp

<x-layouts.legal title="Terms of Service" :updated="\App\Models\Setting::get('legal.updated', 'October 16, 2026')">
    <p>
        These Terms of Service (“Terms”) are an agreement between you and {{ $owner }} (“we”, “us”) for your use of {{ $app }} at {{ url('/') }}
        (the “Service”). By creating an account or using the Service you agree to these Terms and to our
        <a href="{{ route('privacy') }}">Privacy Policy</a>.
    </p>

    <h2>1. The Service</h2>
    <p>
        {{ $app }} lets you connect social media accounts, publish and schedule posts and videos, see your content and statistics,
        read and reply to comments, and view trending content. Features depend on what each platform allows through its official API,
        and platforms can change or limit their APIs at any time.
    </p>

    <h2>2. Your account</h2>
    <ul>
        <li>You must give accurate information and keep your password safe. You are responsible for activity on your account.</li>
        <li>You must be at least 16 years old, and old enough to agree to these Terms where you live.</li>
        <li>If you use the Service for a business or a client, you confirm you are allowed to act for them.</li>
    </ul>

    <h2>3. Connected platforms</h2>
    <p>
        You may only connect accounts you own or are authorised to manage. When you use the Service with a platform you must also follow
        that platform’s terms and rules, including the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a>
        and the terms of Meta, TikTok, LinkedIn and X. You can disconnect any account at any time.
    </p>

    <h2>4. Your content</h2>
    <ul>
        <li>You keep all rights to the content you upload or create. You give us permission to store, process and transmit it only as needed to provide the Service, for example to publish it to the accounts you choose.</li>
        <li>You are responsible for your content and confirm you have the rights to publish it, including any music, images and people shown in it.</li>
        <li>We do not post to your accounts unless you ask us to.</li>
    </ul>

    <h2>5. Acceptable use</h2>
    <p>You agree not to use the Service to:</p>
    <ul>
        <li>post spam, misleading, illegal, hateful or infringing content, or content that breaks a platform’s rules;</li>
        <li>automate fake engagement, or access accounts you are not authorised to manage;</li>
        <li>try to break, overload, reverse engineer or get around the security or limits of the Service or any platform;</li>
        <li>access another customer’s workspace or data.</li>
    </ul>
    <p>We may suspend or close accounts that break these rules or put the Service or other users at risk.</p>

    <h2>6. Plans, trials and payment</h2>
    <ul>
        <li>New accounts may get a free trial. When it ends, you need an active plan to keep using the workspace; your data is kept for you.</li>
        <li>Plans renew for the period shown until cancelled. You can cancel at any time; access continues until the end of the paid period.</li>
        <li>Prices and plan limits are shown on the website and may change; changes apply from your next billing period.</li>
        <li>Except where the law requires otherwise, payments are not refundable for periods already started.</li>
    </ul>

    <h2>7. Availability</h2>
    <p>
        We work to keep the Service running, but it is provided “as is” and “as available”. We cannot guarantee it will always be available or
        error-free, or that every post will be accepted by every platform, since platforms control their own systems.
    </p>

    <h2>8. Limitation of liability</h2>
    <p>
        To the extent the law allows, we are not liable for indirect or consequential losses, lost profits, lost followers or lost data,
        and our total liability for any claim is limited to the amount you paid us for the Service in the 3 months before the claim.
    </p>

    <h2>9. Ending your use</h2>
    <p>
        You can stop using the Service and delete your account at any time in Settings. We may suspend or end your access if you break these Terms,
        if required by law, or if a platform requires us to. When an account is deleted, its data is deleted as described in the Privacy Policy.
    </p>

    <h2>10. Changes to these Terms</h2>
    <p>We may update these Terms. If the changes are important we will tell you in advance by email or in the Service. Continuing to use the Service means you accept the updated Terms.</p>

    <h2>11. Governing law</h2>
    <p>These Terms are governed by the laws of {{ $country ?: 'the country where '.$owner.' is based' }}.</p>

    <h2>12. Contact</h2>
    <p>Questions about these Terms: {!! $contact !!}.</p>
</x-layouts.legal>
