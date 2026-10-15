# SocialHub

A multi-client social media management SaaS built with Laravel 12, Livewire 4 (Volt) and Flux.

Each **client** signs up, gets a free trial, connects their own social accounts and gets their own private workspace.
The **platform admin** sees every client, controls plans, trials and payments, can add / suspend clients, and can open any client's workspace ("Manage").

## Subscriptions (controlled by the admin)

| Where | What the admin can do |
|---|---|
| **Admin → Plans** | Create plans: price, currency, monthly/yearly, max social accounts, max posts per month (empty = unlimited), optional Stripe Price ID, show/hide |
| **Admin → Settings** | Default free trial length for new sign-ups (0 = no trial), which plan's limits apply during trials, how clients can pay you manually, Stripe keys, and the social platform app keys |
| **Admin → Clients → Open** | Give a trial of **any number of days**, extend a running trial, activate a plan by hand until a date (bank transfer, mobile money, cash, deals) or with no end date, end access now, private notes, suspend |
| **Admin → Overview** | Clients on trial, paying clients, expired, estimated monthly revenue, trials ending in the next 7 days |

How it behaves:

- New clients (self sign-up or added by the admin) start the trial set in Settings automatically.
- When a trial or plan ends, the client can only open **Plan & billing** until they pay or the admin re-activates them.
  Their scheduled posts are not published while inactive. Their data is kept.
- Plan limits are enforced: connecting more accounts than the plan allows and publishing beyond the monthly post limit are blocked (drafts are always allowed; reconnecting an existing account never counts).
- Clients see a "trial ends in N days" banner and get an email 3 days before the trial ends.
- **Online card payments are optional.** Add Stripe keys in Admin → Settings and a Stripe Price ID on each plan,
  then point a Stripe webhook at `APP_URL/stripe/webhook` (the exact URL and events are shown on the Settings page).
  Clients then pay with Stripe Checkout and manage cards/invoices in the Stripe portal. Without Stripe, clients see your manual payment contact instead.

## What a client can do

| Page | What it does |
|---|---|
| **Dashboard** | Totals across all their accounts: views, likes, comments, unread comments, upcoming posts |
| **Create post** | Upload one video, pick accounts, write a caption, post now / schedule / save draft |
| **Scheduled & sent** | Every post with its status on each platform, links to the live post, retry failed |
| **All content** | Every video/post on their connected accounts (also ones posted outside the app) with metrics |
| **Inbox** | Comments from all platforms in one feed: reply, mark read, filter by platform or post |
| **Connected accounts** | Step-by-step guide; connect / reconnect / sync / disconnect YouTube, Facebook Pages, Instagram, TikTok, LinkedIn, X |
| **Plan & billing** | Current plan, trial days left, usage against limits, subscribe / switch plan |

Clients never type their social passwords into this app. They click **Connect**, log in on the platform's own page
(Google, Facebook, TikTok, …) and approve access; the platform hands back a secure access token, stored encrypted.
That is the only method the platforms allow.

## What each platform supports

| Platform | Post video | Content + metrics | Read comments | Reply |
|---|---|---|---|---|
| YouTube | ✅ | ✅ | ✅ | ✅ |
| Facebook Pages | ✅ | ✅ | ✅ | ✅ |
| Instagram (Business/Creator) | ✅ Reels | ✅ | ✅ | ✅ |
| TikTok | ✅ | ✅ counts only | ❌ not offered by TikTok's API | ❌ |
| LinkedIn (profile) | ✅ | needs Community Management approval | needs approval | needs approval |
| X | ✅ | ✅ | ✅ | ✅ (all need a paid X API tier) |

## Running locally (XAMPP)

Start MySQL in the XAMPP control panel, then in this folder:

```bash
composer install
npm install
npm run build
php artisan migrate --seed
```

Run these three processes (three terminals):

```bash
php artisan serve
```

```bash
php artisan queue:work --timeout=3600
```

```bash
php artisan schedule:work
```

- `queue:work` does the publishing, syncing and uploads in the background.
- `schedule:work` publishes scheduled posts every minute, syncs all accounts every 15 minutes,
  and every hour expires finished trials and sends trial-ending emails (`billing:maintain`).

Open http://localhost:8000. Demo logins (password `password`, created by the seeder):

- `admin@socialhub.test` — platform admin
- `amara@socialhub.test` — client on the Pro plan (activated manually)
- `kofi@socialhub.test` — client on a 5-day trial
- `zara@socialhub.test` — client whose trial has ended (sees the "choose a plan" page)

> The demo accounts have fake tokens, so Sync/Publish fail on them. Connect real accounts to test for real.

Create your real admin account with:

```bash
php artisan app:make-admin you@yourdomain.com
```

## Connecting the platforms

Create one developer app per platform (owned by you, the SaaS, not by each client). Paste its keys in
**Admin → Settings** (stored encrypted; the page also shows the exact callback URL to copy), or put them in `.env`.
Until a platform has keys, clients see it as "Coming soon".
The **redirect / callback URL** for each is `APP_URL/accounts/callback/{platform}`:

| Platform | Where | `.env` keys | Callback path |
|---|---|---|---|
| YouTube | [Google Cloud Console](https://console.cloud.google.com/) → enable *YouTube Data API v3* → OAuth client (Web) | `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET` | `/accounts/callback/youtube` |
| Facebook + Instagram | [Meta for Developers](https://developers.facebook.com/) → Business app → Facebook Login for Business | `META_APP_ID`, `META_APP_SECRET` | `/accounts/callback/facebook` and `/accounts/callback/instagram` |
| TikTok | [TikTok for Developers](https://developers.tiktok.com/) → Login Kit + Content Posting API | `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | `/accounts/callback/tiktok` |
| LinkedIn | [LinkedIn Developers](https://www.linkedin.com/developers/) → *Share on LinkedIn* + *Sign In with OpenID Connect* | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | `/accounts/callback/linkedin` |
| X | [X Developer Portal](https://developer.x.com/) → OAuth 2.0, type *Web App* | `X_CLIENT_ID`, `X_CLIENT_SECRET` | `/accounts/callback/x` |

After editing `.env`, run `php artisan config:clear`.

**App review:** while in development mode, each platform only lets *you* (and listed test users) connect.
Before real clients can use it you must pass each platform's review (Google OAuth verification, Meta App Review,
TikTok audit, LinkedIn product approval). Plan for this early. It usually takes longer than the coding.

## Going live checklist

- **Public HTTPS domain.** OAuth callbacks need it, and **Instagram downloads videos from your server's public URL**,
  so Instagram publishing won't work from `localhost` (use a real domain or a tunnel like ngrok while testing).
- **Bigger uploads.** Raise `upload_max_filesize` and `post_max_size` in `php.ini` (XAMPP defaults to 40 MB).
  The app accepts up to 1 GB (`config/livewire.php`).
- **Queue worker** kept running with Supervisor (or Laravel Forge / Cloud), using `--timeout=3600` for big uploads.
- **Cron:** `* * * * * php /path/to/artisan schedule:run`.
- **File storage:** move videos to S3 (`FILESYSTEM_DISK`) when you have many clients.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and real mail settings so password resets work.

## Code map

- `app/Services/Social/Drivers/*` — one class per platform (OAuth, publish, fetch content, comments, reply)
- `app/Services/Social/Contracts/PlatformDriver.php` — the interface every platform implements
- `app/Jobs/PublishPostTarget.php`, `app/Jobs/SyncSocialAccount.php` — background work
- `resources/views/livewire/*` — the pages (Volt single-file components)
- `app/Http/Middleware/*`, `app/Http/Controllers/ImpersonationController.php` — admin / suspension / "Manage" / subscription access
- `app/Services/Billing/SubscriptionManager.php` — trials, manual activation, expiry (used by admin pages and Stripe)
- `app/Services/Billing/StripeBilling.php`, `app/Http/Controllers/StripeWebhookController.php` — optional Stripe payments
- `app/Models/Setting.php` — admin-editable settings (secrets encrypted)
- `tests/Feature/*` — client isolation, publishing, billing and limits tests (`php artisan test`)

Social access tokens and secrets saved in Admin → Settings are encrypted with `APP_KEY`.
Don't lose or change it, or every client must reconnect and you must re-enter the keys.

## Next steps worth adding

- A public pricing / landing page on `/` that lists the plans
- Teams: several users per client workspace with roles
- A calendar view for scheduled posts, and AI caption/hashtag suggestions
"# social-Media-Manager" 
