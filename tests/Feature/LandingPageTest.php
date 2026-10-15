<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_landing_page_with_live_plans_and_trial(): void
    {
        Setting::put('billing.trial_days', 30);
        Plan::create(['name' => 'Creator', 'price' => 12, 'max_social_accounts' => 4]);
        Plan::create(['name' => 'Hidden plan', 'price' => 1, 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Post everywhere.')
            ->assertSee('Start your 30-day free trial')
            ->assertSee('Creator')
            ->assertSee('4 social accounts')
            ->assertDontSee('Hidden plan')
            ->assertDontSee('Laravel');
    }

    public function test_signed_in_clients_are_offered_their_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Go to your dashboard');
    }
}
