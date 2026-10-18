<?php

namespace App\Support;

/**
 * Ready-made campaigns clients start from. Merge tags: {first_name}, {name}, {sender}, {review_link}, {page_link}.
 * Email bodies are Markdown; SMS are plain text (an opt-out link is added automatically).
 */
class CampaignTemplates
{
    /** key => [label, icon, subject, body] */
    public const EMAIL = [
        'welcome' => ['Welcome new subscribers', 'hand-raised', 'Welcome to {sender}, {first_name}!',
            "Hi {first_name},\n\nThank you for joining us! We're so glad you're here.\n\nHere's what you can expect from us:\n\n- **News first**: new products and services before anyone else\n- **Members-only offers**, a few times a month\n- **Useful tips** from our team\n\nTo say thank you, here's **10% off your first visit or order**. Just mention this email.\n\n[See what we offer]({page_link})\n\nTalk soon,\n{sender}"],
        'news' => ['Monthly news', 'newspaper', 'What’s new at {sender} this month',
            "Hi {first_name},\n\nHere’s what’s new this month:\n\n## Something new\nTell people about a new product, service or result.\n\n## A tip for you\nShare one useful tip your customers will love.\n\n## This month’s offer\nGive a reason to act now, with a clear deadline.\n\n[Book or order now](https://)\n\nThanks for being part of our community!"],
        'offer' => ['Special offer', 'tag', '{first_name}, 20% off this week only',
            "Hi {first_name},\n\nAs a thank-you for being on our list, you get **20% off** until Sunday.\n\nJust show this email or use the code **THANKYOU20**.\n\n[Claim your offer](https://)\n\nSee you soon!"],
        'flash' => ['Flash sale (24 hours)', 'bolt', '⚡ 24 hours only, {first_name}',
            "Hi {first_name},\n\nFor the next **24 hours only**: (your offer, e.g. buy one, get one free).\n\nWhen it's gone, it's gone.\n\n[Shop the flash sale](https://)\n\nHurry!\n{sender}"],
        'launch' => ['New product or service', 'rocket-launch', 'Introducing something new from {sender}',
            "Hi {first_name},\n\nWe've been working on something special, and it's finally here:\n\n## (Name of the new product or service)\n\n(Two or three sentences: what it is, who it's for, and why they'll love it.)\n\n**Launch offer:** (e.g. 15% off this week).\n\n[Be one of the first to try it](https://)\n\n{sender}"],
        'event' => ['Event invitation', 'calendar-days', 'You’re invited, {first_name}!',
            "Hi {first_name},\n\nWe'd love to see you at **(event name)**.\n\n- **When:** (date and time)\n- **Where:** (place)\n- **What:** (what will happen: demos, music, food, offers…)\n\nSpaces are limited, so let us know you're coming.\n\n[Reserve your spot](https://)\n\nSee you there!\n{sender}"],
        'tips' => ['Tips and advice', 'light-bulb', '3 quick tips from {sender}',
            "Hi {first_name},\n\nHere are three quick tips we share with our customers all the time:\n\n**1. (First tip)**\nOne or two sentences.\n\n**2. (Second tip)**\nOne or two sentences.\n\n**3. (Third tip)**\nOne or two sentences.\n\nWant help with this? Just reply to this email.\n\n{sender}"],
        'story' => ['Customer story', 'chat-bubble-bottom-center-text', 'How (customer) got (result) with us',
            "Hi {first_name},\n\nWe love sharing our customers' wins. Meet **(customer name)**.\n\n> \"(A short quote from the customer about their experience.)\"\n\n(Two or three sentences: their problem, what we did, and the result.)\n\nWant the same for you?\n\n[Book now](https://)\n\n{sender}"],
        'video' => ['New video', 'play-circle', 'New video: (your video title)',
            "Hi {first_name},\n\nI just posted a new video you might like:\n\n**(Your video title)**\n\nIn it I show (what people will learn or see).\n\n[Watch it here](https://)\n\nReply and tell me what you think!"],
        'reviews' => ['Ask for reviews', 'star', 'Could you do us a quick favour, {first_name}?',
            "Hi {first_name},\n\nThank you for choosing us! We'd love to hear how it went.\n\nCould you take **one minute** to leave us a Google review? It helps other people find us, and we read every single one.\n\n[Leave a Google review]({review_link})\n\nThank you so much for your support!"],
        'holiday' => ['Holiday greetings', 'gift', 'Season’s greetings from {sender}',
            "Hi {first_name},\n\nFrom all of us at {sender}: **thank you** for your support this year. We couldn't do it without you.\n\n**Holiday opening hours:** (your hours).\n\nWishing you and your family a joyful season and a wonderful new year!\n\n{sender}"],
        'winback' => ['We miss you', 'heart', 'We miss you, {first_name}',
            "Hi {first_name},\n\nIt's been a while, and we miss you!\n\nA lot has changed since your last visit: (one or two things that are new or better).\n\nCome back this month and enjoy **(a welcome-back offer)**.\n\n[Come and see what's new](https://)\n\nHope to see you soon,\n{sender}"],
        'blank' => ['Blank email', 'document', '', ''],
    ];

    /** key => [label, icon, body] */
    public const SMS = [
        'sms_offer' => ['Special offer', 'tag', 'Hi {first_name}! {sender}: 20% off everything this week only. Show this SMS to claim. See you soon!'],
        'sms_flash' => ['Flash sale', 'bolt', '{sender} FLASH SALE: today only, (your offer). Hurry, while stocks last!'],
        'sms_new' => ['New arrival', 'sparkles', 'Hi {first_name}, something new just arrived at {sender}: (product/service). Come and see it this week!'],
        'sms_event' => ['Event invitation', 'calendar-days', 'Hi {first_name}! You’re invited to (event) at {sender} on (date, time). Reply YES to reserve your spot.'],
        'sms_reminder' => ['Appointment reminder', 'clock', 'Hi {first_name}, a friendly reminder from {sender} about your appointment on (date, time). See you then!'],
        'sms_reviews' => ['Ask for a review', 'star', 'Hi {first_name}, thank you for choosing {sender}! Please rate us on Google, it takes 1 minute: {review_link}'],
        'sms_holiday' => ['Holiday greetings', 'gift', 'Season’s greetings from all of us at {sender}! Thank you for your support this year, {first_name}.'],
        'sms_blank' => ['Blank SMS', 'document', ''],
    ];

    /** @return array{channel: string, subject: string, body: string} */
    public static function make(string $key, string $sender): array
    {
        $fill = fn (string $text) => str_replace('{sender}', $sender, $text);

        if (isset(self::SMS[$key])) {
            return ['channel' => 'sms', 'subject' => self::SMS[$key][0], 'body' => $fill(self::SMS[$key][2])];
        }

        [, , $subject, $body] = self::EMAIL[$key] ?? self::EMAIL['blank'];

        return ['channel' => 'email', 'subject' => $fill($subject), 'body' => $fill($body)];
    }
}
