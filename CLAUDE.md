# SocialHub (Gokatale Social)

Multi-tenant social media management SaaS. Live at **https://social.gokatale.tech**.
Repo: github.com/DERRO12/social-Media-Manager (branch `main`). See README.md for the full feature list.

## Stack
Laravel 12, Livewire 4 + Volt single-file pages (`resources/views/livewire/**`), Flux **free** components, Tailwind v4, MySQL.
Queue: database driver (`retry_after` 3700 > the 3600s upload timeout, so long uploads aren't run twice).

## How it's organised
- **Tenancy:** every client is a `User`; always query through the user's relations (`auth()->user()->socialAccounts()`, `->comments()`, …), never by raw id. Admins have `is_admin`; impersonation via `ImpersonationController`.
- **Platforms:** one driver per platform in `app/Services/Social/Drivers` implementing `PlatformDriver`; keys come from Admin → Settings (encrypted `Setting` rows) merged over `.env` in `SocialManager::credentials()`. Callback URLs are built from `APP_URL`.
- **Sync:** `SyncSocialAccount` job, scheduled every `SYNC_EVERY_MINUTES` (5). Comments are re-downloaded only when the platform's count changed (`comments_synced_count`) or every 6h. YouTube fetches every page and every reply.
- **Billing:** `SubscriptionManager` (trials, manual activation, payment-link approvals), optional Stripe (`StripeBilling`), PayPal / Yo! Payments links per plan with "I've paid" claims approved in Admin → Payments.
- **Academy:** courses → modules → lessons (YouTube id and/or Markdown notes) → quiz → certificate (`App\Services\Academy\Academy`). Public pages under `/courses` and `/certificates/{code}`.
- **Email:** the site's mail goes through the admin's mailbox (Admin → Settings → Email, `SiteMail::apply()` at boot, Gmail app password). Clients send newsletters from their own `Mailbox`, or, if none, the admin's mailbox (`Mailbox::forUser()`, a virtual shared mailbox under the client's name; per-client and total daily caps; sends flagged `shared`), via `SendCampaign` (paced, daily cap, one-click unsubscribe). How-to videos: `App\Support\HelpVideos` (Admin → Help videos) + `<x-help-video topic="…">`. Email confirmation is only enforced when the admin switches it on (`User::hasVerifiedEmail()`).
- **SMS:** EgoSMS only (`App\Services\Sms\EgoSms`, JSON API `comms.egosms.co/api/v1/json/`, method SendSms). Platform account in Admin → Settings with a monthly allowance per client; clients may connect their own `SmsAccount`. Phones stored as digits, 256… (`Phone::normalise`). SMS campaigns reuse `email_campaigns` with `channel = sms`.
- **AI:** Claude via `App\Services\Ai\Writer` (key, model, daily limit in Admin → Settings → AI).
- **Search visibility:** public pages `/p/{handle}` with photos (`ImageOptimizer` strips GPS, makes WebP), structured data in `App\Support\ProfileSchema`, image sitemap, IndexNow in production.
- **Uploads:** browser sends videos in 50 MB chunks (`ChunkedUploadController`) because Cloudflare rejects requests over 100 MB.

## Platform status
- YouTube: connected and working (OAuth verification + YouTube API audit pending with Google).
- X: posts on the Free plan; reading replies needs a paid plan + the "read access" switch in Admin → Settings.
- TikTok: sandbox; app review pending (domain TXT verification, demo video).
- Meta (Facebook/Instagram), LinkedIn: setup guides given; not yet connected.
- Google Business Profile (create listing, reviews in the inbox): built; needs Google's Business Profile API access approval for the Google Cloud project.

## Local development (Windows, XAMPP)
- PHP 8.2 from XAMPP **without the intl extension**: don't use Laravel's `Number` helper in views; use `App\Support\Metric`.
- **Never write files with a UTF-8 BOM** (a BOM in a config file broke every response once). PowerShell 5.1 `Set-Content -Encoding utf8` adds one; use `[IO.File]::WriteAllText($p, $c, (New-Object System.Text.UTF8Encoding($false)))`.
- Commit messages with quotes: write them to `.git/COMMIT_MSG_SOCIALHUB`, `git commit -F` it, then delete it (PowerShell mangles quoted args; temp paths are too long).
- Port 8000 may be used by another local project; run `php artisan serve --port=8010` if so.
- Tests: `php artisan test` (SQLite in memory). Keep them green; add a feature test for each change.

## Server (production)
- Ubuntu NAT VPS: SSH `ssh -p 10087 administrator@93.127.133.65`, then `sudo -i` (shared IP, no public 80/443).
- Public traffic arrives through a **Cloudflare Tunnel** (`cloudflared`) to Nginx on `127.0.0.1:80` / `[::1]:80`; `.env` has `TRUSTED_PROXIES=*`. DNS for gokatale.tech is on Cloudflare.
- App in `/var/www/socialhub`; deploy with `bash /var/www/socialhub/deploy/update.sh`. Worker via Supervisor (`socialhub-worker`), scheduler via `/etc/cron.d/socialhub`.
- The owner deploys and pastes server output back; never ask for passwords or secrets in chat.
