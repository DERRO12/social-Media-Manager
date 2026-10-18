<?php

namespace App\Notifications;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Congratulates a student and links to their certificate. */
class CertificateEarned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Certificate $certificate) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your certificate: {$this->certificate->course_title}")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("You passed {$this->certificate->course_title}. Your certificate is ready to download, print and share.")
            ->action('View certificate', route('certificates.show', $this->certificate))
            ->line('Add it to your LinkedIn profile from the certificate page. Certificate ID: '.$this->certificate->code);
    }
}
