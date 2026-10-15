<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithComment(string $name, string $comment): User
    {
        $user = User::factory()->create(['name' => $name]);

        $account = $user->socialAccounts()->create([
            'platform' => Platform::YouTube,
            'platform_user_id' => fake()->uuid(),
            'name' => "{$name} channel",
            'access_token' => 'token',
        ]);

        $item = $account->contentItems()->create(['platform_content_id' => fake()->uuid(), 'title' => "{$name} video"]);
        $item->comments()->create([
            'social_account_id' => $account->id,
            'platform_comment_id' => fake()->uuid(),
            'body' => $comment,
        ]);

        return $user;
    }

    public function test_clients_only_see_their_own_comments_and_content(): void
    {
        $alice = $this->clientWithComment('Alice', 'Comment for Alice');
        $this->clientWithComment('Bob', 'Comment for Bob');

        $this->actingAs($alice);

        $this->get('/inbox')->assertOk()->assertSee('Comment for Alice')->assertDontSee('Comment for Bob');
        $this->get('/content')->assertOk()->assertSee('Alice video')->assertDontSee('Bob video');
    }

    public function test_clients_cannot_act_on_another_clients_records(): void
    {
        $alice = $this->clientWithComment('Alice', 'a');
        $bob = $this->clientWithComment('Bob', 'b');
        $bobsAccount = $bob->socialAccounts()->first();

        $this->actingAs($alice);

        Volt::test('accounts.index')->call('disconnect', $bobsAccount->id)->assertNotFound();

        $this->assertModelExists($bobsAccount);
    }

    public function test_clients_cannot_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/clients')->assertForbidden();
    }

    public function test_admin_can_manage_a_client_workspace_and_return(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $client = $this->clientWithComment('Client', 'Hello from a fan');

        $this->actingAs($admin)->get('/admin/clients')->assertOk()->assertSee($client->email);

        $this->post("/admin/clients/{$client->id}/impersonate")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($client);
        $this->get('/inbox')->assertSee('Hello from a fan')->assertSee('Back to admin');

        $this->post('/impersonate/stop')->assertRedirect('/admin/clients');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_impersonate_stop_cannot_be_used_to_escalate(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->withSession(['impersonator_id' => $client->id])
            ->post('/impersonate/stop')->assertForbidden();
    }

    public function test_suspended_clients_are_logged_out(): void
    {
        $client = User::factory()->create(['suspended_at' => null]);
        $client->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($client)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $account = $this->clientWithComment('Alice', 'x')->socialAccounts()->first();

        $raw = SocialAccount::query()->toBase()->where('id', $account->id)->value('access_token');

        $this->assertNotSame('token', $raw);
        $this->assertSame('token', $account->fresh()->access_token);
    }
}
