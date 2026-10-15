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
            return to_route('accounts')->with('error', "{$platform->label()} is not set up yet. Add its app keys to the .env file.");
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

        foreach ($accounts as $data) {
            $account = $request->user()->socialAccounts()->updateOrCreate(
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

        $names = collect($accounts)->map(fn ($a) => "{$a->name} ({$a->platform->label()})")->implode(', ');

        return to_route('accounts')->with('status', "Connected: {$names}. Importing content now…");
    }

    private function callbackUrl(Platform $platform): string
    {
        return route('accounts.callback', $platform);
    }
}
