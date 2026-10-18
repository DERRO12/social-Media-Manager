<?php

namespace App\Services\Seo;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Checks one web page for the on-page SEO basics that matter most, and explains each fix in plain words.
 * Each check is pass (1 point), warn (½) or fail (0); the score is the percentage of points.
 */
class SeoAuditor
{
    public function __construct(private SafeFetcher $fetcher) {}

    /**
     * @return array{url: string, score: int, checks: array<int, array{key: string, label: string, status: string, detail: string, fix: ?string}>}
     */
    public function audit(string $url): array
    {
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        ['response' => $response, 'url' => $finalUrl, 'seconds' => $seconds] = $this->fetcher->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("The page answered with error {$response->status()}, so it can’t be checked. Search engines can’t index it either.");
        }

        if (! str_contains(strtolower((string) $response->header('Content-Type')), 'html')) {
            throw new RuntimeException('That address isn’t a web page (it returned '.($response->header('Content-Type') ?: 'an unknown file type').').');
        }

        $html = $response->body();
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new DOMXPath($dom);

        $text = fn (string $query) => trim((string) ($x->query($query)->item(0)?->textContent ?? ''));
        $attr = fn (string $query, string $name) => trim((string) ($x->query($query)->item(0)?->attributes?->getNamedItem($name)?->nodeValue ?? ''));

        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail, ?string $fix = null) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail', 'fix');
        };

        // HTTPS
        str_starts_with($finalUrl, 'https://')
            ? $add('https', 'Secure connection (HTTPS)', 'pass', 'The page loads over HTTPS.')
            : $add('https', 'Secure connection (HTTPS)', 'fail', 'The page loads over plain HTTP.', 'Turn on HTTPS (most hosts and Cloudflare offer free certificates) and redirect HTTP to HTTPS.');

        // Title
        $title = $text('//head/title');
        $len = mb_strlen($title);
        match (true) {
            $len === 0 => $add('title', 'Page title', 'fail', 'There is no title.', 'Add a <title> with your main service and town, about 50–60 characters, e.g. “Bridal Makeup in Kampala | Amara Studio”.'),
            $len < 25 || $len > 65 => $add('title', 'Page title', 'warn', "“{$title}” ({$len} characters).", 'Aim for about 50–60 characters: what you offer, where, and your brand.'),
            default => $add('title', 'Page title', 'pass', "“{$title}” ({$len} characters)."),
        };

        // Meta description
        $description = $attr('//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content');
        $len = mb_strlen($description);
        match (true) {
            $len === 0 => $add('description', 'Meta description', 'fail', 'There is no meta description.', 'Add a 120–160 character summary that makes people want to click in search results.'),
            $len < 70 || $len > 170 => $add('description', 'Meta description', 'warn', "{$len} characters.", 'Aim for about 120–160 characters with a clear benefit and call to action.'),
            default => $add('description', 'Meta description', 'pass', "{$len} characters: “".Str::limit($description, 90).'”'),
        };

        // H1
        $h1s = $x->query('//h1');
        match (true) {
            $h1s->length === 0 => $add('h1', 'Main heading (H1)', 'fail', 'No H1 heading found.', 'Add one H1 that says what the page is about, using the words customers search.'),
            $h1s->length > 1 => $add('h1', 'Main heading (H1)', 'warn', "{$h1s->length} H1 headings found.", 'Use one H1 per page and H2/H3 for sections.'),
            default => $add('h1', 'Main heading (H1)', 'pass', '“'.Str::limit(trim($h1s->item(0)->textContent), 80).'”'),
        };

        // Mobile viewport
        $attr('//meta[@name="viewport"]', 'content')
            ? $add('viewport', 'Mobile-friendly setup', 'pass', 'The page has a mobile viewport tag.')
            : $add('viewport', 'Mobile-friendly setup', 'fail', 'No mobile viewport tag.', 'Add <meta name="viewport" content="width=device-width, initial-scale=1">. Most visitors and Google use mobile.');

        // Indexable
        $robots = strtolower($attr('//meta[translate(@name,"ROBOTS","robots")="robots"]', 'content').' '.$response->header('X-Robots-Tag'));
        str_contains($robots, 'noindex')
            ? $add('indexable', 'Allowed in Google', 'fail', 'The page tells search engines not to index it (noindex).', 'Remove “noindex” from the robots meta tag or X-Robots-Tag header if you want this page in Google.')
            : $add('indexable', 'Allowed in Google', 'pass', 'Nothing blocks search engines from indexing this page.');

        // Canonical
        $attr('//link[@rel="canonical"]', 'href')
            ? $add('canonical', 'Canonical link', 'pass', 'A canonical URL is set.')
            : $add('canonical', 'Canonical link', 'warn', 'No canonical link.', 'Add <link rel="canonical" href="…"> pointing to the main address of this page to avoid duplicates.');

        // Language
        $attr('//html', 'lang')
            ? $add('lang', 'Language set', 'pass', 'The page declares its language.')
            : $add('lang', 'Language set', 'warn', 'The <html> tag has no lang attribute.', 'Add lang="en" (or your language) to the <html> tag.');

        // Image alt text
        $images = $x->query('//img');
        $missing = 0;
        foreach ($images as $img) {
            if (trim((string) $img->getAttribute('alt')) === '') {
                $missing++;
            }
        }
        match (true) {
            $images->length === 0 => $add('alt', 'Image descriptions (alt text)', 'warn', 'No images found.', 'Pages with relevant images (with alt text) usually do better; consider adding photos of your work.'),
            $missing === 0 => $add('alt', 'Image descriptions (alt text)', 'pass', "All {$images->length} images have alt text."),
            $missing / $images->length > 0.3 => $add('alt', 'Image descriptions (alt text)', 'fail', "{$missing} of {$images->length} images have no alt text.", 'Describe each image in its alt attribute, e.g. alt="Wedding cake with gold flowers".'),
            default => $add('alt', 'Image descriptions (alt text)', 'warn', "{$missing} of {$images->length} images have no alt text.", 'Add alt text to the remaining images.'),
        };

        // Content length
        $body = $x->query('//body')->item(0);
        foreach ($x->query('//script|//style|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $words = str_word_count(strip_tags((string) $body?->textContent));
        $words >= 300
            ? $add('content', 'Amount of text', 'pass', "About {$words} words.")
            : $add('content', 'Amount of text', 'warn', "Only about {$words} words.", 'Add useful text: what you offer, who it’s for, prices, FAQs, your area. Google needs words to understand the page.');

        // Social sharing
        ($attr('//meta[@property="og:title"]', 'content') && $attr('//meta[@property="og:image"]', 'content'))
            ? $add('social', 'Social sharing preview', 'pass', 'Open Graph title and image are set.')
            : $add('social', 'Social sharing preview', 'warn', 'Missing Open Graph title or image.', 'Add og:title and og:image so links look good when shared on WhatsApp, Facebook and LinkedIn.');

        // Structured data
        $jsonLd = $x->query('//script[@type="application/ld+json"]')->length;
        str_contains($html, 'application/ld+json') || $jsonLd
            ? $add('schema', 'Structured data', 'pass', 'Structured data (JSON-LD) found.')
            : $add('schema', 'Structured data', 'warn', 'No structured data found.', 'Add LocalBusiness or Organization structured data (JSON-LD) with your name, address, phone and hours.');

        // Speed (server response)
        match (true) {
            $seconds <= 1.5 => $add('speed', 'Server response time', 'pass', "{$seconds} s to load the page."),
            $seconds <= 3.5 => $add('speed', 'Server response time', 'warn', "{$seconds} s to load the page.", 'Speed up hosting or enable caching (e.g. Cloudflare). Slow pages lose visitors.'),
            default => $add('speed', 'Server response time', 'fail', "{$seconds} s to load the page.", 'Your server is slow. Use caching, a faster host, or a CDN.'),
        };

        // robots.txt and sitemap
        $origin = preg_replace('~^(https?://[^/]+).*$~i', '$1', $finalUrl);
        $robotsTxt = $this->tryGet($origin.'/robots.txt');
        $disallowAll = $robotsTxt && preg_match('/user-agent:\s*\*\s*(?:\R(?!user-agent).*)*\R\s*disallow:\s*\/\s*$/im', $robotsTxt);
        match (true) {
            $robotsTxt === null => $add('robots', 'robots.txt', 'warn', 'No robots.txt file found.', 'Add a robots.txt that allows crawling and lists your sitemap.'),
            (bool) $disallowAll => $add('robots', 'robots.txt', 'fail', 'robots.txt blocks all search engines (Disallow: /).', 'Remove “Disallow: /” for User-agent: * unless the whole site should stay out of Google.'),
            default => $add('robots', 'robots.txt', 'pass', 'robots.txt found and allows crawling.'),
        };

        $sitemapUrl = $robotsTxt && preg_match('/^sitemap:\s*(\S+)/im', $robotsTxt, $m) ? $m[1] : $origin.'/sitemap.xml';
        $sitemap = str_starts_with($sitemapUrl, $origin) ? $this->tryGet($sitemapUrl) : null;
        $sitemap && str_contains($sitemap, '<urlset') || $sitemap && str_contains($sitemap, '<sitemapindex')
            ? $add('sitemap', 'XML sitemap', 'pass', 'Sitemap found.')
            : $add('sitemap', 'XML sitemap', 'warn', 'No XML sitemap found.', 'Create a sitemap.xml (most website builders have a setting) and submit it in Google Search Console.');

        $points = collect($checks)->sum(fn ($c) => ['pass' => 1, 'warn' => 0.5, 'fail' => 0][$c['status']]);

        return [
            'url' => $finalUrl,
            'score' => (int) round($points / count($checks) * 100),
            'checks' => $checks,
        ];
    }

    private function tryGet(string $url): ?string
    {
        try {
            $result = $this->fetcher->get($url);

            return $result['response']->successful() ? $result['response']->body() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
