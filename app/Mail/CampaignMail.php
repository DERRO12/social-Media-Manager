<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Models\Mailbox;
use App\Services\Mail\Smtp;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** A newsletter email to one contact, with an unsubscribe link (also as one-click headers, which Gmail requires). */
class CampaignMail extends Mailable
{
    public function __construct(
        public EmailCampaign $campaign,
        public Contact $contact,
        public Mailbox $mailbox,
        public ?EmailCampaignSend $send = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->mailbox->username, $this->mailbox->fromName()),
            replyTo: $this->mailbox->reply_to ? [new Address($this->mailbox->reply_to)] : [],
            subject: Smtp::personalise($this->campaign->subject, $this->contact->name),
        );
    }

    public function content(): Content
    {
        $body = Smtp::personalise((string) $this->campaign->body, $this->contact->name);
        $profile = $this->campaign->user->publicProfile;

        return new Content(
            view: 'mail.campaign',
            text: 'mail.campaign-text',
            with: [
                'html' => Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
                'text' => $body,
                'preheader' => $this->campaign->preheader,
                'sender' => $this->mailbox->fromName(),
                'profileUrl' => $profile?->is_public ? $profile->url() : null,
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'openUrl' => $this->send ? URL::signedRoute('email.open', ['send' => $this->send->id]) : null,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function unsubscribeUrl(): string
    {
        // Test emails go to the client, who isn't a contact.
        return $this->contact->exists ? $this->contact->unsubscribeUrl() : route('email.contacts');
    }
}
