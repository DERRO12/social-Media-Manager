<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost:8000']);
        Setting::put('youtube.client_id', 'google-client');
        Setting::put('youtube.client_secret', 'google-secret', encrypt: true);
    }

    private function fakeGoogle(array $profile): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response($profile),
        ]);
    }

    /** Start the flow, then come back from Google with a valid state. */
    private function signInWithGoogle(): \Illuminate\Testing\TestResponse
    {
        $location = $this->get('http://localhost:8000/auth/google')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('http://localhost:8000/auth/google/callback', $query['redirect_uri']);

        return $this->get('http://localhost:8000/auth/google/callback?code=abc&state='.$query['state']);
    }

    public function test_buttons_show_on_login_and_sign_up_when_google_is_configured(): void
    {
        $this->get('/login')->assertSee('Continue with Google');
        $this->get('/register')->assertSee('Sign up with Google');
    }

    public function test_buttons_are_hidden_when_google_is_not_configured(): void
    {
        Setting::put('youtube.client_id', null);
        config(['services.youtube.client_id' => null]);

        $this->get('/login')->assertDontSee('Continue with Google');
    }

    public function test_new_person_gets_an_account_with_a_trial(): void
    {
        $this->fakeGoogle(['sub' => 'g-1', 'email' => 'Ama@Gmail.com', 'email_verified' => true, 'name' => 'Ama K', 'picture' => 'https://lh3.googleusercontent.com/a/x']);

        $this->signInWithGoogle()->assertRedirect(route('dashboard'));

        $user = User::firstWhere('email', 'ama@gmail.com');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-1', $user->google_id);
        $this->assertSame('Ama K', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->subscription->onTrial());
    }

    public function test_existing_account_with_the_same_verified_email_is_linked(): void
    {
        $existing = User::factory()->create(['email' => 'kofi@gmail.com']);
        $this->fakeGoogle(['sub' => 'g-2', 'email' => 'kofi@gmail.com', 'email_verified' => true, 'name' => 'Kofi']);

        $this->signInWithGoogle()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('g-2', $existing->fresh()->google_id);
        $this->assertSame(1, User::count());
    }

    public function test_unverified_google_email_cannot_take_over_an_existing_account(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);
        $this->fakeGoogle(['sub' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => false]);

        $this->signInWithGoogle()->assertRedirect(route('login'))->assertSessionHas('google_error');

        $this->assertGuest();
    }

    public function test_suspended_clients_cannot_sign_in_with_google(): void
    {
        $user = User::factory()->create(['email' => 'sus@gmail.com']);
        $user->forceFill(['suspended_at' => now(), 'google_id' => 'g-3'])->save();
        $this->fakeGoogle(['sub' => 'g-3', 'email' => 'sus@gmail.com', 'email_verified' => true]);

        $this->signInWithGoogle()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_admins_land_on_the_admin_overview(): void
    {
        $admin = User::factory()->create(['email' => 'boss@gmail.com']);
        $admin->forceFill(['is_admin' => true])->save();
        $this->fakeGoogle(['sub' => 'g-4', 'email' => 'boss@gmail.com', 'email_verified' => true]);

        $this->signInWithGoogle()->assertRedirect(route('admin.overview'));
    }

    public function test_a_forged_state_is_rejected(): void
    {
        Http::fake();

        $this->get('http://localhost:8000/auth/google/callback?code=abc&state=forged')
            ->assertRedirect(route('login'))
            ->assertSessionHas('google_error');

        $this->assertGuest();
        Http::assertNothingSent();
    }
}
