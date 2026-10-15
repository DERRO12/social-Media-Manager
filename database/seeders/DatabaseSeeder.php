<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Real starting data only: default plans and trial settings (edit them in Admin → Plans / Settings).
     * No fake users or accounts. Create your admin with: php artisan app:make-admin you@example.com
     * For fake demo data on a local machine, use: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        if (Setting::get('billing.trial_days') === null) {
            Setting::put('billing.trial_days', 14);
        }

        if (Plan::exists()) {
            return;
        }

        Plan::create(['name' => 'Starter', 'description' => 'For solo creators', 'price' => 15, 'max_social_accounts' => 3, 'max_posts_per_month' => 30, 'sort' => 1]);
        $pro = Plan::create(['name' => 'Pro', 'description' => 'For growing brands', 'price' => 39, 'max_social_accounts' => 10, 'max_posts_per_month' => 200, 'sort' => 2]);
        Plan::create(['name' => 'Agency', 'description' => 'Unlimited everything', 'price' => 99, 'sort' => 3]);

        Setting::put('billing.trial_plan_id', $pro->id);
    }
}
