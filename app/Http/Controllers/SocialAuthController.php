<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Jobs\SyncSocialAccount;
use App\Services\Social\PlatformException;
use App\Services\Social\SocialManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    public function __construct(private SocialManager $social) {}

    public function redirect(Request $request, Platform $platform): RedirectResponse
    {
        $driver = $this->social->driver($platform);

        if (! $driver->configured()) {
            return to_route('accounts')->with('error', "{$platform->label()} is not available yet. The administrator needs to add its app keys in Admin → Settings.");
        }

        // Reconnecting an existing account is always allowed; the callback enforces the limit for new ones.
        if (! $request->boolean('reconnect') && $request->user()->remainingAccountSlots() === 0) {
            return to_route('accounts')->with('error', 'You have reached the number of social accounts your plan allows. Upgrade your plan to connect more.');
        }

        $state = Str::random(40);
        $request->session()->put('oauth_state', $state);

        return redirect()->away($driver->authorizationUrl($state, $this->callbackUrl($platform)));
    }

    public function callback(Request $request, Platform $platform): RedirectResponse
    {
        $expected = $request->session()->pull('oauth_state');

        if (! $expected || ! hash_equals($expected, (string) $request->input('state'))) {
            return to_route('accounts')->with('error', 'The connection request expired. Please try again.');
        }

        try {
            $accounts = $this->social->driver($platform)->handleCallback($request, $this->callbackUrl($platform));
        } catch (PlatformException $e) {
            report($e);

            return to_route('accounts')->with('error', $e->getMessage());
        }

        $user = $request->user();
        $slots = $user->remainingAccountSlots();
        $connected = [];
        $skipped = [];

        foreach ($accounts as $data) {
            $exists = $user->socialAccounts()
                ->where('platform', $data->platform)
                ->where('platform_user_id', $data->platformUserId)
                ->exists();

            // Reconnecting an existing account never counts against the plan limit.
            if (! $exists && $slots !== null) {
                if ($slots <= 0) {
                    $skipped[] = $data;

                    continue;
                }
                $slots--;
            }

            $connected[] = $data;

            $account = $user->socialAccounts()->updateOrCreate(
                ['platform' => $data->platform, 'platform_user_id' => $data->platformUserId],
                [
                    'name' => $data->name,
                    'username' => $data->username,
                    'avatar_url' => $data->avatarUrl,
                    'access_token' => $data->accessToken,
                    'refresh_token' => $data->refreshToken,
                    'token_expires_at' => $data->expiresAt,
                    'scopes' => $data->scopes,
                    'meta' => $data->meta,
                    'last_error' => null,
                ],
            );

            SyncSocialAccount::dispatch($account);
        }

        $names = fn (array $list) => collect($list)->map(fn ($a) => "{$a->name} ({$a->platform->label()})")->implode(', ');
        $redirect = to_route('accounts');

        if ($connected) {
            $redirect->with('status', 'Connected: '.$names($connected).'. Importing content now…');
        }

        if ($skipped) {
            $redirect->with('error', 'Not connected because of your plan limit: '.$names($skipped).'. Upgrade your plan to add them.');
        }

        return $redirect;
    }

    private function callbackUrl(Platform $platform): string
    {
        return route('accounts.callback', $platform);
    }
}
