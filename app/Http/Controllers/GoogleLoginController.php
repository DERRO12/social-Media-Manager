<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\SocialManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Continue with Google" sign-in / sign-up. Uses the same Google Cloud OAuth client as the YouTube
 * connection, but only the basic openid/email/profile scopes, which need no Google verification.
 */
class GoogleLoginController extends Controller
{
    public static function enabled(): bool
    {
        $google = SocialManager::credentials('youtube');

        return filled($google['client_id'] ?? null) && filled($google['client_secret'] ?? null);
    }

    public static function callbackUrl(): string
    {
        return rtrim(config('app.url'), '/').route('google.callback', absolute: false);
    }

    public function redirect(Request $request): RedirectResponse
    {
        // Google returns to APP_URL; start there so the session (and its state value) survives the round trip.
        $appRoot = rtrim(config('app.url'), '/');
        if (strcasecmp($request->root(), $appRoot) !== 0) {
            return redirect()->away($appRoot.$request->getRequestUri());
        }

        if (! self::enabled()) {
            return $this->fail('Google sign-in is not available yet.');
        }

        $state = Str::random(40);
        $request->session()->put('google_login_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => SocialManager::credentials('youtube')['client_id'],
            'redirect_uri' => self::callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_login_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->fail('The sign-in request expired. Please try again.');
        }

        if ($request->filled('error')) {
            return $this->fail('Google sign-in was cancelled.');
        }

        try {
            $profile = $this->fetchProfile((string) $request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Google sign-in failed. Please try again.');
        }

        if (empty($profile['sub']) || empty($profile['email'])) {
            return $this->fail('Google did not share an email address for this account.');
        }

        $user = User::firstWhere('google_id', $profile['sub']);

        $verified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $user && ($existing = User::firstWhere('email', strtolower($profile['email'])))) {
            // Only link to an existing account when Google has verified the address, so nobody can take over
            // an account by signing in with an unverified Google email that matches it.
            if (! $verified) {
                return $this->fail('Please verify this email address with Google first, or log in with your password.');
            }

            $user = $existing;
        }

        $isNew = ! $user;

        if (! $user) {
            $user = User::create([
                'name' => $profile['name'] ?? Str::before($profile['email'], '@'),
                'email' => strtolower($profile['email']),
                // They sign in with Google; a random password still lets them use "Forgot password" later.
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $user->forceFill([
            'google_id' => $profile['sub'],
            'avatar_url' => $profile['picture'] ?? $user->avatar_url,
            'email_verified_at' => $user->email_verified_at ?? ($verified ? now() : null),
        ])->save();

        if ($isNew) {
            event(new \Illuminate\Auth\Events\Registered($user));
        }

        if ($user->isSuspended()) {
            return $this->fail('Your account has been suspended. Please contact support.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended($user->isAdmin() ? route('admin.overview') : route('dashboard'));
    }

    /** @return array{sub?: string, email?: string, email_verified?: bool, name?: string, picture?: string} */
    private function fetchProfile(string $code): array
    {
        $google = SocialManager::credentials('youtube');

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $google['client_id'],
            'client_secret' => $google['client_secret'],
            'redirect_uri' => self::callbackUrl(),
            'grant_type' => 'authorization_code',
        ])->throw()->json('access_token');

        return Http::withToken($token)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
    }

    private function fail(string $message): RedirectResponse
    {
        return to_route('login')->with('google_error', $message);
    }
}
