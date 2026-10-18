<?php

use App\Http\Controllers\PublicProfileController;
use App\Models\PublicProfile;
use App\Services\Seo\ImageOptimizer;
use App\Services\Seo\IndexNow;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    public string $handle = '';
    public string $displayName = '';
    public string $headline = '';
    public string $bio = '';
    public string $location = '';
    public string $website = '';
    public bool $isPublic = false;
    public string $seoTitle = '';
    public string $seoDescription = '';
    public bool $showBusiness = false;
    public $avatar = null;
    /** Link-in-bio buttons: [['label' => …, 'url' => …], …] */
    public array $bioLinks = [];
    public string $whatsapp = '';

    public function addLink(): void
    {
        if (count($this->bioLinks) < 8) {
            $this->bioLinks[] = ['label' => '', 'url' => ''];
        }
    }

    public function removeLink(int $index): void
    {
        unset($this->bioLinks[$index]);
        $this->bioLinks = array_values($this->bioLinks);
    }

    public function mount(): void
    {
        $user = auth()->user();
        $p = $user->publicProfile;

        $this->handle = $p->handle ?? $this->suggestHandle($user->name);
        $this->displayName = (string) ($p->display_name ?? $user->businessProfile?->name ?? $user->name);
        $this->headline = (string) $p?->headline;
        $this->bio = (string) ($p->bio ?? $user->businessProfile?->description ?? '');
        $this->location = (string) ($p->location ?? $user->businessProfile?->city ?? '');
        $this->website = (string) ($p->website ?? $user->businessProfile?->website ?? '');
        $this->isPublic = (bool) $p?->is_public;
        $this->seoTitle = (string) $p?->seo_title;
        $this->seoDescription = (string) $p?->seo_description;
        $this->showBusiness = (bool) ($p?->show_business ?? $user->businessProfile !== null);
        $this->bioLinks = array_values($p?->links ?? []);
        $this->whatsapp = (string) ($p?->whatsapp ? '+'.$p->whatsapp : '');
    }

    private function suggestHandle(string $name): string
    {
        $base = Str::slug($name) ?: 'creator';
        $handle = $base;
        for ($i = 2; PublicProfile::where('handle', $handle)->exists() || in_array($handle, PublicProfile::RESERVED, true); $i++) {
            $handle = "{$base}-{$i}";
        }

        return $handle;
    }

    public function save(): void
    {
        $this->handle = strtolower(trim($this->handle));
        $profile = auth()->user()->publicProfile;

        $this->validate([
            'handle' => ['required', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(PublicProfile::RESERVED),
                Rule::unique('public_profiles', 'handle')->ignore($profile?->id)],
            'displayName' => ['required', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:1500'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:255'],
            'seoTitle' => ['nullable', 'string', 'max:70'],
            'seoDescription' => ['nullable', 'string', 'max:170'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'bioLinks' => ['array', 'max:8'],
            'bioLinks.*.label' => ['required_with:bioLinks.*.url', 'nullable', 'string', 'max:40'],
            'bioLinks.*.url' => ['required_with:bioLinks.*.label', 'nullable', 'url:http,https', 'max:500'],
            'whatsapp' => ['nullable', 'string', 'max:25'],
        ], [
            'bioLinks.*.url.url' => 'Enter a full web address starting with https://',
            'bioLinks.*.label.required_with' => 'Give the button a name.',
            'bioLinks.*.url.required_with' => 'Add the link for this button.',
            'handle.regex' => 'Use lowercase letters, numbers and single dashes, e.g. amara-studio.',
            'handle.not_in' => 'That address is reserved. Please choose another.',
            'handle.unique' => 'That address is taken. Please choose another.',
        ]);

        $whatsapp = \App\Services\Sms\Phone::normalise($this->whatsapp);
        if ($this->whatsapp !== '' && ! $whatsapp) {
            $this->addError('whatsapp', 'Enter your WhatsApp number, e.g. 0772 123456 or +256 772 123456.');

            return;
        }

        $avatarPath = $profile?->avatar_path;
        if ($this->avatar) {
            try {
                $stored = app(ImageOptimizer::class)->store($this->avatar->getRealPath(), 'photos/'.auth()->id(), $this->displayName.' logo', max: 800, thumb: 800);
            } catch (RuntimeException $e) {
                $this->addError('avatar', $e->getMessage());

                return;
            }
            app(ImageOptimizer::class)->delete($avatarPath, $stored['thumb_path'] !== $stored['path'] ? $stored['thumb_path'] : null);
            $avatarPath = $stored['path'];
            $this->avatar = null;
        }

        $profile = auth()->user()->publicProfile()->updateOrCreate([], [
            'handle' => $this->handle,
            'display_name' => $this->displayName,
            'headline' => $this->headline ?: null,
            'bio' => $this->bio ?: null,
            'location' => $this->location ?: null,
            'website' => $this->website ?: null,
            'is_public' => $this->isPublic,
            'seo_title' => $this->seoTitle ?: null,
            'seo_description' => $this->seoDescription ?: null,
            'avatar_path' => $avatarPath,
            'show_business' => $this->showBusiness,
            'links' => collect($this->bioLinks)->filter(fn ($l) => filled($l['label'] ?? null) && filled($l['url'] ?? null))
                ->map(fn ($l) => ['label' => trim($l['label']), 'url' => trim($l['url'])])->values()->all() ?: null,
            'whatsapp' => $whatsapp,
        ]);

        if (PublicProfileController::isLive($profile->fresh())) {
            app(IndexNow::class)->submit([$profile->url()]);
        }

        $this->dispatch('saved');
    }

    public function removeAvatar(): void
    {
        $profile = auth()->user()->publicProfile;

        if ($profile?->avatar_path) {
            app(ImageOptimizer::class)->delete($profile->avatar_path);
            $profile->update(['avatar_path' => null]);
        }
    }

    /** Free checking tools, with the page address filled in: [link, label, help]. */
    public function testTools(string $pageUrl): array
    {
        $encoded = urlencode($pageUrl);
        $site = urlencode('site:'.preg_replace('#^https?://#', '', $pageUrl));

        return [
            ['https://search.google.com/test/rich-results?url='.$encoded, 'Rich results test', 'Checks the details Google reads (business, photos, videos).'],
            ['https://pagespeed.web.dev/analysis?url='.$encoded, 'PageSpeed Insights', 'Speed and mobile score.'],
            ['https://www.google.com/search?q='.$site, 'Is it on Google yet?', 'Shows the pages Google has already indexed.'],
            ['https://www.google.com/search?tbm=isch&q='.$site, 'Photos on Google Images', 'Shows which photos Google has indexed.'],
            ['https://developers.facebook.com/tools/debug/?q='.$encoded, 'Sharing preview', 'How the link looks on Facebook and WhatsApp.'],
        ];
    }

    public function toggleItem(int $id): void
    {
        $item = auth()->user()->contentItems()->findOrFail($id);
        $item->update(['hidden_from_profile' => ! $item->hidden_from_profile]);
    }

    public function with(): array
    {
        $profile = auth()->user()->publicProfile;

        return [
            'profile' => $profile,
            'items' => auth()->user()->contentItems()->with('account')->whereNotNull('content_items.permalink')
                ->latest('content_items.published_at')->paginate(12),
            'pageUrl' => url('/p/'.($profile->handle ?? $this->handle)),
            'hasBusiness' => auth()->user()->businessProfile !== null,
            'live' => $profile && PublicProfileController::isLive($profile),
            'photoCount' => auth()->user()->photos()->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.grow-tabs')

    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <div class="flex min-w-0 flex-col gap-6">
            <form wire:submit="save" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg">Your public page</flux:heading>
                    @if ($profile?->is_public)
                        <flux:badge color="green" icon="globe-alt">Live</flux:badge>
                    @else
                        <flux:badge>Off</flux:badge>
                    @endif
                </div>
                <flux:text class="text-sm">
                    A page on this site that lists your videos and posts. Each video gets its own page with the details Google uses to show videos in search results,
                    and everything is added to our sitemap so Google finds it.
                </flux:text>

                <flux:field>
                    <flux:label>Page address</flux:label>
                    <div class="flex items-center gap-1">
                        <span class="text-sm text-zinc-500">{{ url('/p') }}/</span>
                        <flux:input wire:model="handle" class="flex-1" />
                    </div>
                    <flux:error name="handle" />
                </flux:field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="displayName" label="Name shown" required />
                    <flux:input wire:model="location" label="Location" placeholder="Kampala, Uganda" />
                </div>
                <flux:input wire:model="headline" label="Headline" placeholder="Natural hair care tips and bridal styling in Kampala" description="Shown under your name and in Google results. Use the words people search." />
                <flux:textarea wire:model="bio" label="About" rows="4" />
                <flux:input wire:model="website" type="url" label="Website" placeholder="https://…" />
                <flux:input wire:model="whatsapp" type="tel" label="WhatsApp number (optional)" placeholder="0772 123456" description="Adds a “Chat on WhatsApp” button to your page." />

                <div class="flex flex-col gap-2">
                    <flux:label>Link-in-bio buttons</flux:label>
                    <flux:text class="text-xs">Put your page link in your Instagram and TikTok bio; these buttons take people to your shop, menu, booking page or anything else.</flux:text>
                    @foreach ($bioLinks as $i => $link)
                        <div wire:key="bl-{{ $i }}" class="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[180px_1fr_auto]">
                            <flux:input wire:model="bioLinks.{{ $i }}.label" placeholder="Book now" maxlength="40" aria-label="Button name" class="max-sm:col-span-2" />
                            <flux:input wire:model="bioLinks.{{ $i }}.url" placeholder="https://…" aria-label="Button link" />
                            <flux:button variant="ghost" icon="x-mark" wire:click="removeLink({{ $i }})" aria-label="Remove button" />
                        </div>
                        <flux:error name="bioLinks.{{ $i }}.label" />
                        <flux:error name="bioLinks.{{ $i }}.url" />
                    @endforeach
                    @if (count($bioLinks) < 8)
                        <div><flux:button size="sm" icon="plus" wire:click="addLink">Add a button</flux:button></div>
                    @endif
                </div>

                <flux:field>
                    <flux:label>Page picture or logo</flux:label>
                    <div class="flex flex-wrap items-center gap-4">
                        <x-avatar size="lg" :src="$avatar ? $avatar->temporaryUrl() : $profile?->avatarUrl()" :name="$displayName" class="!size-16" />
                        <input type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp"
                            class="block min-w-0 flex-1 text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-white">
                        @if ($profile?->avatar_path)
                            <flux:button size="sm" variant="ghost" wire:click="removeAvatar">Remove</flux:button>
                        @endif
                    </div>
                    <flux:description>Shown on your page and when the link is shared. Otherwise your connected account’s picture is used.</flux:description>
                    <flux:error name="avatar" />
                </flux:field>

                <div class="flex flex-col gap-4 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-900/50">
                    <div>
                        <flux:heading>How your page looks in Google</flux:heading>
                        <flux:text class="text-sm">Optional. Leave empty to use your name, headline and bio.</flux:text>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-xs dark:bg-zinc-800" aria-label="Google result preview">
                        <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700"><x-app-logo-icon class="size-3.5" /></span>
                            <span class="min-w-0 truncate">{{ parse_url(config('app.url'), PHP_URL_HOST) }} › p › {{ $handle }}</span>
                        </div>
                        <div class="mt-1 line-clamp-1 text-lg text-[#1a0dab] dark:text-[#8ab4f8]">{{ Str::limit($seoTitle ?: $displayName.($headline ? ': '.$headline : ''), 60) }}</div>
                        <div class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-300">{{ Str::limit($seoDescription ?: ($headline ?: $bio) ?: 'Videos, posts and photos by '.$displayName.'.', 160) }}</div>
                    </div>
                    <flux:field>
                        <flux:label>Google title <span class="ms-auto text-xs font-normal tabular-nums text-zinc-500">{{ mb_strlen($seoTitle) }}/60</span></flux:label>
                        <flux:input wire:model.live.debounce.300ms="seoTitle" maxlength="70" :placeholder="$displayName.' – Bridal hair and braids in Kampala'" />
                        <flux:description>Up to about 60 characters: your name plus what you do and where.</flux:description>
                        <flux:error name="seoTitle" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Google description <span class="ms-auto text-xs font-normal tabular-nums text-zinc-500">{{ mb_strlen($seoDescription) }}/160</span></flux:label>
                        <flux:textarea wire:model.live.debounce.300ms="seoDescription" rows="2" maxlength="170" placeholder="Book bridal braids, knotless braids and natural hair care in Ntinda, Kampala. See our latest work and prices." />
                        <flux:description>About 120–160 characters that make people want to click.</flux:description>
                        <flux:error name="seoDescription" />
                    </flux:field>
                </div>

                @if ($hasBusiness)
                    <flux:switch wire:model="showBusiness" label="Show my business details" description="Services, opening hours, address, phone, directions and review link from your Business Profile, marked up so Google understands them." />
                @else
                    <flux:text class="text-sm">Add your <a href="{{ route('grow.business') }}" wire:navigate class="underline">Business Profile</a> to show services, hours, address and a call button on this page.</flux:text>
                @endif
                <flux:switch wire:model="isPublic" label="Make my page public" description="When on, anyone (and Google) can see the page. Turn it off at any time." />

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary">Save</flux:button>
                    <x-action-message on="saved">Saved.</x-action-message>
                    @if ($profile?->is_public)
                        <flux:button :href="$pageUrl" target="_blank" icon-trailing="arrow-top-right-on-square">View my page</flux:button>
                    @endif
                </div>
            </form>

            <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">What appears on your page</flux:heading>
                <flux:text class="text-sm">Your imported videos and posts, newest first. Hide anything you don’t want shown.</flux:text>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($items as $item)
                        <div wire:key="pi-{{ $item->id }}" @class(['overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700', 'opacity-50' => $item->hidden_from_profile])>
                            <div class="aspect-video bg-zinc-100 dark:bg-zinc-900">
                                @if ($item->thumbnail_url)
                                    <img src="{{ $item->thumbnail_url }}" alt="" class="size-full object-cover" referrerpolicy="no-referrer" loading="lazy">
                                @endif
                            </div>
                            <div class="flex items-center gap-2 p-2">
                                <x-platform-badge :platform="$item->account->platform" />
                                <span class="min-w-0 flex-1 truncate text-xs">{{ $item->title ?: \Illuminate\Support\Str::limit($item->caption, 40) }}</span>
                                <flux:button size="xs" variant="ghost" :icon="$item->hidden_from_profile ? 'eye-slash' : 'eye'" wire:click="toggleItem({{ $item->id }})"
                                    :title="$item->hidden_from_profile ? 'Hidden. Click to show' : 'Shown. Click to hide'" />
                            </div>
                        </div>
                    @empty
                        <flux:text class="col-span-full">No content yet. Connect an account and your videos will appear here.</flux:text>
                    @endforelse
                </div>
                <div class="mt-4">{{ $items->links() }}</div>
            </section>
        </div>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Help Google find you faster</flux:heading>
                <ol class="mt-3 list-decimal space-y-2 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>Turn your page on and save.</li>
                    <li>Link to it from your Instagram/TikTok bio, Google Business Profile and website.</li>
                    <li>Write clear titles and descriptions on your videos; Google reads text.</li>
                    <li>Add photos with good titles and alt text so they show in Google Images.</li>
                    <li>Be patient: Google usually takes days to a few weeks to show new pages.</li>
                </ol>
                <flux:text class="mt-3 text-xs">
                    Every public page is listed in <a href="{{ url('/sitemap.xml') }}" target="_blank" class="underline">{{ url('/sitemap.xml') }}</a>, which tells search engines where your content is.
                </flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Photos for Google Images</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ $photoCount ? $photoCount.' '.Str::plural('photo', $photoCount).' on your page.' : 'No photos yet.' }}
                    Each photo gets its own page that can show in Google Images.
                </flux:text>
                <flux:button class="mt-3" size="sm" icon="photo" :href="route('grow.photos')" wire:navigate>Manage photos</flux:button>
            </div>
            @if ($live)
                <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading>Test your page</flux:heading>
                    <flux:text class="mt-1 text-sm">Free Google and Facebook tools, opened with your page address filled in.</flux:text>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($this->testTools($pageUrl) as [$href, $label, $help])
                            <li>
                                <a href="{{ $href }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium underline">{{ $label }} <flux:icon name="arrow-top-right-on-square" variant="micro" /></a>
                                <div class="text-xs text-zinc-500">{{ $help }}</div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Your own website</flux:heading>
                <flux:text class="mt-1 text-sm">Have a website too? Run the <a href="{{ route('grow.seo') }}" wire:navigate class="underline">SEO checker</a> on it and fix the top issues.</flux:text>
            </div>
        </aside>
    </div>
</div>
