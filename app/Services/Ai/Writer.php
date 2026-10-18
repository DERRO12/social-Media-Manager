<?php

namespace App\Services\Ai;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The AI writing assistant (Claude by Anthropic): captions and hashtags, comment and review replies,
 * and newsletters, written for the client's own business. The admin sets the API key, model and a
 * daily limit per client in Admin → Settings → AI assistant.
 */
class Writer
{
    public const MODELS = [
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (recommended: great quality, fair price)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (fastest, cheapest)',
        'claude-opus-5-5' => 'Claude Opus 5.5 (best writing, most expensive)',
    ];

    public const TONES = ['friendly' => 'Friendly', 'professional' => 'Professional', 'fun' => 'Fun and playful', 'bold' => 'Bold and exciting', 'inspiring' => 'Inspiring'];

    public const LANGUAGES = ['English', 'Luganda', 'Swahili', 'French', 'Kinyarwanda', 'Arabic', 'Spanish', 'Portuguese'];

    public function enabled(): bool
    {
        return filled(Setting::get('ai.api_key'));
    }

    public function dailyLimit(): int
    {
        return (int) Setting::get('ai.daily_limit', 40);
    }

    public function usedToday(User $user): int
    {
        return (int) Cache::get($this->counterKey($user), 0);
    }

    public function remaining(User $user): int
    {
        return $user->isAdmin() ? PHP_INT_MAX : max(0, $this->dailyLimit() - $this->usedToday($user));
    }

    private function counterKey(User $user): string
    {
        return 'ai-uses:'.$user->id.':'.now()->toDateString();
    }

    /** @return array{captions: string[], hashtags: string[], title: string} */
    public function captions(User $user, string $topic, array $platforms, string $tone, string $language): array
    {
        $data = $this->json($user, <<<PROMPT
        Write 3 different social media captions for this post: {$topic}

        Platforms: {$this->list($platforms, 'all major social networks')}. Tone: {$this->tone($tone)}. Language: {$language}.
        Each caption: a strong first line (hook), 1–3 short sentences, a clear call to action, a fitting emoji or two. Under 600 characters.
        Make them different: one short, one storytelling, one with a question to get comments.
        Also suggest 8–15 relevant hashtags (mix of popular and specific/local ones, no spaces) and a catchy video title under 90 characters.

        Reply with only JSON: {"captions": ["…", "…", "…"], "hashtags": ["#…"], "title": "…"}
        PROMPT, 1200);

        return [
            'captions' => array_values(array_filter(array_map('trim', (array) ($data['captions'] ?? [])))),
            'hashtags' => array_values(array_unique(array_map(fn ($h) => '#'.ltrim(str_replace(' ', '', $h), '#'), (array) ($data['hashtags'] ?? [])))),
            'title' => Str::limit(trim((string) ($data['title'] ?? '')), 100, ''),
        ];
    }

    /** A reply to a comment or a Google review, in the business's voice. */
    public function reply(User $user, string $comment, ?string $author, ?string $context, string $tone = 'friendly'): string
    {
        $text = $this->ask($user, <<<PROMPT
        Write a reply from the business to this comment{$this->suffix($context, ' on "%s"')}.
        Comment by {$this->or($author, 'a follower')}: "{$comment}"

        Tone: {$this->tone($tone)}. Reply in the same language as the comment. Thank them by first name if known.
        If it is a complaint or a low star rating: apologise sincerely, don't argue, and invite them to contact the business privately.
        If it is a question you can't answer from the business details, say you'll message them.
        1–3 sentences. No hashtags. Reply with only the text of the reply.
        PROMPT, 300);

        return trim($text, " \n\"");
    }

    /** @return array{subject: string, preheader: string, body: string} */
    public function newsletter(User $user, string $topic, string $tone = 'friendly'): array
    {
        $data = $this->json($user, <<<PROMPT
        Write an email newsletter to the business's subscribers about: {$topic}

        Tone: {$this->tone($tone)}. Start the body with "Hi {first_name}," exactly (it gets replaced with each person's name).
        Use Markdown: short paragraphs, maybe one ## heading, **bold** for the key point, and one call-to-action link written as [Button text](https://) for the owner to fill in.
        150–300 words. Personal, not salesy. End with a friendly sign-off from the business.
        Subject under 60 characters that makes people open it. Preheader under 100 characters.

        Reply with only JSON: {"subject": "…", "preheader": "…", "body": "…"}
        PROMPT, 1500);

        return [
            'subject' => Str::limit(trim((string) ($data['subject'] ?? '')), 150, ''),
            'preheader' => Str::limit(trim((string) ($data['preheader'] ?? '')), 150, ''),
            'body' => trim((string) ($data['body'] ?? '')),
        ];
    }

    /** One marketing SMS, short enough to be a single message once the opt-out link is added. */
    public function sms(User $user, string $topic): string
    {
        $text = $this->ask($user, <<<PROMPT
        Write one marketing SMS about: {$topic}

        Rules: at most 110 characters (a link is added after it), plain text, no emoji, no hashtags.
        Start with "Hi {first_name}," only if it fits. Include the business name, the offer or news, and one clear action
        (visit, call, reply or book). Write like a person, not a robot. Reply with only the SMS text.
        PROMPT, 200);

        return Str::limit(trim($text, " \n\""), 160, '');
    }

    /** Check the key works (Admin → Settings). Returns null when fine, otherwise the problem. */
    public function test(string $key, string $model): ?string
    {
        try {
            $this->call($key, $model, 'You are a test.', 'Reply with the word OK.', 10);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    private function json(User $user, string $prompt, int $maxTokens): array
    {
        $text = $this->ask($user, $prompt, $maxTokens);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        if (! is_array($data)) {
            throw new RuntimeException('The AI answer came back in an unexpected format. Please try again.');
        }

        return $data;
    }

    private function ask(User $user, string $prompt, int $maxTokens): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The AI assistant isn’t switched on yet. The site admin can add it in Admin → Settings.');
        }

        if ($this->remaining($user) <= 0) {
            throw new RuntimeException('You’ve used today’s '.$this->dailyLimit().' AI requests. They reset at midnight.');
        }

        $text = $this->call(Setting::get('ai.api_key'), Setting::get('ai.model', array_key_first(self::MODELS)), $this->system($user), $prompt, $maxTokens);

        Cache::add($this->counterKey($user), 0, now()->endOfDay());
        Cache::increment($this->counterKey($user));

        return $text;
    }

    private function call(string $key, string $model, string $system, string $prompt, int $maxTokens): string
    {
        try {
            $response = Http::timeout(60)->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Couldn’t reach the AI service. Please try again in a moment.');
        }

        if ($response->failed()) {
            throw new RuntimeException(match ($response->status()) {
                401 => 'The AI API key is not valid. The site admin should check it in Admin → Settings.',
                429, 529 => 'The AI service is busy. Please try again in a minute.',
                default => 'The AI service returned an error: '.Str::limit((string) $response->json('error.message', $response->body()), 200),
            });
        }

        return collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");
    }

    /** Who the assistant is writing for, so the words fit the business. */
    private function system(User $user): string
    {
        $b = $user->businessProfile;
        $p = $user->publicProfile;

        $about = array_filter([
            'Business or creator name' => $b?->name ?: $p?->display_name ?: $user->name,
            'What they do' => $b?->primary_category ?: $p?->headline,
            'Location' => $b?->city ?: $p?->location,
            'Description' => Str::limit((string) ($b?->description ?: $p?->bio), 500),
            'Services' => $b?->services ? implode(', ', array_slice($b->services, 0, 15)) : null,
            'Keywords' => $b?->keywords,
            'Website' => $b?->website ?: $p?->website,
        ]);

        $lines = collect($about)->map(fn ($v, $k) => "- {$k}: {$v}")->implode("\n");

        return "You are an expert social media marketer writing for a small business or creator, mostly in Africa.\n"
            ."Write in a natural, human voice. Never invent prices, discounts, phone numbers or facts that aren't given.\n"
            ."About the business:\n".($lines ?: '- (no details given)');
    }

    private function tone(string $tone): string
    {
        return self::TONES[$tone] ?? 'Friendly';
    }

    private function list(array $items, string $empty): string
    {
        return $items ? implode(', ', $items) : $empty;
    }

    private function or(?string $value, string $fallback): string
    {
        return filled($value) ? $value : $fallback;
    }

    private function suffix(?string $value, string $format): string
    {
        return filled($value) ? sprintf($format, Str::limit($value, 120)) : '';
    }
}
