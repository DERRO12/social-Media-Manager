<?php

use App\Http\Controllers\AcademyPublicController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\GoogleLoginController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// "Continue with Google" sign-in / sign-up.
Route::middleware('guest')->group(function () {
    Route::get('auth/google', [GoogleLoginController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleLoginController::class, 'callback'])->name('google.callback');
});

// Public legal pages (required by Google, Meta and TikTok app reviews).
Route::view('privacy', 'legal.privacy')->name('privacy');
Route::view('terms', 'legal.terms')->name('terms');

// Public Academy pages: course catalog, course outlines with free preview lessons, certificate verification.
Route::get('courses', [AcademyPublicController::class, 'index'])->name('courses.index');
Route::get('courses/{course:slug}', [AcademyPublicController::class, 'show'])->name('courses.show');
Route::get('certificates/{certificate:code}', [AcademyPublicController::class, 'certificate'])->name('certificates.show');

// Search visibility: clients' public pages, a page per video and per photo, the sitemap, robots.txt and the IndexNow key.
Route::get('p/{profile:handle}', [PublicProfileController::class, 'show'])->name('profiles.show');
Route::get('p/{profile:handle}/v/{item}/{slug?}', [PublicProfileController::class, 'item'])->name('profiles.item');
Route::get('p/{profile:handle}/photos/{photo}/{slug?}', [PublicProfileController::class, 'photo'])->name('profiles.photo');
Route::get('sitemap.xml', [PublicProfileController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [PublicProfileController::class, 'robots'])->name('robots');
Route::get('indexnow.txt', [PublicProfileController::class, 'indexNowKey'])->name('indexnow');

// Email marketing, public side: sign-up form, unsubscribe (also one-click from mail apps) and open tracking.
Route::post('p/{profile:handle}/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:10,1')->name('newsletter.subscribe');
Route::get('unsubscribe/{token}', [NewsletterController::class, 'unsubscribePage'])->name('email.unsubscribe');
Route::post('unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:30,1')->name('email.unsubscribe.confirm');
Route::post('unsubscribe/{token}/resubscribe', [NewsletterController::class, 'resubscribe'])->middleware('throttle:10,1')->name('email.resubscribe');
Route::get('e/o/{send}', [NewsletterController::class, 'open'])->middleware('signed')->name('email.open');

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

    // Grow on Google: Business Profile builder, SEO checker, public page settings.
    Route::redirect('grow', 'grow/business-profile')->name('grow');
    Volt::route('grow/business-profile', 'grow.business')->name('grow.business');
    Volt::route('grow/seo', 'grow.seo')->name('grow.seo');
    Volt::route('grow/public-page', 'grow.page')->name('grow.page');
    Volt::route('grow/photos', 'grow.photos')->name('grow.photos');

    Volt::route('email', 'email.campaigns')->name('email.campaigns');
    Volt::route('email/campaigns/{campaign}', 'email.campaign')->name('email.campaign');
    Volt::route('email/contacts', 'email.contacts')->name('email.contacts');
    Volt::route('email/settings', 'email.settings')->name('email.settings');

    // Academy (studying needs a trial or plan; the public pages above don't).
    Volt::route('academy', 'academy.index')->name('academy');
    Volt::route('academy/certificates', 'academy.certificates')->name('academy.certificates');
    Volt::route('academy/{course:slug}', 'academy.course')->name('academy.course');
    Volt::route('academy/{course:slug}/lessons/{lesson}', 'academy.lesson')->name('academy.lesson');
    Volt::route('academy/{course:slug}/quiz', 'academy.quiz')->name('academy.quiz');

    Route::post('uploads/chunk', [ChunkedUploadController::class, 'chunk'])->name('uploads.chunk');
    Route::post('uploads/complete', [ChunkedUploadController::class, 'complete'])->name('uploads.complete');

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
    Volt::route('courses', 'admin.courses')->name('courses');
    Volt::route('courses/{course}', 'admin.course')->name('course');
    Volt::route('certificates', 'admin.certificates')->name('certificates');
    Volt::route('payments', 'admin.payments')->name('payments');
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
