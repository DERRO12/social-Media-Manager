<?php

return [
    'slug' => 'whatsapp-business-marketing',
    'title' => 'WhatsApp Business: Turn Chats into Sales',
    'summary' => 'Set up WhatsApp Business properly and use your catalogue, status, broadcast lists and quick replies to sell more, without spamming.',
    'level' => 'beginner',
    'description' => <<<'MD'
For many businesses in Africa, **WhatsApp is where the sale happens**. People see you on Instagram or TikTok, then message you on WhatsApp to ask the price. This course shows you how to use the free WhatsApp Business app like a professional.

**You will learn how to:**

- set up a business profile and catalogue customers trust
- answer faster with quick replies, greetings and labels
- use your Status as a free daily advert
- send broadcasts people are happy to receive
- bring people from social media to your WhatsApp
- follow up and turn chats into repeat customers
MD,
    'modules' => [
        ['Module 1: Set up to sell', [
            ['Your WhatsApp Business profile', 6, <<<'MD'
Download **WhatsApp Business** (free, separate from normal WhatsApp). You can move your existing number to it.

## Fill in everything (Settings → Business tools → Business profile)

- **Profile photo:** your logo or a clear photo of your shop.
- **Business name** exactly as customers know you.
- **Category:** salon, restaurant, clothing store…
- **Description:** what you sell, where, and why you're different, in one or two lines.
- **Address** with a map pin, **business hours**, **email** and **website** (your public page link works well).

A complete profile looks trustworthy, and trust is what makes people pay.

> **Action:** Complete every field of your business profile today.
MD],
            ['Your catalogue', 6, <<<'MD'
The **catalogue** is a mini shop inside WhatsApp (Business tools → Catalogue).

## For each product or service

- A **clear photo** on a plain background (or a real photo of the work).
- A **name** people search for: "Knotless braids (waist length)".
- The **price**, or "from UGX 80,000". Prices save you endless "how much?" chats.
- A short **description**: size, colours, time it takes, what's included.
- A **link** to the product page if you have one.

## Use it

- Share the whole catalogue or one item in chats with one tap.
- Group items into **collections** (e.g. "Braids", "Wigs", "Hair care").
- Keep it updated: remove what's sold out.

> **Action:** Add your 10 best-selling products or services with prices.
MD],
        ]],
        ['Module 2: Answer fast', [
            ['Greetings, away messages and quick replies', 5, <<<'MD'
Fast answers win sales. Three free tools help (Business tools):

## Greeting message

Sent automatically to new customers: "Hi! Thanks for contacting Amara Salon. See our styles and prices in our catalogue. How can we help?"

## Away message

Outside business hours: "We're closed now and will reply from 8am. Book any time here: (your link)."

## Quick replies

Saved answers you insert by typing `/`: `/price`, `/location`, `/payment`, `/booking`. Write your 5–10 most common answers once and save hours.

> **Action:** Set up a greeting, an away message and five quick replies.
MD],
            ['Labels: never forget a customer', 4, <<<'MD'
**Labels** colour-code chats so nothing gets lost: *New customer*, *Waiting for payment*, *Paid*, *Delivered*, *VIP*.

## A simple routine

- Label each new chat.
- Every evening, open **Waiting for payment** and follow up politely.
- After delivery, label **Delivered** and send a thank-you with a review link.

Labels also make broadcast lists easier: send an offer to everyone labelled *VIP*.

> **Action:** Create five labels and label your open chats.
MD],
        ]],
        ['Module 3: Reach more people', [
            ['Status: your free daily advert', 5, <<<'MD'
Everyone who saved your number sees your **Status**. It's free advertising that lasts 24 hours.

## What to post

- New arrivals, today's special, before/after photos.
- Short videos of your work (reuse your Reels and TikToks).
- Customer reviews (screenshot, with permission).
- Behind the scenes, your team, your opening time.
- A clear offer once or twice a week, with your short link.

## Tips

- Post **1–3 times a day**, not 20.
- Add text: many people watch without sound.
- Ask customers to **save your number** so they see your Status.

> **Action:** Post three Status updates this week: one product, one review, one behind the scenes.
MD],
            ['Broadcasts, groups and channels', 6, <<<'MD'
## Broadcast lists

Send one message to many people; each receives it as a private chat. **They only receive it if they saved your number**, so ask customers to save it. Keep lists by interest (VIP, braids, wigs) and send at most once or twice a week.

## Groups

Good for communities (a class, an event team), not for adverts. Members see each other's numbers, and promotional groups get muted fast.

## Channels

One-way updates that anyone can follow without sharing their number. Good for regular news and offers.

## Respect people's choice

Only add people who agreed. Give them a way to stop ("Reply STOP and we'll remove you") and honour it. Too many messages and people block you, which can get your number restricted.

> **Action:** Create a broadcast list for your best customers and send one useful message.
MD],
            ['From social media to WhatsApp', 5, str_replace('APPNAME', config('app.name'), <<<'MD'
Make it easy for people who find you on social media to message you.

- **Click-to-chat link:** `wa.me/2567XXXXXXXX` opens a chat with you. Add `?text=Hi, I'd like to book` to pre-fill a message.
- In APPNAME, add your WhatsApp number to your **public page** for a big "Chat on WhatsApp" button, and put that page in your bios.
- Make a **short link** to your wa.me link in Links & QR codes to count clicks, and print its **QR code** on flyers, receipts and your shop window.
- End posts with "WhatsApp us on 0772…" or "Link in bio to order".

> **Action:** Create a click-to-chat short link and QR code, and add it to your bio and counter.
MD)],
        ]],
    ],
    'questions' => [
        ['Why should you add prices to your WhatsApp catalogue?', ['WhatsApp requires it', 'It saves endless "how much?" chats and builds trust', 'It hides the product', 'It makes photos load faster'], 1, 'Clear prices answer the most common question and make customers more confident.'],
        ['What is a quick reply?', ['A reply sent by AI', 'A saved answer you insert by typing /', 'A missed call', 'An automatic payment'], 1, 'Quick replies are saved answers you insert with a shortcut like /price.'],
        ['When is the away message sent?', ['To every message', 'When you are outside business hours', 'Only to new customers', 'Never'], 1, 'The away message answers automatically when you are closed or away.'],
        ['Who receives your broadcast list messages?', ['Everyone on WhatsApp', 'Only people on the list who saved your number', 'Only group admins', 'People who follow your Status'], 1, 'Broadcasts only reach contacts who saved your number.'],
        ['How often should you post to your Status?', ['20 times a day', '1–3 times a day', 'Once a year', 'Never'], 1, 'A few useful updates a day keep you visible without annoying people.'],
        ['What does a wa.me link do?', ['Opens a chat with your number', 'Deletes your chats', 'Shows your location', 'Creates a group'], 0, 'wa.me/yournumber opens a WhatsApp chat with you, optionally with a pre-filled message.'],
        ['Why are groups usually bad for adverts?', ['They are paid', 'Promotional groups get muted and members see each other\'s numbers', 'They can\'t have photos', 'They need a website'], 1, 'People mute advert groups quickly, and members can see each other\'s numbers.'],
        ['What should you do when someone asks to stop receiving messages?', ['Ignore it', 'Remove them and stop messaging', 'Add them to a group', 'Send more offers'], 1, 'Respect opt-outs. It keeps your number in good standing and your customers happy.'],
    ],
];
