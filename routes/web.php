<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Public legal pages (required by Google, Meta and TikTok app reviews).
Route::view('privacy', 'legal.privacy')->name('privacy');
Route::view('terms', 'legal.terms')->name('terms');

// Client workspace: every page only shows the signed-in client's own data,
// and needs a running trial or an active plan ("has-access").
Route::middleware(['auth', 'verified', 'not-suspended', 'has-access'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');
    Volt::route('accounts', 'accounts.index')->name('accounts');
    Volt::route('compose', 'posts.compose')->name('compose');
    Volt::route('posts', 'posts.index')->name('posts');
    Volt::route('content', 'content.index')->name('content');
    Volt::route('inbox', 'inbox.index')->name('inbox');
    Volt::route('trending', 'trending.index')->name('trending');

    Route::get('accounts/connect/{platform}', [SocialAuthController::class, 'redirect'])->name('accounts.connect');
    Route::get('accounts/callback/{platform}', [SocialAuthController::class, 'callback'])->name('accounts.callback');
});

// Billing stays reachable when the trial/plan has run out, so the client can pay.
Route::middleware(['auth', 'verified', 'not-suspended'])->group(function () {
    Volt::route('billing', 'billing.index')->name('billing');
    Route::post('billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::get('billing/success', [BillingController::class, 'success'])->name('billing.success');
});

Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Platform admin: manage every client, plan and setting.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('/', 'admin.overview')->name('overview');
    Volt::route('clients', 'admin.clients')->name('clients');
    Volt::route('clients/{user}', 'admin.client')->name('client');
    Volt::route('plans', 'admin.plans')->name('plans');
    Volt::route('settings', 'admin.settings')->name('settings');
    Route::post('clients/{user}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate');
});

Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonate.stop');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
