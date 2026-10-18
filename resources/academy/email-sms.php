<?php

$app = fn (string $text) => str_replace('APPNAME', config('app.name'), $text);

return [
    'slug' => 'email-and-sms-marketing',
    'title' => 'Email & SMS Marketing: Reach Customers Directly',
    'summary' => 'Build your own list of customers, connect Gmail with an app password, and send emails and SMS that people open and act on.',
    'level' => 'beginner',
    'featured' => true,
    'description' => $app(<<<'MD'
Social media platforms decide who sees your posts. Your **email and SMS list** is different: it belongs to you, and every message reaches the person directly. This course shows you how to grow that list the right way and use it to bring customers back again and again, step by step inside APPNAME.

**You will learn how to:**

- grow a list of people who *want* to hear from you
- connect your Gmail to APPNAME with an app password
- write emails people open, read and click
- write short SMS that get people to act
- use tags to send the right message to the right people
- measure your results and improve every month
MD),
    'modules' => [
        ['Module 1: Your own audience', [
            ['Why your own list beats followers', 6, $app(<<<'MD'
Imagine you have 5,000 followers on Instagram. When you post, maybe **5 to 10%** of them see it. The platform chooses. If your account is hacked or banned, they are gone.

Now imagine 500 customers on your **email and SMS list**:

- Every message lands in their inbox or on their phone.
- An SMS is usually read within minutes.
- Nobody can take the list away from you.

## What a list is good for

- **Bringing customers back:** "New stock is in", "Your favourite dish is back".
- **Filling quiet days:** a Tuesday offer sent on Tuesday morning.
- **Launching something new:** your list hears first and buys first.
- **Getting reviews:** ask happy customers for a Google review with one link.

## Social media and your list work together

Use social media to **meet** new people, and your list to **keep** them. Every post, bio and flyer can invite people to join your list. In APPNAME, turn on the sign-up form on your public page (Email & SMS → Settings & sign-up form) and share that link.

> **Action:** Write down three moments when a message to all your customers would have brought you sales last month.
MD)],
            ['Getting permission and growing your list', 7, $app(<<<'MD'
Only send marketing messages to people who **agreed** to receive them. It's the law in many countries (including Uganda's Data Protection and Privacy Act), and it's simply good business: people who asked for your messages open them, people who didn't mark them as spam.

## Good ways to collect contacts

- **At the counter or after a sale:** "Can we send you our offers? Just your name and number."
- **Your public page sign-up form:** people type their email (and phone, optional) and tick the box.
- **A reason to join:** "Join our list and get 10% off your next visit" works far better than "Subscribe".
- **QR code on a poster or receipt** that opens your public page. Make one in Links & QR codes.
- **Events and markets:** a clipboard or a tablet with your sign-up page open.

## Importing contacts you already have

Have customers in Excel, Google Sheets or your phone? In **Email & SMS → Contacts**, import a CSV file with an **email** and/or **phone** column. Local numbers like 0772 123456 are understood. Only import people who agreed to hear from you, and tick the box confirming it.

## Always make it easy to leave

Every email from APPNAME has an **unsubscribe** link and every SMS ends with a short **opt-out** link. Never remove them. A smaller list of interested people beats a big list of annoyed ones.

> **Action:** Turn on your sign-up form and share your page link in your WhatsApp status today.
MD)],
        ]],
        ['Module 2: Email marketing', [
            ['Connecting Gmail with an app password', 6, $app(<<<'MD'
APPNAME sends your newsletters from **your own Gmail address**, so customers recognise you and replies come straight to you. Google doesn't let apps use your normal password, so you create a special **app password** just for APPNAME.

## Step by step

1. **Turn on 2-Step Verification.** Go to myaccount.google.com → Security → 2-Step Verification and follow the steps (you'll confirm with your phone).
2. **Create the app password.** Go to myaccount.google.com/apppasswords. Type a name like "APPNAME" and click **Create**.
3. **Copy the 16 letters** Google shows (like `abcd efgh ijkl mnop`). You only see them once.
4. In APPNAME, go to **Email & SMS → Settings & sign-up form**, choose **Gmail**, enter your Gmail address and paste the app password. Spaces don't matter.
5. Click **Save**, then **Send me a test**. Check your inbox (and spam folder).

## Good to know

- The app password only works for sending email. It can't be used to sign in to your Google account.
- You can delete it at any time on the same Google page; APPNAME then stops sending until you add a new one.
- Gmail sends up to about **500 emails a day**; Google Workspace up to 2,000. Set your daily limit a little below that.
- **No mailbox yet?** You can still send: your emails go out from APPNAME's mailbox with your name, and replies come to you, up to a daily limit.

## If the test fails

- "Rejected the login": the app password is wrong, or 2-Step Verification is off. Create a new app password.
- Using Outlook or Zoho? Choose that provider; they have app passwords too.

> **Action:** Connect your Gmail and send yourself a test email.
MD)],
            ['Writing emails people open', 7, <<<'MD'
People decide in **two seconds** whether to open your email, based on three things: who it's from, the subject, and the preview text.

## Subject lines that work

- **Short:** under 50 characters, so phones show all of it.
- **Specific:** "New braids styles for December" beats "Newsletter #12".
- **Personal:** "{first_name}, your weekend offer" (the name is filled in for each person).
- **Curiosity or benefit:** "3 mistakes that ruin your hair" or "Free delivery this week only".
- **Honest:** never promise something the email doesn't deliver.

## The preview text

The grey line next to the subject. Use it to add a second reason to open: subject "December offers", preview "Plus our new opening hours".

## Inside the email

- **One main message.** One offer or one piece of news, not ten.
- **Short paragraphs** of one to three sentences. Most people read on a phone.
- **One clear button or link:** "Book now", "Order on WhatsApp", "See the menu".
- **A real photo** of your product, shop or team.
- **Sound like a person**, sign with your name.

## Use AI to start, then make it yours

In the campaign editor, **Write with AI** drafts a full newsletter from a few words. Always read it, fix any details, and add your own voice.

> **Action:** Write three subject lines for your next offer and pick the one you'd open yourself.
MD],
            ['A simple monthly email plan', 6, <<<'MD'
Consistency matters more than perfection. **One good email a month**, every month, keeps you in your customers' minds. Two to four a month is great once you have a rhythm.

## A plan you can repeat

- **Week 1: News.** What's new: products, services, results, team.
- **Week 2: Tip.** Something useful about your field, with no selling.
- **Week 3: Offer.** A clear deal with a deadline.
- **Week 4: Story.** A customer story or behind-the-scenes moment.

Start with just the first one and add more when you can.

## Use the templates

In **Email & SMS → Campaigns** there are ready-made templates: monthly news, special offer, flash sale, new product, event invitation, tips, customer story, new video, ask for reviews, holiday greetings and "we miss you". Pick one, change the details, send.

## Plan with the calendar

Open the **Calendar** to see important days coming up (Independence Day, Christmas, Mother's Day, Black Friday…). Plan your emails around them a week ahead.

## Schedule instead of rushing

Write on a quiet day and **schedule** the campaign for the best time: usually a weekday morning (8–10am) or early evening. Check **Insights** to see when your audience is most active on social media; email follows similar patterns.

> **Action:** Put four email dates for next month in your calendar.
MD],
        ]],
        ['Module 3: SMS marketing', [
            ['When SMS works best', 6, <<<'MD'
SMS is the most direct channel you have: almost everyone has a phone, it works without internet, and most messages are read within minutes. That power means you should use it **carefully**: too many SMS and people opt out.

## Great uses for SMS

- **Time-sensitive offers:** "Today only: 2 for 1 juices until 6pm."
- **Reminders:** appointments, events, payment due dates.
- **New stock or arrivals** your regular customers are waiting for.
- **Review requests** right after a visit, with your Google review link.
- **Greetings** on big holidays (no selling).

## When to use email instead

Long news, many photos, a full menu or price list, stories: email is better and cheaper.

## How often?

For most businesses, **two to four SMS a month** at most. Every message should be useful.

## Best times

Between **9am and 7pm**, on days that make sense for your business (a restaurant: before lunch; a salon: Thursday for the weekend). Never late at night.

> **Action:** List three SMS your customers would actually be happy to receive.
MD],
            ['Writing SMS that get action', 6, $app(<<<'MD'
An SMS has **160 characters**. APPNAME adds a short opt-out link at the end, so aim for about **110 characters** of your own to stay within one SMS (and pay for one).

## A simple formula

**Who + what + until when + what to do.**

> Hi {first_name}! Amara Salon: 20% off braids this Sat only. Book: 0772123456

## Tips

- **Name your business** at the start; people don't recognise numbers.
- **One offer, one action:** call, reply, visit or click.
- **Avoid emoji and special characters:** they change the encoding and cut each SMS to 70 characters, doubling the cost.
- **Use {first_name}** to greet people personally.
- **Use {review_link}** in a review request; it becomes your Google review link.

## Check before sending

The campaign editor shows how many characters and how many SMS your message is, and a phone preview. Send yourself a **test SMS** first.

## Let AI help

**Write with AI** in an SMS campaign writes a short message for you. Read it and adjust the details.

> **Action:** Write one SMS for your next offer using the formula, under 110 characters.
MD)],
            ['EgoSMS: sender IDs, credit and costs', 5, $app(<<<'MD'
APPNAME sends SMS through **EgoSMS**, a Ugandan SMS provider.

## Two ways to send

1. **APPNAME's account (easiest):** you can send a number of SMS each month included with your plan. The sender name is APPNAME's.
2. **Your own EgoSMS account:** your **business name** shows as the sender, there's no monthly cap, and you buy credit directly from EgoSMS.

## Setting up your own EgoSMS account

1. Register at **egosms.co** and verify your account.
2. Buy SMS credit (Mobile Money works).
3. Request a **sender ID**: your business name, up to 11 letters or numbers, e.g. AMARASALON. Approval can take a few days.
4. In APPNAME, go to **Email & SMS → Settings**, enter your EgoSMS username, password and sender ID, and click **Save**.
5. Click **Send test** with your own phone number, and **Check balance**.

## Costs

You pay per SMS part. A 160-character message is one part; longer messages are several parts. If your credit runs out mid-campaign, sending pauses; top up and press **Resume**.

> **Action:** Decide whether the included SMS are enough for you this month, or whether to open your own EgoSMS account.
MD)],
        ]],
        ['Module 4: Results', [
            ['Tags: the right message to the right people', 5, <<<'MD'
Not every message is for everyone. **Tags** let you group contacts and send to just one group.

## Useful tags

- **customers** vs **leads** (bought vs only interested)
- **vip** for your best customers
- a product or service: **braids**, **wigs**, **catering**
- a place: **kampala**, **entebbe**
- an event: **expo-2025**

## How to tag

- When adding a contact, type tags separated by commas.
- When importing a spreadsheet, tag everyone in the file (e.g. "market-day-dec").
- In a campaign, choose **Send to → Only contacts tagged "vip"**.

## Ideas

- A thank-you offer only for **vip** customers.
- An event invitation only for people in **kampala**.
- A "we miss you" email for **customers** who haven't visited in a while.

> **Action:** Add at least two tags to your contacts this week.
MD],
            ['Measuring and improving', 6, <<<'MD'
After each campaign, look at the results in its page and learn one thing.

## What the numbers mean

- **Delivered:** emails or SMS that went out successfully.
- **Opened (email):** how many people opened it. 20–40% is good for small businesses.
- **Failed:** wrong addresses or numbers. Fix or remove them.
- **Cost (SMS):** what the campaign cost on EgoSMS.

## Track clicks with smart links

Make a **short link** in Links & QR codes for the link in your email or SMS, choosing "Email newsletter" or "SMS" as where you share it. You'll see exactly how many people clicked, day by day.

## Improve one thing at a time

- Low opens? Try a more specific or personal subject line.
- Opens but no clicks? Make the offer clearer and the button bigger.
- Many SMS opt-outs? You may be sending too often.

## Keep your list healthy

- Remove contacts that always fail.
- Respect opt-outs (APPNAME does this automatically).
- Keep inviting new people to join.

> **Action:** After your next campaign, write down its open rate and clicks, and one thing to try next time.
MD],
        ]],
    ],
    'questions' => [
        ['Why is your own email/SMS list valuable?', ['It reaches people directly and nobody can take it away', 'It is always free to use', 'It replaces the need for social media', 'Platforms boost posts to people on it'], 0, 'Social platforms decide who sees your posts; your list reaches every person directly and belongs to you.'],
        ['Who should you send marketing emails and SMS to?', ['Anyone whose number you find', 'Only people who agreed to receive them', 'All your phone contacts', 'Your competitors\' customers'], 1, 'Only message people who agreed. It is the law in many countries and those people actually read your messages.'],
        ['What is a Gmail app password?', ['Your normal Gmail password', 'A 16-letter password that lets an app send email for you', 'A code Google texts you every time', 'The password for your website'], 1, 'An app password is a special 16-letter password, made after turning on 2-Step Verification, that only lets an app send email.'],
        ['What must you turn on before you can create a Gmail app password?', ['Google Analytics', 'Gmail filters', '2-Step Verification', 'YouTube'], 2, 'Google only offers app passwords once 2-Step Verification is on.'],
        ['Which is the best email subject line?', ['Newsletter #12', 'IMPORTANT!!!', 'New braids styles for December', 'Hello'], 2, 'Specific, short subject lines tell people exactly why to open.'],
        ['About how many characters should your own SMS text be so it stays one SMS with the opt-out link?', ['50', '110', '300', '459'], 1, 'An SMS holds 160 characters; the opt-out link uses some, so aim for about 110.'],
        ['Why avoid emoji in SMS?', ['They are not allowed', 'They make each SMS hold only 70 characters, raising the cost', 'Phones cannot show them', 'EgoSMS blocks them'], 1, 'Emoji switch the message to a different encoding with 70 characters per SMS, so it costs more.'],
        ['What are tags used for?', ['To hide contacts', 'To send a campaign to just one group of contacts', 'To change contact names', 'To delete opt-outs'], 1, 'Tags group contacts so you can send the right message to the right people.'],
    ],
];
