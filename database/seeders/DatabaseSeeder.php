<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local demo data. Every demo login uses the password "password".
     *
     *   admin@socialhub.test   – platform admin (sees all clients)
     *   amara@socialhub.test   – client with sample content and comments
     *   kofi@socialhub.test    – second client on a 5-day trial, to check clients can't see each other's data
     *   zara@socialhub.test    – client whose trial has ended (sees the "choose a plan" page)
     *
     * The social accounts are fake (no real tokens), so "Sync" and publishing will fail on them.
     */
    public function run(): void
    {
        Setting::put('billing.trial_days', 14);
        Setting::put('billing.support_contact', 'WhatsApp +000 00 000 0000 or billing@socialhub.test');

        $starter = Plan::create(['name' => 'Starter', 'description' => 'For solo creators', 'price' => 15, 'max_social_accounts' => 3, 'max_posts_per_month' => 30, 'sort' => 1]);
        $pro = Plan::create(['name' => 'Pro', 'description' => 'For growing brands', 'price' => 39, 'max_social_accounts' => 10, 'max_posts_per_month' => 200, 'sort' => 2]);
        Plan::create(['name' => 'Agency', 'description' => 'Unlimited everything', 'price' => 99, 'sort' => 3]);
        Setting::put('billing.trial_plan_id', $pro->id);

        $admin = User::factory()->create(['name' => 'Platform Admin', 'email' => 'admin@socialhub.test']);
        $admin->forceFill(['is_admin' => true])->save();
        $admin->subscription()->delete(); // admins aren't billed

        $billing = app(SubscriptionManager::class);

        $amara = $this->demoClient('Amara Studio', 'amara@socialhub.test', [Platform::YouTube, Platform::Instagram, Platform::TikTok]);
        $billing->activateManually($amara, $pro, now()->addMonth(), 'Paid 1 month by mobile money');

        $kofi = $this->demoClient('Kofi Fitness', 'kofi@socialhub.test', [Platform::Facebook, Platform::X]);
        $billing->startTrial($kofi, 5, $starter);

        // Trial already over: logging in as zara shows the "choose a plan" page.
        $zara = $this->demoClient('Zara Media', 'zara@socialhub.test', [Platform::LinkedIn]);
        $billing->record($zara, ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->subDay(), 'plan_id' => $starter->id]);
    }

    private function demoClient(string $name, string $email, array $platforms): User
    {
        $client = User::factory()->create(['name' => $name, 'email' => $email]);

        foreach ($platforms as $platform) {
            $account = $client->socialAccounts()->create([
                'platform' => $platform,
                'platform_user_id' => fake()->uuid(),
                'name' => "{$name} {$platform->label()}",
                'username' => '@'.str($name)->slug(''),
                'access_token' => 'demo-token',
                'last_synced_at' => now()->subMinutes(rand(2, 50)),
            ]);

            $this->demoContent($account);
        }

        $post = $client->posts()->create([
            'title' => 'Behind the scenes',
            'caption' => 'A look at how we made this week’s video #bts',
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->addDays(2),
        ]);

        $client->socialAccounts->each(fn ($account) => $post->targets()->create(['social_account_id' => $account->id]));

        return $client;
    }

    private function demoContent(SocialAccount $account): void
    {
        foreach (range(1, 4) as $i) {
            $item = $account->contentItems()->create([
                'platform_content_id' => fake()->uuid(),
                'type' => in_array($account->platform, [Platform::Facebook, Platform::X]) ? 'post' : 'video',
                'title' => fake()->sentence(5),
                'caption' => fake()->paragraph(),
                'permalink' => 'https://example.com',
                'published_at' => now()->subDays($i * 3),
                'views' => $account->platform === Platform::Instagram ? null : rand(500, 90000),
                'likes' => rand(20, 5000),
                'comments_count' => rand(2, 300),
                'shares' => rand(0, 400),
            ]);

            if ($account->platform === Platform::TikTok) {
                continue; // TikTok doesn't expose comment text.
            }

            foreach (range(1, rand(2, 4)) as $c) {
                $item->comments()->create([
                    'social_account_id' => $account->id,
                    'platform_comment_id' => fake()->uuid(),
                    'author_name' => fake()->name(),
                    'body' => fake()->randomElement([
                        'This is amazing! 🔥',
                        'Where did you get that jacket?',
                        'Can you do a tutorial on this?',
                        'Been waiting for this one, thank you!',
                        'What camera do you use?',
                    ]),
                    'likes' => rand(0, 40),
                    'commented_at' => now()->subHours(rand(1, 200)),
                    'read_at' => $c === 1 ? now() : null,
                ]);
            }
        }
    }
}
