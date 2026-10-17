<?php

namespace App\Services\Academy;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * A complete beginner course the admin can add with one click (Admin → Courses), so the Academy
 * isn't empty on day one. It's created as a draft: the admin can edit any text, add YouTube videos
 * to lessons, then publish.
 */
class StarterCourse
{
    public const SLUG = 'social-media-marketing-foundations';

    public function install(): Course
    {
        if ($existing = Course::firstWhere('slug', self::SLUG)) {
            return $existing;
        }

        return DB::transaction(function () {
            $course = Course::create([
                'title' => 'Social Media Marketing Foundations',
                'slug' => self::SLUG,
                'summary' => 'Plan, create and grow on social media with a clear strategy, content people want, and numbers that show what works.',
                'description' => $this->description(),
                'level' => 'beginner',
                'is_published' => false,
                'is_featured' => true,
                'certificate_enabled' => true,
                'pass_mark' => 70,
                'sort' => 0,
            ]);

            foreach ($this->modules() as $m => [$moduleTitle, $lessons]) {
                $module = $course->modules()->create(['title' => $moduleTitle, 'sort' => $m]);

                foreach ($lessons as $l => [$title, $minutes, $content]) {
                    $course->lessons()->create([
                        'course_module_id' => $module->id,
                        'title' => $title,
                        'content' => trim($content),
                        'duration_minutes' => $minutes,
                        'is_preview' => $m === 0 && $l === 0,
                        'sort' => $l,
                    ]);
                }
            }

            foreach ($this->questions() as $i => [$question, $options, $correct, $explanation]) {
                $course->questions()->create([
                    'question' => $question,
                    'options' => $options,
                    'correct_option' => $correct,
                    'explanation' => $explanation,
                    'sort' => $i,
                ]);
            }

            return $course;
        });
    }

    private function description(): string
    {
        return <<<'MD'
Social media can feel like shouting into a crowded room. This course gives you a simple, repeatable system instead.

**You will learn how to:**

- define who you are talking to and what you want them to do
- pick the platforms that fit your audience instead of being everywhere
- plan content pillars and short videos people actually watch
- write captions and calls to action that get responses
- build a posting rhythm and look after your community
- read your numbers and improve month after month

It's built for business owners, freelancers and new social media managers. No experience needed. Finish every lesson and pass the final quiz to earn your certificate.
MD;
    }

    /** @return array<int, array{0: string, 1: array<int, array{0: string, 1: int, 2: string}>}> */
    private function modules(): array
    {
        return [
            ['Module 1: Foundations', [
                ['What social media marketing really is', 6, <<<'MD'
Social media marketing is using social platforms to **build relationships with the people who could become your customers**, and then turning some of that attention into action: a visit, an enquiry, a sale.

It is *not* just posting adverts. People open TikTok, Instagram or Facebook to be entertained, informed or inspired, not to be sold to. Brands that win give people something they value first.

## The three jobs of your social media

1. **Get discovered.** Reach people who don't know you yet.
2. **Build trust.** Show your expertise, your results and the people behind the business.
3. **Convert.** Make it easy for interested people to take the next step.

Most accounts only do the third job and wonder why nobody responds. In this course you'll build a system that does all three.

> **Action step:** Write one sentence answering: *"Why would someone follow my account even if they're not ready to buy?"* Keep it; you'll use it in the next lessons.
MD],
                ['Know your audience', 7, <<<'MD'
You can't create content people love if you don't know who they are. A simple **audience persona** fixes that.

## Build a one-page persona

Answer these about your ideal customer:

- **Who are they?** Age range, location, job or life stage.
- **What do they want?** The result they're hoping for.
- **What's in their way?** Their problems, fears and common mistakes.
- **Where do they spend time online?** Which apps, which creators they follow.
- **What words do they use?** Copy real phrases from comments, reviews and messages.

## Why it matters

When you know their problems, content ideas become easy: every problem is a post. When you use their words, your captions sound like a friend, not an advert.

> **Action step:** Read 20 comments or reviews from your customers or a competitor's. Write down five problems and five phrases people repeat.
MD],
                ['Setting goals you can measure', 6, <<<'MD'
"Grow on social media" is a wish, not a goal. Use **SMART** goals: Specific, Measurable, Achievable, Relevant and Time-bound.

**Example:** *"Get 30 WhatsApp enquiries from Instagram in the next 60 days."*

## Match the goal to the metric

| Goal | What to measure |
|---|---|
| Awareness | Reach, views, new followers |
| Engagement | Likes, comments, shares, saves |
| Traffic | Link clicks, profile visits |
| Sales or leads | Enquiries, sign-ups, purchases |

Pick **one main goal per quarter**. You can track the rest, but decisions should serve the main goal.

> **Action step:** Write your SMART goal for the next 90 days and the one number you'll check every week.
MD],
            ]],
            ['Module 2: Platforms and content', [
                ['Choosing the right platforms', 6, <<<'MD'
You don't need to be everywhere. You need to be **where your audience already is**, and do it well.

## A quick guide

- **Facebook:** broad adult audience, local communities and groups, great for local businesses.
- **Instagram:** visual brands, lifestyle, products, services with a "look"; Reels for reach.
- **TikTok:** fastest discovery for new accounts; entertaining, educational short video.
- **YouTube:** long-term search traffic; tutorials and in-depth content; Shorts for reach.
- **LinkedIn:** B2B, professional services, recruiting, personal brands of experts.
- **X:** news, conversation and fast-moving industries.

## Start with one or two

Choose **one main platform** you'll master and **one secondary** platform you'll repurpose content to. Add more only when you have a routine that works.

> **Action step:** Using your persona, choose your main and secondary platforms and write one sentence on why.
MD],
                ['Content pillars and the value-first mix', 7, <<<'MD'
**Content pillars** are the 3 to 5 topics your account is known for. They stop you from staring at a blank screen and make your account easy to understand.

## Example: a hair salon

1. Hair care tips (educate)
2. Transformations (prove results)
3. Behind the scenes and the team (build trust)
4. Offers and booking reminders (convert)

## Keep the mix value-first

A useful rule: roughly **80% of posts give value** (teach, entertain, inspire) and **20% ask for something** (book, buy, sign up). When most posts help people, the occasional offer feels welcome instead of pushy.

> **Action step:** Write your 3 to 5 pillars and list five post ideas under each. That's 15 to 25 posts planned already.
MD],
                ['Short-form video that gets watched', 8, <<<'MD'
Short vertical video (Reels, TikTok, Shorts) is the fastest way to reach new people. Platforms show more of what people **watch to the end**, so every second counts.

## The first two seconds

Start with a **hook** that makes people want the rest:

- a bold statement: *"Stop washing your hair every day."*
- a question: *"Why do your posts get no views?"*
- a visible result: show the "after" first.

## Make it easy to watch

- Film **vertically (9:16)** in good natural light.
- Add **on-screen captions**; many people watch without sound.
- Keep it tight: cut pauses and get to the point.
- End with one clear next step: follow, comment, or visit the link.

> **Action step:** Film three videos for one pillar. Spend most of your effort on the first line.
MD],
            ]],
            ['Module 3: Growth and engagement', [
                ['Captions, hashtags and calls to action', 7, <<<'MD'
## Captions

The first line is a second hook: it shows before "more". Then add **context or value**, and finish with **one call to action**.

## Calls to action (CTAs)

Ask for one thing only, and make it easy:

- *"Save this for your next shoot."*
- *"Comment 'PRICE' and we'll send the list."*
- *"Tap the link in bio to book."*

Questions in captions invite comments, and comments tell platforms your post is worth showing to more people.

## Hashtags and keywords

Use a few **specific, relevant** hashtags rather than many generic ones, and put **searchable keywords** in your caption and on-screen text. People search social apps like search engines now.

> **Action step:** Rewrite the captions for your three videos with a hook, value and one CTA.
MD],
                ['Posting consistently with a content calendar', 6, <<<'MD'
Consistency beats intensity. Posting three times a week **every week** beats posting every day for a fortnight and then disappearing.

## Build a simple calendar

1. Decide your rhythm (for example: Monday tip, Wednesday result, Friday behind the scenes).
2. Batch-create: film or design a week of content in one session.
3. **Schedule** posts ahead so they go out even on busy days. In this app, use **Create post → Schedule** to publish to all your accounts at once.

## Repurpose, don't restart

One good video can become a Reel, a TikTok, a YouTube Short, a LinkedIn post with the key point, and a Facebook post. Adjust the caption for each platform.

> **Action step:** Plan and schedule next week's posts in one sitting.
MD],
                ['Community management: replies and messages', 6, <<<'MD'
Social media is a conversation. **Fast, friendly replies** turn followers into fans and fans into customers.

## Good habits

- Reply to comments within the first hour of posting when you can; early activity helps reach.
- Answer questions publicly so others benefit, then move private details to messages.
- Thank people for positive feedback and use their names.
- Handle complaints calmly: acknowledge, apologise if needed, move to private messages, and follow up.

## Make it manageable

Check comments at set times each day instead of all day. In this app, the **Inbox** collects comments from all your platforms so you can answer them in one place.

> **Action step:** Write three saved replies: a thank-you, an answer to your most common question, and a complaint response.
MD],
            ]],
            ['Module 4: Measure and improve', [
                ['Metrics that matter', 7, <<<'MD'
Numbers tell you what to do more of. Focus on metrics linked to your goal, not vanity numbers.

## Key metrics

- **Reach:** how many different people saw your content.
- **Engagement rate:** interactions divided by reach (or followers), times 100.
  *Example: 45 likes + 10 comments + 5 shares = 60 interactions. 60 ÷ 2,000 reach × 100 = **3%**.*
- **Saves and shares:** strong signals that content is genuinely useful.
- **Click-through rate (CTR):** clicks divided by people who saw the link.
- **Conversions:** the enquiries, sign-ups or sales your goal is about.

## Look at patterns, not single posts

Compare a month of posts. Which pillar, format, hook or posting time does best? That's what to make more of.

> **Action step:** Calculate the engagement rate of your last five posts and note which did best and why.
MD],
                ['Testing and improving', 6, <<<'MD'
The best social media managers treat every month as an experiment.

## Run simple tests

Change **one thing at a time** so you know what made the difference:

- two different hooks for the same video idea
- posting time: morning vs evening
- caption length: short vs detailed
- CTA: "comment" vs "link in bio"

Give each test enough posts (at least three to five each way) before deciding.

## The monthly review

1. What worked best? Do more of it.
2. What flopped? Drop it or change one thing.
3. What will you test next month?

> **Action step:** Choose one test for the next two weeks and write down what you'll compare.
MD],
                ['Your 30-day social media plan', 8, <<<'MD'
Time to put everything together into a plan you can start today.

## Week 1: Set up

- Finalise your persona, SMART goal and main platforms.
- Update your profile: clear photo or logo, what you do, who it's for, and one link.
- Choose your content pillars.

## Week 2: Create

- Batch-create two weeks of posts, including at least three short videos.
- Write captions with hooks and one CTA each.
- Schedule everything.

## Week 3: Engage

- Reply to every comment and message daily.
- Engage with 10 accounts in your niche each day: real comments, not emojis.

## Week 4: Review

- Check your metrics against your goal.
- Pick what to double down on and one test for next month.

Congratulations on finishing the lessons. Take the **final quiz** to earn your certificate!
MD],
            ]],
        ];
    }

    /** @return array<int, array{0: string, 1: string[], 2: int, 3: string}> */
    private function questions(): array
    {
        return [
            ['What is the main purpose of social media marketing?', [
                'Posting adverts as often as possible',
                'Building relationships with potential customers and turning attention into action',
                'Getting as many followers as possible, whoever they are',
                'Copying what competitors post',
            ], 1, 'Social media works best when you build relationships first; selling comes from trust.'],
            ['Which of these is a SMART goal?', [
                'Grow on Instagram',
                'Become famous on TikTok',
                'Get 30 WhatsApp enquiries from Instagram in the next 60 days',
                'Post more often',
            ], 2, 'It is specific, measurable, achievable, relevant and has a deadline.'],
            ['Where should you find the words your audience uses?', [
                'In a dictionary',
                'In real comments, reviews and messages from customers',
                'In your competitors\' adverts only',
                'You should make them up',
            ], 1, 'Copying real phrases makes your captions sound like a friend, not an advert.'],
            ['How many platforms should a new account focus on first?', [
                'Every platform at once',
                'None until you have 1,000 followers',
                'One main platform and one secondary platform',
                'Only the newest platform',
            ], 2, 'Mastering one platform and repurposing to a second is more effective than spreading thin.'],
            ['What are content pillars?', [
                'The 3 to 5 topics your account is known for',
                'Paid adverts',
                'The posting times you use',
                'Your follower count milestones',
            ], 0, 'Pillars make your account easy to understand and make planning content much easier.'],
            ['In a value-first content mix, roughly what share of posts should give value rather than ask for a sale?', [
                '20%', '50%', '80%', '100%',
            ], 2, 'About 80% value and 20% asks keeps offers welcome instead of pushy.'],
            ['What matters most in the first two seconds of a short video?', [
                'Your logo animation',
                'A strong hook that makes people want to keep watching',
                'Background music',
                'A long introduction about your business',
            ], 1, 'Platforms show more of what people watch to the end, and the hook decides whether they stay.'],
            ['Why add on-screen captions to videos?', [
                'They are required by every platform',
                'Many people watch without sound',
                'They make the video longer',
                'They replace the need for a hook',
            ], 1, 'A large share of viewers scroll with the sound off; captions keep them watching.'],
            ['How many calls to action should one post usually have?', [
                'One clear call to action', 'Three or more', 'None', 'One per hashtag',
            ], 0, 'One clear ask is easier to follow than several competing ones.'],
            ['A post reached 2,000 people and got 60 interactions. What is its engagement rate by reach?', [
                '0.3%', '3%', '30%', '60%',
            ], 1, '60 ÷ 2,000 × 100 = 3%.'],
            ['When testing what works, how many things should you change at a time?', [
                'As many as possible', 'One thing at a time', 'Nothing, just post more', 'Two things every day',
            ], 1, 'Changing one variable lets you know what caused the difference.'],
            ['What is the best way to handle a public complaint?', [
                'Delete it immediately',
                'Argue until they agree',
                'Acknowledge it calmly, move details to private messages, and follow up',
                'Ignore it',
            ], 2, 'A calm public response shows others you care, and private messages protect personal details.'],
        ];
    }
}
