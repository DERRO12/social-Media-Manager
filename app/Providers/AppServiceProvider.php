<?php

namespace App\Providers;

use App\Listeners\SendSignupEmails;
use App\Services\Mail\SiteMail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind an HTTPS tunnel or load balancer, trust its forwarded headers so Laravel knows the page is https.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Send the site's emails through the mailbox set in Admin → Settings → Email (e.g. Gmail + app password).
        SiteMail::apply();

        Event::listen(Registered::class, SendSignupEmails::class);
    }
}
