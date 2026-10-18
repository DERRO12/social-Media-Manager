<?php

use App\Models\ProfilePhoto;
use App\Services\Seo\ImageOptimizer;
use App\Services\Seo\IndexNow;
use App\Http\Controllers\PublicProfileController;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    /** Most photos one client can keep on their page. */
    public const LIMIT = 300;

    public array $uploads = [];

    public ?int $editing = null;
    public string $title = '';
    public string $alt = '';
    public string $caption = '';
    public string $keywords = '';
    public string $location = '';
    public bool $isPublic = true;

    public function upload(ImageOptimizer $optimizer): void
    {
        $this->validate([
            'uploads' => ['required', 'array', 'max:10'],
            'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'uploads.max' => 'Upload up to 10 photos at a time.',
            'uploads.*.image' => 'Only photos (JPG, PNG or WebP) can be uploaded.',
            'uploads.*.mimes' => 'Only JPG, PNG or WebP photos can be uploaded.',
            'uploads.*.max' => 'Each photo can be up to 10 MB.',
        ]);

        $user = auth()->user();
        if ($user->photos()->count() + count($this->uploads) > self::LIMIT) {
            $this->addError('uploads', 'You can keep up to '.self::LIMIT.' photos. Delete some to add more.');

            return;
        }

        $location = $user->businessProfile?->city ?? $user->publicProfile?->location;
        $first = null;
        $problems = [];

        foreach ($this->uploads as $file) {
            // Start from the file name ("bridal_braids-2.jpg" → "Bridal braids 2"); the owner should improve it.
            $title = Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->ucfirst()->limit(120, '')->value() ?: 'Photo';

            try {
                $stored = $optimizer->store($file->getRealPath(), 'photos/'.$user->id, $title);
            } catch (RuntimeException $e) {
                $problems[] = $file->getClientOriginalName().': '.$e->getMessage();

                continue;
            }

            $photo = $user->photos()->create($stored + ['title' => $title, 'location' => $location]);
            $first ??= $photo;
        }

        $this->reset('uploads');

        if ($first) {
            $this->edit($first->id);
            $this->announce();
        }

        // After edit(), which clears messages, so files that failed are still reported.
        foreach ($problems as $problem) {
            $this->addError('uploads', $problem);
        }
    }

    public function edit(int $id): void
    {
        $photo = auth()->user()->photos()->findOrFail($id);

        $this->editing = $photo->id;
        $this->title = $photo->title;
        $this->alt = (string) $photo->alt;
        $this->caption = (string) $photo->caption;
        $this->keywords = (string) $photo->keywords;
        $this->location = (string) $photo->location;
        $this->isPublic = $photo->is_public;
        $this->resetValidation();
    }

    public function save(): void
    {
        $photo = auth()->user()->photos()->findOrFail($this->editing);

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'alt' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
        ]);

        $photo->update([
            'title' => $this->title,
            'alt' => $this->alt ?: null,
            'caption' => $this->caption ?: null,
            'keywords' => $this->keywords ?: null,
            'location' => $this->location ?: null,
            'is_public' => $this->isPublic,
        ]);

        $this->editing = null;
        $this->announce();
        $this->dispatch('photo-saved');
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function toggle(int $id): void
    {
        $photo = auth()->user()->photos()->findOrFail($id);
        $photo->update(['is_public' => ! $photo->is_public]);
    }

    public function delete(int $id): void
    {
        auth()->user()->photos()->findOrFail($id)->delete();

        if ($this->editing === $id) {
            $this->editing = null;
        }
    }

    /** Move a photo up or down the page. */
    public function move(int $id, int $direction): void
    {
        $photos = auth()->user()->photos()->orderBy('sort')->latest('id')->get()->values();
        $index = $photos->search(fn ($p) => $p->id === $id);
        $swap = $index === false ? null : $photos->get($index + $direction);

        if ($swap) {
            $ordered = $photos->all();
            [$ordered[$index], $ordered[$index + $direction]] = [$ordered[$index + $direction], $ordered[$index]];
            foreach ($ordered as $sort => $p) {
                $p->sort === $sort || $p->update(['sort' => $sort]);
            }
        }
    }

    /** Let IndexNow search engines know about new or changed photo pages, if the page is live. */
    private function announce(): void
    {
        $profile = auth()->user()->publicProfile;

        if ($profile && PublicProfileController::isLive($profile)) {
            app(IndexNow::class)->submit([
                $profile->url(),
                ...$profile->photos()->latest('updated_at')->limit(20)->get()->map->pageUrl($profile),
            ]);
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $profile = $user->publicProfile;

        return [
            'profile' => $profile,
            'live' => $profile && PublicProfileController::isLive($profile),
            'photos' => $user->photos()->orderBy('sort')->latest('id')->paginate(24),
            'count' => $user->photos()->count(),
            'current' => $this->editing ? $user->photos()->find($this->editing) : null,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.grow-tabs')

    @unless ($live)
        <flux:callout icon="information-circle" color="sky">
            <flux:callout.heading>Your public page is {{ $profile ? 'off' : 'not set up yet' }}</flux:callout.heading>
            <flux:callout.text>
                Photos appear on Google only when your page is public.
                <flux:callout.link :href="route('grow.page')" wire:navigate>Set up your public page</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    @endunless

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="flex min-w-0 flex-col gap-6">
            <form wire:submit="upload" class="flex flex-col gap-3 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-600">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg">Add photos</flux:heading>
                    <flux:text class="text-sm">{{ $count }} / {{ $this::LIMIT }} photos</flux:text>
                </div>
                <flux:text class="text-sm">
                    Your work, products, place and team. Real photos rank better than stock ones. We make them fast to load, give them search-friendly
                    file names and remove the phone’s location data. Up to 10 at a time, 10 MB each.
                </flux:text>
                <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-700 dark:text-zinc-300 dark:file:bg-white dark:file:text-zinc-900">
                <div wire:loading wire:target="uploads" class="text-sm text-zinc-500">Uploading…</div>
                <flux:error name="uploads" />
                <flux:error name="uploads.*" />
                @if ($uploads)
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray">Add {{ count($uploads) }} {{ Str::plural('photo', count($uploads)) }}</flux:button>
                        <div wire:loading wire:target="upload" class="text-sm text-zinc-500">Optimising photos…</div>
                    </div>
                @endif
            </form>

            @if ($current)
                @php($seo = $current->seoChecks())
                <form wire:submit="save" wire:key="edit-{{ $current->id }}" class="grid gap-5 rounded-xl border border-zinc-200 p-5 md:grid-cols-[220px_1fr] dark:border-zinc-700">
                    <div class="flex flex-col gap-3">
                        <img src="{{ $current->thumbUrl() }}" alt="{{ $current->altText() }}" class="w-full rounded-lg object-cover">
                        <div class="text-xs text-zinc-500">{{ $current->width }}×{{ $current->height }} · {{ \App\Support\Metric::short((int) ceil($current->size / 1024)) }} KB</div>
                        <ul class="space-y-1 text-xs">
                            @foreach ($seo['checks'] as $check)
                                <li class="flex gap-1.5" title="{{ $check['tip'] }}">
                                    <flux:icon :name="$check['ok'] ? 'check-circle' : 'exclamation-circle'" variant="micro" @class(['mt-0.5 shrink-0', 'text-green-600' => $check['ok'], 'text-amber-500' => ! $check['ok']]) />
                                    <span>{{ $check['label'] }}@unless ($check['ok'])<span class="block text-zinc-500">{{ $check['tip'] }}</span>@endunless</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="flex min-w-0 flex-col gap-4">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading size="lg">Describe this photo for Google</flux:heading>
                            <flux:badge :color="$seo['score'] >= 80 ? 'green' : ($seo['score'] >= 50 ? 'amber' : 'red')">{{ $seo['score'] }}/100</flux:badge>
                        </div>
                        <flux:input wire:model="title" label="Title" placeholder="Bridal braids at Amara Studio, Kampala" description="Becomes the page title and file name people see in Google Images." required />
                        <flux:input wire:model="alt" label="Alt text" placeholder="Bride with long knotless braids and gold beads, smiling" description="Describe what is in the photo. Google and screen readers use it." />
                        <flux:textarea wire:model="caption" label="Caption" rows="3" placeholder="A sentence or two about the photo: the service, product, price or story behind it." />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="keywords" label="Keywords" placeholder="bridal braids, wedding hair Kampala" />
                            <flux:input wire:model="location" label="Location" placeholder="Kampala, Uganda" />
                        </div>
                        <flux:switch wire:model="isPublic" label="Show on my public page" />
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:button type="submit" variant="primary">Save</flux:button>
                            <flux:button wire:click="cancel">Close</flux:button>
                            @if ($live && $current->is_public)
                                <flux:button variant="ghost" :href="$current->pageUrl($profile)" target="_blank" icon-trailing="arrow-top-right-on-square">View page</flux:button>
                            @endif
                        </div>
                    </div>
                </form>
            @endif

            <section>
                <x-action-message on="photo-saved" class="mb-2 text-sm">Saved.</x-action-message>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    @forelse ($photos as $photo)
                        @php($score = $photo->seoChecks()['score'])
                        <div wire:key="photo-{{ $photo->id }}" @class(['group overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700', 'opacity-50' => ! $photo->is_public, 'ring-2 ring-zinc-900 dark:ring-white' => $editing === $photo->id])>
                            <button type="button" wire:click="edit({{ $photo->id }})" class="relative block aspect-square w-full bg-zinc-100 dark:bg-zinc-900">
                                <img src="{{ $photo->thumbUrl() }}" alt="{{ $photo->altText() }}" loading="lazy" class="size-full object-cover">
                                <span @class(['absolute left-2 top-2 rounded-full px-2 py-0.5 text-[11px] font-semibold text-white',
                                    'bg-green-600' => $score >= 80, 'bg-amber-500' => $score >= 50 && $score < 80, 'bg-rose-600' => $score < 50])>{{ $score }}</span>
                            </button>
                            <div class="flex items-center gap-1 p-2">
                                <span class="min-w-0 flex-1 truncate text-xs" title="{{ $photo->title }}">{{ $photo->title }}</span>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Photo options" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil" wire:click="edit({{ $photo->id }})">Edit details</flux:menu.item>
                                        <flux:menu.item :icon="$photo->is_public ? 'eye-slash' : 'eye'" wire:click="toggle({{ $photo->id }})">{{ $photo->is_public ? 'Hide from page' : 'Show on page' }}</flux:menu.item>
                                        <flux:menu.item icon="arrow-up" wire:click="move({{ $photo->id }}, -1)">Move earlier</flux:menu.item>
                                        <flux:menu.item icon="arrow-down" wire:click="move({{ $photo->id }}, 1)">Move later</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $photo->id }})" wire:confirm="Delete this photo? It will be removed from your page and from search results over time.">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full rounded-xl border border-zinc-200 p-8 text-center dark:border-zinc-700">
                            <flux:icon name="photo" class="mx-auto size-8 text-zinc-400" />
                            <flux:text class="mt-2">No photos yet. Add your best work above.</flux:text>
                        </div>
                    @endforelse
                </div>
                <div class="mt-4">{{ $photos->links() }}</div>
            </section>
        </div>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>How to get photos into Google Images</flux:heading>
                <ol class="mt-3 list-decimal space-y-2 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li><strong>Describe each photo</strong>: a clear title, alt text and a caption with words people search. Aim for a green score.</li>
                    <li><strong>Use big, sharp, real photos</strong> (1200px wide or more). No text-heavy graphics or stock photos.</li>
                    <li><strong>Add your town</strong> to the location for local searches.</li>
                    <li><strong>Keep your page public</strong>. Each photo gets its own page, and all photos are listed in our image sitemap.</li>
                    <li><strong>Link to your page</strong> from your bio, Google Business Profile and website.</li>
                </ol>
                <flux:text class="mt-3 text-xs">Google usually takes a few days to a few weeks to show new photos.</flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Google Business Profile</flux:heading>
                <flux:text class="mt-1 text-sm">Also add these photos to your Google Business Profile: they show on Google Maps and in local results.
                    <a href="{{ route('grow.business') }}" wire:navigate class="underline">Open the builder</a></flux:text>
            </div>
        </aside>
    </div>
</div>
