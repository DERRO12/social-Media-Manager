<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local demo data. Every demo login uses the password "password".
     *
     *   admin@socialhub.test   – platform admin (sees all clients)
     *   amara@socialhub.test   – client with sample content and comments
     *   kofi@socialhub.test    – second client, to check clients can't see each other's data
     *
     * The social accounts are fake (no real tokens), so "Sync" and publishing will fail on them.
     */
    public function run(): void
    {
        $admin = User::factory()->create(['name' => 'Platform Admin', 'email' => 'admin@socialhub.test']);
        $admin->forceFill(['is_admin' => true])->save();

        $this->demoClient('Amara Studio', 'amara@socialhub.test', [Platform::YouTube, Platform::Instagram, Platform::TikTok]);
        $this->demoClient('Kofi Fitness', 'kofi@socialhub.test', [Platform::Facebook, Platform::X]);
    }

    private function demoClient(string $name, string $email, array $platforms): void
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
