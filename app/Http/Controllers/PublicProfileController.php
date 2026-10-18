<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\ProfilePhoto;
use App\Models\PublicProfile;
use App\Services\Seo\IndexNow;
use App\Support\YouTube;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Public creator pages, per-video and per-photo pages, the sitemap and robots.txt: what lets search engines find content. */
class PublicProfileController extends Controller
{
    public function show(Request $request, PublicProfile $profile): View
    {
        $this->ensureVisible($request, $profile);

        return view('profiles.show', [
            'profile' => $profile->load('user.socialAccounts', 'user.businessProfile'),
            'items' => $profile->items()->with('account')->paginate(24),
            'photos' => $request->integer('page', 1) > 1 ? collect() : $profile->photos()->limit(24)->get(),
        ]);
    }

    public function photo(Request $request, PublicProfile $profile, ProfilePhoto $photo, ?string $slug = null): View|\Illuminate\Http\RedirectResponse
    {
        $this->ensureVisible($request, $profile);
        abort_unless($photo->user_id === $profile->user_id && $photo->is_public, 404);

        if ($slug !== $photo->slug()) {
            return redirect()->to($photo->pageUrl($profile), 301);
        }

        $profile->load('user.socialAccounts', 'user.businessProfile');

        return view('profiles.photo', [
            'profile' => $profile,
            'photo' => $photo,
            'more' => $profile->photos()->where('id', '!=', $photo->id)->limit(8)->get(),
        ]);
    }

    public function item(Request $request, PublicProfile $profile, ContentItem $item, ?string $slug = null): View|\Illuminate\Http\RedirectResponse
    {
        $this->ensureVisible($request, $profile);
        $item->load('account');
        abort_unless($item->account->user_id === $profile->user_id && ! $item->hidden_from_profile && $item->permalink, 404);

        // Keep one address per video (the one with the right title slug) so search engines don't see duplicates.
        if ($slug !== self::slug($item)) {
            return redirect()->route('profiles.item', [$profile, $item, self::slug($item)], 301);
        }

        return view('profiles.item', [
            'profile' => $profile,
            'item' => $item,
            'embedUrl' => $item->account->platform === Platform::YouTube ? YouTube::embedUrl($item->platform_content_id) : null,
            'more' => $profile->items()->with('account')->where('content_items.id', '!=', $item->id)->limit(6)->get(),
        ]);
    }

    public static function slug(ContentItem $item): string
    {
        return Str::slug(Str::limit($item->title ?: $item->caption ?: 'post', 70, '')) ?: 'post';
    }

    public function sitemap(): Response
    {
        $urls = collect([
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('courses.index'), 'priority' => '0.8'],
            ['loc' => route('privacy'), 'priority' => '0.2'],
            ['loc' => route('terms'), 'priority' => '0.2'],
        ]);

        foreach (Course::published()->get() as $course) {
            $urls->push(['loc' => route('courses.show', $course), 'lastmod' => $course->updated_at, 'priority' => '0.7']);
        }

        foreach (PublicProfile::where('is_public', true)->with('user.subscription', 'user.socialAccounts')->get()->filter(fn ($p) => self::isLive($p)) as $profile) {
            $photos = $profile->photos()->limit(1000)->get();

            // Image sitemap entries: Google Images finds photos through the pages that show them.
            $urls->push([
                'loc' => $profile->url(),
                'lastmod' => max($profile->updated_at, $photos->max('updated_at') ?? $profile->updated_at),
                'priority' => '0.6',
                'images' => array_values(array_filter([
                    $profile->avatar_path ? $profile->avatarUrl() : null,
                    ...$photos->take(24)->map->url(),
                ])),
            ]);

            foreach ($photos as $photo) {
                $urls->push(['loc' => $photo->pageUrl($profile), 'lastmod' => $photo->updated_at, 'priority' => '0.5', 'images' => [$photo->url()]]);
            }

            foreach ($profile->items()->with('account')->limit(500)->get() as $item) {
                $urls->push([
                    'loc' => route('profiles.item', [$profile, $item, self::slug($item)]),
                    'lastmod' => $item->published_at ?? $item->updated_at,
                    'priority' => '0.5',
                ]);
            }
        }

        return response()->view('seo.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /settings',
            'Disallow: /billing',
            'Disallow: /livewire',
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** The IndexNow key file search engines read to check that submissions come from this site. */
    public function indexNowKey(IndexNow $indexNow): Response
    {
        abort_unless($indexNow->enabled(), 404);

        return response($indexNow->key(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Only public pages are visible, except to their owner (for previewing) and the admin. */
    private function ensureVisible(Request $request, PublicProfile $profile): void
    {
        $viewer = $request->user();

        $isOwnerOrAdmin = $viewer?->id === $profile->user_id || $viewer?->isAdmin();

        abort_unless($isOwnerOrAdmin || self::isLive($profile), 404);
    }

    /** Public, and the owner is an active (trial or paid), non-suspended client. */
    public static function isLive(PublicProfile $profile): bool
    {
        return $profile->is_public && $profile->user && ! $profile->user->isSuspended() && $profile->user->hasAccess();
    }
}
