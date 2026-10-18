<?php

$app = fn (string $text) => str_replace('APPNAME', config('app.name'), $text);

return [
    'slug' => 'complete-guide-to-the-platform',
    'title' => $app('The Complete Guide to APPNAME'),
    'summary' => $app('Step by step through every tool in APPNAME: connecting accounts, posting, the inbox, Google, email, SMS and insights.'),
    'level' => 'beginner',
    'featured' => true,
    'description' => $app(<<<'MD'
This course walks you through **everything APPNAME can do for your business**, in the order you'll use it. Each lesson is short and practical: read it with APPNAME open in another tab and do each step as you go. The admin may add a video to each lesson showing the screens.

**By the end you will:**

- have your social accounts connected and your first posts scheduled
- answer every comment and review from one inbox
- have a public page, photos and a Google Business Profile that help people find you
- send emails and SMS to your own customers
- know which posts work best and when to post
MD),
    'modules' => [
        ['Module 1: Getting set up', [
            ['A quick tour', 5, $app(<<<'MD'
When you log in you land on the **Dashboard**: totals for all your accounts (views, likes, comments, unread comments, upcoming posts).

## The menu

- **Dashboard:** your numbers at a glance.
- **Create post:** upload a video once and post it everywhere.
- **Calendar:** your posts and important days by month.
- **Scheduled & sent:** every post and its status on each platform.
- **All content:** every video and post on your accounts, even ones posted elsewhere.
- **Inbox:** comments, replies and Google reviews from every platform.
- **Trending:** what's popular on YouTube and the other platforms.
- **Insights:** your best time to post and your top posts.
- **Links & QR codes:** short links that count clicks, and printable QR codes.
- **Email & SMS:** your contacts, newsletters and SMS campaigns.
- **Grow on Google:** Business Profile, public page, photos and the SEO checker.
- **Academy:** courses like this one, with certificates.
- **Help & tutorials:** short how-to videos.

On a phone, the most used pages are in the bar at the bottom, and the full menu is behind the ☰ button.

> **Action:** Click through every menu item once so you know where things are.
MD)],
            ['Connecting your social accounts', 7, $app(<<<'MD'
Go to **Connected accounts** and click **Connect** next to each platform. You'll log in on the platform's own page and approve access. APPNAME never sees your passwords.

## What you need for each

- **YouTube:** a Google account with a YouTube channel.
- **Facebook:** a Facebook **Page** you manage (not a personal profile). Tick every Page you want when Facebook asks.
- **Instagram:** a **Business or Creator** account linked to a Facebook Page. You connect through Facebook.
- **TikTok:** your TikTok login.
- **LinkedIn:** your LinkedIn login.
- **X:** your X login.
- **Google Business Profile:** the Google account that manages your business on Google Maps.

## After connecting

APPNAME imports your recent posts, their numbers and their comments. This takes a few minutes the first time and then repeats automatically. Click **Sync** to check now.

## If something goes wrong

- "Reconnect" means the platform ended the connection (e.g. you changed your password). Click **Reconnect**.
- Each plan allows a number of accounts; your plan page shows how many are left.

> **Action:** Connect every account you post to.
MD)],
        ]],
        ['Module 2: Posting', [
            ['Create and schedule a post', 7, <<<'MD'
Go to **Create post**.

1. **Upload your video** (MP4, MOV or WebM). Vertical 9:16 works best for TikTok, Reels and Shorts.
2. **Add a title** (used by YouTube and LinkedIn) and a **caption** with hashtags.
3. **Choose the accounts** to post to. Tick several to post everywhere at once.
4. **Platform options:** YouTube privacy, and TikTok's privacy and disclosure choices (TikTok requires these).
5. **When:** post now, schedule for a date and time (in your time zone), or save as a draft.

## After posting

Open **Scheduled & sent** to see the status on each platform: publishing, published (with a link) or failed. If one fails, you'll get an email too; often reconnecting the account fixes it, then click **Retry**.

## Tips

- Write the first line of your caption as a hook.
- Use 3–8 relevant hashtags, not 30.
- Post at your best time (see Insights).

> **Action:** Schedule one post for tomorrow at your best time.
MD],
            ['Plan with the calendar', 5, <<<'MD'
The **Calendar** shows your scheduled and published posts by month, plus **marketing days**: holidays and awareness days in Uganda and East Africa, Mother's Day, Black Friday and more, each with a content idea.

## How to use it

- Look at the month ahead every Monday.
- Click **+** on a day (or **Plan** on a phone) to open Create post with that day already chosen.
- Fill empty weeks first: aim for a steady rhythm rather than bursts.
- Prepare content for big days (Independence Day, Christmas) a week before.

> **Action:** Plan at least one post for each of the next four weeks.
MD],
            ['Write faster with AI', 5, $app(<<<'MD'
If the admin has switched it on, you'll see **Write with AI** in several places. It writes for **your** business, using the details in your Business Profile and public page.

## Where to find it

- **Create post:** describe the post in a few words, choose a tone and language (English, Luganda, Swahili, French…), and get three captions, hashtags and a title. Click **Use this**.
- **Inbox:** **Suggest a reply** writes a polite reply to a comment or Google review.
- **Email & SMS campaigns:** writes a whole newsletter or a short SMS.

## Use it well

- Always read and edit: fix prices, dates and names, and add your own voice.
- Give a specific topic: "20% off bridal braids this weekend in Ntinda" beats "post about braids".
- There is a daily limit on AI requests; it resets at midnight.

> **Action:** Generate captions for your next post and edit the best one.
MD)],
        ]],
        ['Module 3: Talking to customers', [
            ['The inbox: comments and reviews', 6, <<<'MD'
The **Inbox** collects comments and replies from all your platforms, and Google reviews if your Business Profile is connected.

## Working through it

- **Unread** shows only what needs your attention; **All** shows everything.
- Filter by platform, or open a post's comments from All content.
- Click **Reply**, write (or click **Suggest a reply**), then **Send reply**. It's posted on the platform under your account.
- Replies by other people in a conversation appear in the thread.

## Good habits

- Reply within a day. Fast replies make people comment more.
- Thank people by name.
- Answer complaints calmly and invite them to message you privately.
- Reply to every Google review, good or bad: future customers read your replies.

> **Action:** Clear your unread inbox today.
MD],
        ]],
        ['Module 4: Getting found on Google', [
            ['Your public page and photos', 6, <<<'MD'
**Grow on Google → Public page** creates a page for you at an address like `/p/your-name`, which Google can show in search.

1. Choose your **page address**, name, headline and bio. Use words people search for: "Bridal braids in Kampala".
2. Upload a **logo or photo**.
3. Optional: your own **Google title and description**, with a live Google preview.
4. Add a **WhatsApp number** and **link buttons** (shop, booking, menu): your page becomes your link in bio.
5. Turn **Make my page public** on and save.

## Photos for Google Images

In **Photos**, upload your best real photos. For each, add a clear title, alt text (what's in the photo), a caption, keywords and your town. The score shows what's missing. Each photo gets its own page that Google Images can show.

## Use your page

- Put the link in your Instagram and TikTok bio.
- Print its QR code (Links & QR codes) on flyers and receipts.

> **Action:** Publish your page and add five photos with full descriptions.
MD],
            ['Google Business Profile', 7, <<<'MD'
Your Google Business Profile is how you appear on **Google Maps** and in local searches like "salon near me".

## Prepare it

In **Grow on Google → Business Profile**, fill in your name, category, address, phone, hours, services and description. The strength score and checklist show what to improve, and **Write a draft for me** helps with the description.

## Put it on Google

- **Connected?** If Google Business Profile is connected in Connected accounts, use **Create on Google** to create the listing from these details, and **Send these details to Google** to update it later.
- **Not connected?** Copy the details into business.google.com yourself.

Either way, Google asks you to **verify** the business (video, phone, email or postcard) before it shows on Maps.

## Reviews

- Add your **Place ID** (or connect the profile) to get your **review link** and a printable review **QR code**.
- Ask every happy customer for a review: by SMS, email (the "Ask for reviews" template) or the QR code at your counter.
- Reply to reviews in the Inbox.

> **Action:** Complete your Business Profile to at least 80%.
MD],
            ['Links and QR codes', 4, <<<'MD'
**Links & QR codes** makes short links like `/go/abc123` that count every click.

1. Paste the address you want to share (your shop, a product, WhatsApp, a form).
2. Choose where you'll share it (Instagram bio, WhatsApp status, flyer…) and, optionally, a campaign name.
3. Copy the short link, or download its **QR code** to print.

The list shows clicks per day for each link, so you can see which post or flyer actually brings people. Ready-made QR codes for your public page and Google review link are on the right.

> **Action:** Make a short link for your main offer and use it in your next post.
MD],
        ]],
        ['Module 5: Your own audience and results', [
            ['Email and SMS in the app', 6, $app(<<<'MD'
**Email & SMS** lets you message your own customers directly. (The Email & SMS Marketing course goes deeper.)

1. **Settings & sign-up form:** connect your Gmail with an app password (or send through APPNAME's mailbox), set up SMS, and turn on the sign-up form on your public page.
2. **Contacts:** add people, or import a spreadsheet of emails and phone numbers. Only you can see your contacts.
3. **Campaigns:** choose Email or SMS, pick a template, edit, preview, send a test, then **Send now** or **Schedule**.

Each campaign page shows how many were delivered, opened (email) and failed.

> **Action:** Import your customer list and send your first campaign.
MD)],
            ['Read your insights', 5, <<<'MD'
**Insights** shows what works on your accounts, for the last 30 or 90 days, 12 months or all time.

- **Best time to post:** a grid of days and times; darker means more likes, comments and shares per post.
- **The tip at the top** tells you your best day and time once you have enough posts.
- **By platform:** where your audience reacts most.
- **Top posts:** your 10 best posts. Make more like them, and reuse them on other platforms.

## Turn insights into action

1. Schedule your most important posts at your best time.
2. Every month, look at your top 3 posts and ask: what did they have in common?
3. Try one new idea each week and compare.

> **Action:** Find your best day and time, and schedule next week's posts then.
MD],
        ]],
    ],
    'questions' => [
        ['Where do you upload a video once and post it to several platforms?', ['Inbox', 'Create post', 'Insights', 'Calendar'], 1, 'Create post lets you upload once, pick several accounts and post now or schedule.'],
        ['What kind of Instagram account can be connected?', ['Any personal account', 'A Business or Creator account linked to a Facebook Page', 'Only private accounts', 'Instagram cannot be connected'], 1, 'Instagram\'s API only works with Business or Creator accounts linked to a Facebook Page.'],
        ['What does "Reconnect" on an account usually mean?', ['The account was deleted', 'The platform ended the connection, so you need to approve access again', 'You have too many followers', 'Your plan expired'], 1, 'Platforms end connections when passwords change or access expires; reconnecting fixes it.'],
        ['What should you always do with AI-written text?', ['Post it exactly as written', 'Read and edit it, fixing details and adding your voice', 'Translate it twice', 'Delete the hashtags'], 1, 'AI drafts are a starting point; check prices, dates and names and make them sound like you.'],
        ['Why reply to every Google review?', ['Google deletes reviews without replies', 'Future customers read your replies and see you care', 'It removes bad reviews', 'It is required to stay on Maps'], 1, 'Replies show future customers how you treat people, and encourage more reviews.'],
        ['What must happen before your business shows on Google Maps?', ['You post on TikTok', 'Google verifies the business', 'You send an SMS campaign', 'You buy ads'], 1, 'Google verifies businesses (video, phone, email or postcard) before showing them on Maps.'],
        ['What do short links in Links & QR codes tell you?', ['Who your competitors are', 'How many people clicked, day by day', 'Your Google ranking', 'Your followers\' ages'], 1, 'Short links count every click so you can see which post, bio or flyer works.'],
        ['What does the Insights "best time to post" grid show?', ['When the app is busiest', 'Which days and times your posts get the most likes, comments and shares', 'When your competitors post', 'Your scheduled posts'], 1, 'It averages engagement per post by day and time, so you can post when your audience reacts most.'],
    ],
];
