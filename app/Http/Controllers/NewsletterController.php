<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\EmailCampaignSend;
use App\Models\PublicProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The public side of email marketing: sign-up forms, unsubscribing and open tracking. */
class NewsletterController extends Controller
{
    /** Join a client's mailing list from their public page. */
    public function subscribe(Request $request, PublicProfile $profile): RedirectResponse
    {
        abort_unless($profile->newsletter_enabled && PublicProfileController::isLive($profile), 404);

        // Bots fill in every field, including this hidden one.
        if (filled($request->input('company'))) {
            return back()->with('newsletter', 'Thanks! You’re on the list.');
        }

        $data = $request->validateWithBag('newsletter', [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please tick the box to agree to receive emails.']);

        $contact = $profile->user->contacts()->firstOrNew(['email' => strtolower(trim($data['email']))]);

        if (! $contact->exists && $profile->user->contacts()->count() >= Contact::LIMIT) {
            return back()->with('newsletter', 'Thanks! You’re on the list.'); // the owner sees the limit in their dashboard
        }

        $phone = \App\Services\Sms\Phone::normalise($data['phone'] ?? null);
        if ($phone && $profile->user->contacts()->where('phone', $phone)->whereKeyNot($contact->id)->exists()) {
            $phone = null; // that number already belongs to another contact
        }

        $contact->fill([
            'name' => ($data['name'] ?? null) ?: $contact->name,
            'phone' => $phone ?: $contact->phone,
            'sms_status' => $phone ? Contact::SUBSCRIBED : $contact->sms_status,
            'source' => $contact->exists ? $contact->source : 'form',
            'status' => Contact::SUBSCRIBED,
            'unsubscribed_at' => null,
        ]);
        if ($contact->isDirty('status')) {
            $contact->subscribed_at = now();
        }
        $contact->save();

        return back()->with('newsletter', 'Thanks! You’re on the list.');
    }

    public function unsubscribePage(string $token): View
    {
        $contact = Contact::where('token', $token)->with('user.publicProfile')->firstOrFail();

        return view('newsletter.unsubscribe', ['contact' => $contact, 'sender' => self::senderName($contact)]);
    }

    /** From the page's button, or a mail app's one-click "Unsubscribe" (RFC 8058). */
    public function unsubscribe(string $token): View|Response
    {
        $contact = Contact::where('token', $token)->with('user.publicProfile')->firstOrFail();
        $contact->status === Contact::UNSUBSCRIBED || $contact->unsubscribe();

        return view('newsletter.unsubscribe', ['contact' => $contact, 'sender' => self::senderName($contact), 'done' => true]);
    }

    /** Resubscribe, if someone clicked unsubscribe by mistake. */
    public function resubscribe(string $token): View
    {
        $contact = Contact::where('token', $token)->with('user.publicProfile')->firstOrFail();
        $contact->update(['status' => Contact::SUBSCRIBED, 'unsubscribed_at' => null]);

        return view('newsletter.unsubscribe', ['contact' => $contact, 'sender' => self::senderName($contact), 'back' => true]);
    }

    /** The short link at the end of every SMS: stops SMS from this sender (email stays as it was). */
    public function smsOptOut(string $code): View
    {
        [$id, $prefix] = array_pad(explode('-', $code, 2), 2, '');
        $contact = Contact::with('user.publicProfile')->find((int) $id);
        abort_unless($contact && strlen($prefix) === 8 && hash_equals(substr($contact->token, 0, 8), $prefix), 404);

        $contact->sms_status === Contact::UNSUBSCRIBED || $contact->unsubscribeSms();

        return view('newsletter.unsubscribe', ['contact' => $contact, 'sender' => self::senderName($contact), 'done' => true, 'sms' => true]);
    }

    /** 1×1 image in each email: records the first open. The link is signed so counts can't be faked. */
    public function open(EmailCampaignSend $send): Response
    {
        if (! $send->opened_at) {
            $send->update(['opened_at' => now()]);
            $send->campaign()->increment('opens');
        }

        return response(base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    private static function senderName(Contact $contact): string
    {
        return $contact->user->mailbox?->fromName() ?? $contact->user->publicProfile?->name() ?? $contact->user->name;
    }
}
