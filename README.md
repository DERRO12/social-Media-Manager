# SocialHub

A multi-client social media management SaaS built with Laravel 12, Livewire 4 (Volt) and Flux.

Each **client** signs up, connects their own social accounts and gets their own private workspace.
The **platform admin** sees every client, can add / suspend clients, and can open any client's workspace ("Manage").

## What a client can do

| Page | What it does |
|---|---|
| **Dashboard** | Totals across all their accounts: views, likes, comments, unread comments, upcoming posts |
| **Create post** | Upload one video, pick accounts, write a caption, post now / schedule / save draft |
| **Scheduled & sent** | Every post with its status on each platform, links to the live post, retry failed |
| **All content** | Every video/post on their connected accounts (also ones posted outside the app) with metrics |
| **Inbox** | Comments from all platforms in one feed: reply, mark read, filter by platform or post |
| **Connected accounts** | Connect / sync / disconnect YouTube, Facebook Pages, Instagram, TikTok, LinkedIn, X |

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
- `schedule:work` publishes scheduled posts every minute and syncs all accounts every 15 minutes.

Open http://localhost:8000. Demo logins (password `password`, created by the seeder):

- `admin@socialhub.test` — platform admin
- `amara@socialhub.test`, `kofi@socialhub.test` — demo clients with fake data

> The demo accounts have fake tokens, so Sync/Publish fail on them. Connect real accounts to test for real.

Create your real admin account with:

```bash
php artisan app:make-admin you@yourdomain.com
```

## Connecting the platforms

Create a developer app on each platform and put its keys in `.env`.
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
- `app/Http/Middleware/*`, `app/Http/Controllers/ImpersonationController.php` — admin / suspension / "Manage"
- `tests/Feature/TenancyTest.php`, `tests/Feature/PublishingTest.php` — client isolation and publishing tests

Social access tokens are stored encrypted with `APP_KEY`. Don't lose or change it, or every client must reconnect.

## Next steps worth adding

- Subscriptions / billing per client (Laravel Cashier + Stripe) with plan limits (accounts, posts per month)
- Teams: several users per client workspace with roles
- A calendar view for scheduled posts, and AI caption/hashtag suggestions
"# social-Media-Manager" 
