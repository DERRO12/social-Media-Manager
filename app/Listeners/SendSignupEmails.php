<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewClientSignedUp;
use App\Notifications\Welcome;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Notification;

/** Welcome email for the new client and a heads-up for the admins. Never blocks sign-up if email fails. */
class SendSignupEmails
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->isAdmin()) {
            return;
        }

        rescue(fn () => $user->notify(new Welcome));
        rescue(fn () => Notification::send(User::where('is_admin', true)->get(), new NewClientSignedUp($user)));
    }
}
