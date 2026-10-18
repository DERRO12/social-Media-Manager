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

### Payment links (PayPal, Yo! Payments / Mobile Money)

1. In **Admin → Plans → Edit**, paste a **PayPal payment link** and/or a **Yo! Payments link** for that plan's price.
2. Clients see **Pay with PayPal** / **Pay with Mobile Money** on Plan & billing. After paying they press **I've paid** and enter the transaction reference.
3. You get an email, check the payment in your PayPal / Yo! account, then **Approve** or **Reject** it in **Admin → Payments**
   (the sidebar shows how many are waiting). Approving activates the plan for one month or year; paying early adds the time on top.
   The client is emailed either way.

## Academy (courses and certificates)

| Where | What it does |
|---|---|
| **Admin → Courses** | Create courses (title, level, description, cover, pass mark, published / featured / certificate on-off). |
| **Admin → Courses → Course library** | A full social media academy ready to add in one click (each course or **Add all & publish**): *The Complete Guide to the platform* (every feature step by step), *Social Media Marketing Foundations*, *TikTok Growth: Going Viral*, *Instagram Growth*, *YouTube and Shorts*, *Facebook Pages and Groups*, *LinkedIn and X*, *Great Content with Your Phone*, *Email & SMS Marketing* (Gmail app passwords, newsletters, EgoSMS), *WhatsApp Business* and *Google Business Profile and Local SEO*: 84 lessons and 92 quiz questions. Already installed courses aren't changed; add new ones with their **Add** button. Courses are added as drafts you can edit (add YouTube videos, change text). New courses go in `resources/academy/*.php`. |
| **Course → Lessons** | Modules and lessons in any order. A lesson is a **YouTube video** (any public/unlisted link), **written notes** (Markdown), or both. **Import a whole YouTube playlist** in one go (needs the YouTube API key in Settings). Mark lessons as **free preview**. |
| **Course → Final quiz** | Multiple-choice questions (2–6 answers) with explanations shown to students who get them wrong. |
| **Course → Students** | Who started, their progress, their certificate, revoke/restore. |
| **Admin → Courses → Certificates** | Every certificate (search, correct the name, revoke) and the wording: issuing organisation, signer's name and title. |

Students (any client with a trial or plan) use **Academy** in the sidebar: course catalog, "continue where you left off",
video lessons with notes and progress, the final quiz (unlocked after all lessons), and **My certificates**.
Passing awards a certificate with a public verification page (`/certificates/CODE`), a print / save-as-PDF layout and an
**Add to LinkedIn** button. Visitors without an account can browse **/courses** and watch the free preview lessons.

## Grow on Google

| Page | What it does |
|---|---|
| **Business Profile** | Builds a Google Business Profile step by step: category, keywords, services, hours, a ready-to-paste description (max 750 characters), a strength score, a Google-style preview, a setup checklist and a review link. With **Google Business Profile connected** (Connected accounts), the client creates the business on Google Maps from these details in one click (Google category search included), sends later changes to Google, and gets the Place ID and review link automatically. Verification still happens on Google (video, phone, email or postcard). |
| **SEO checker** | Checks any public website for 15 basics (title, description, headings, mobile, HTTPS, indexing, canonical, image alt text, social tags, structured data, speed, robots.txt, sitemap) and explains each fix. 10 checks per hour per client. It only fetches public internet addresses (private, local and cloud-metadata IPs are blocked, redirects included). |
| **Public page** | A page at `/p/handle` listing the client's posts and photos, plus one page per video (`/p/handle/v/…`) with structured data (VideoObject, ProfilePage) so Google can show them in search. Clients set the handle, their own Google title and description (with a live Google preview), a logo, and can show their business details (services, hours, address, call/book/directions buttons, review link) marked up as a LocalBusiness. One-click links to Google's Rich Results Test, PageSpeed, `site:` search and Facebook's sharing preview. Pages disappear when the plan ends. |
| **Photos** | Clients upload photos (up to 300) for Google Images. Each is resized to 2000px plus a 640px thumbnail (WebP), given a search-friendly file name, and stripped of GPS and camera data. Each photo gets its own page (`/p/handle/photos/…`) with ImageObject data, breadcrumbs, large-image tags and an image sitemap entry. A per-photo score (title, alt text, caption, keywords, location, size) says what to improve. |

`/sitemap.xml` lists the public pages, courses and every live public page with its posts and photos (image sitemap);
`/robots.txt` points to it and keeps private areas out of search. Submit `https://YOUR-DOMAIN/sitemap.xml` in Google
Search Console. In production, new and changed public pages are also sent to **IndexNow** (Bing, Yandex and others;
key file at `/indexnow.txt`; turn off with `INDEXNOW_ENABLED=false`).

## Email

| Where | What it does |
|---|---|
| **Admin → Settings → Email** | Connect the site's mailbox: Gmail / Google Workspace with an **app password** (step-by-step help on the page), Outlook, Zoho or any SMTP server, with a "send test email" button. It sends password resets, email confirmation (optional switch "New sign-ups must confirm their email", existing users are never locked out), welcome emails, a new sign-up alert to admins, trial-ending and plan-ended emails, payment updates, failed post alerts and certificates. |
| **Admin → Settings → SMS (EgoSMS)** | The platform's [EgoSMS](https://www.egosms.co) account (username, password, sender ID) shared by clients up to a monthly allowance each (0 = clients must use their own), with a test SMS and balance check. |
| **Email & SMS → Settings & sign-up form** | Each client can connect **their own** Gmail (app password) so newsletters come from their address, with a daily limit (Gmail: about 500 a day). **Without one, their emails go through the admin's mailbox** (Admin → Settings → Email) with the client's name as sender and replies to the client, within a daily limit per client and in total, both set by the admin. Same for SMS: the admin's EgoSMS account by default, or **their own EgoSMS account** (their sender ID, their credit). Also turns on a sign-up form (email + optional phone) on their public page. |
| **Admin → Help videos** | A YouTube link (unlisted is fine) per how-to topic: Gmail app password, email newsletter, SMS campaign, EgoSMS set-up, importing contacts, connecting accounts, posting, AI, calendar, inbox, insights, Google Business Profile, public page, links & QR codes, Academy. Each shows as a **Watch how** button on its page, and all of them on the clients' **Help & tutorials** page. |
| **Email & SMS → Contacts** | Emails and/or phone numbers (local numbers like 0772 123456 become +256…), added one by one with a consent tick or imported from a CSV (Excel, Google Sheets, phone contacts export), tagged, exported. Separate email and SMS opt-outs. Up to 5,000 contacts per client, **visible only to that client**. |
| **Email & SMS → Campaigns** | 13 email templates (welcome, monthly news, offer, flash sale, launch, event, tips, customer story, new video, ask for reviews, holiday, we miss you) and 7 SMS templates, a simple editor (bold, headings, links, images, `{first_name}`, `{review_link}`, `{page_link}`), live email or phone preview with SMS character and part counter, AI writing, test send, send now or schedule, everyone or one tag. Sending runs in the background, paced and capped (email: mailbox daily limit; SMS: monthly allowance), pauses with a clear message if a password stops working or SMS credit runs out (Resume continues), and shows delivered, opened (email), cost (SMS) and failed. Every email has an unsubscribe link and one-click unsubscribe headers; every SMS ends with a short opt-out link. |

## Marketing tools

| Page | What it does |
|---|---|
| **AI writing assistant** | Admin → Settings → AI: an Anthropic (Claude) API key, the model and a daily limit per client. Clients then get **Write with AI**: 3 captions + hashtags + a title in Create post (tone and language: English, Luganda, Swahili, French…), **Suggest a reply** for comments and Google reviews in the inbox, and newsletters and SMS in campaigns, all written for their own business details. |
| **Calendar** | Month view of scheduled and published posts (an agenda list on phones) with marketing days: Ugandan and East African holidays, awareness days, Mother's/Father's Day, Easter, Black Friday, each with a content idea. Click a day to plan a post for it. |
| **Insights** | Best day and time to post (heatmap of average likes + comments + shares), results by platform and weekday, engagement rate and the top 10 posts, for 30/90/365 days or all time. |
| **Links & QR codes** | Short trackable links (`/go/abc123`) with campaign (UTM) tags picked by where they're shared, clicks per day, and a printable QR code for each. Ready-made QR codes for the public page and the Google review link. Link previews and bots aren't counted. |
| **Link in bio** | Up to 8 buttons and a **Chat on WhatsApp** button on the public page, so one link in an Instagram or TikTok bio leads everywhere. |

## What a client can do

| Page | What it does |
|---|---|
| **Dashboard** | Totals across all their accounts: views, likes, comments, unread comments, upcoming posts |
| **Create post** | Upload one video, pick accounts, write a caption, post now / schedule / save draft |
| **Scheduled & sent** | Every post with its status on each platform, links to the live post, retry failed |
| **All content** | Every video/post on their connected accounts (also ones posted outside the app) with metrics |
| **Inbox** | Comments from all platforms in one feed: reply, mark read, filter by platform or post |
| **Connected accounts** | Step-by-step guide; connect / reconnect / sync / disconnect YouTube, Facebook Pages, Instagram, TikTok, LinkedIn, X |
| **Trending** | Live YouTube trending videos and music by country, plus official trend links for TikTok, Instagram, X, Facebook |
| **Academy** | Social media marketing courses, quizzes and certificates |
| **Grow on Google** | Business Profile builder (and creating it on Google), public page, photos for Google Images, SEO checker |
| **Email & SMS** | Their own mailbox and EgoSMS, a sign-up form, private contacts (emails and phones), newsletter and SMS campaigns |
| **Calendar, Insights, Links & QR codes** | Plan posts around key dates, see the best time to post, track clicks and print QR codes |
| **Plan & billing** | Current plan, trial days left, usage against limits, pay by card, PayPal or Mobile Money, payment history |

On phones the app has a bottom tab bar (Home, Create, Inbox, Content, Learn) and the full menu behind the ☰ button.

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
| Google Business Profile | – | ✅ rating and review count | ✅ Google reviews in the inbox | ✅ (one public reply per review) |

Google Business Profile uses the same Google Cloud app as YouTube, but Google must first approve the project for the
Business Profile APIs (request form linked in Admin → Settings → Google). Until then, connecting shows a clear message.

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
- Set `APP_ENV=production`, `APP_DEBUG=false`, and connect a mailbox in **Admin → Settings → Email** so password resets and other emails are sent.

## Code map

- `app/Services/Social/Drivers/*` — one class per platform (OAuth, publish, fetch content, comments, reply)
- `app/Services/Social/Contracts/PlatformDriver.php` — the interface every platform implements
- `app/Jobs/PublishPostTarget.php`, `app/Jobs/SyncSocialAccount.php` — background work
- `resources/views/livewire/*` — the pages (Volt single-file components)
- `app/Http/Middleware/*`, `app/Http/Controllers/ImpersonationController.php` — admin / suspension / "Manage" / subscription access
- `app/Services/Billing/SubscriptionManager.php` — trials, manual activation, expiry (used by admin pages and Stripe)
- `app/Services/Billing/StripeBilling.php`, `app/Http/Controllers/StripeWebhookController.php` — optional Stripe payments
- `app/Models/Setting.php` — admin-editable settings (secrets encrypted)
- `app/Services/Academy/*`, `resources/academy/*.php` — course progress, certificates and the ready-made course library
- `app/Services/Seo/*`, `app/Http/Controllers/PublicProfileController.php`, `app/Support/ProfileSchema.php` — SEO checker (safe fetching), photo optimisation, public pages, structured data, sitemap, robots.txt, IndexNow
- `app/Services/Mail/*`, `app/Services/Sms/*`, `app/Jobs/SendCampaign.php`, `app/Http/Controllers/NewsletterController.php` — site email settings, client mailboxes, EgoSMS, email and SMS campaigns, sign-up form, unsubscribe/opt-out and opens
- `app/Support/HelpVideos.php`, `resources/views/components/help-video.blade.php` — admin-managed how-to videos and the Watch how button
- `app/Support/CampaignTemplates.php`, `app/Support/MarketingDates.php` — campaign templates and the calendar's marketing days (add your own there)
- `app/Services/Ai/Writer.php` — the AI writing assistant (Claude)
- `app/Http/Controllers/LinkController.php`, `app/Models/ShortLink.php`, `app/Support/Qr.php` — short links, click counts and QR codes
- `app/Services/Social/Drivers/GoogleBusinessDriver.php` — Google Business Profile: locations, reviews, replies, creating and updating the listing
- `tests/Feature/*` — client isolation, publishing, billing and limits tests (`php artisan test`)

Social access tokens and secrets saved in Admin → Settings are encrypted with `APP_KEY`.
Don't lose or change it, or every client must reconnect and you must re-enter the keys.

## Next steps worth adding

- A public pricing / landing page on `/` that lists the plans
- Teams: several users per client workspace with roles
- A calendar view for scheduled posts, and AI caption/hashtag suggestions
"# social-Media-Manager" 
