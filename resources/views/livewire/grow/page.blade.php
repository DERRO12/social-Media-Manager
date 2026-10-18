<?php

use App\Models\PublicProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $handle = '';
    public string $displayName = '';
    public string $headline = '';
    public string $bio = '';
    public string $location = '';
    public string $website = '';
    public bool $isPublic = false;

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
        ], [
            'handle.regex' => 'Use lowercase letters, numbers and single dashes, e.g. amara-studio.',
            'handle.not_in' => 'That address is reserved. Please choose another.',
            'handle.unique' => 'That address is taken. Please choose another.',
        ]);

        auth()->user()->publicProfile()->updateOrCreate([], [
            'handle' => $this->handle,
            'display_name' => $this->displayName,
            'headline' => $this->headline ?: null,
            'bio' => $this->bio ?: null,
            'location' => $this->location ?: null,
            'website' => $this->website ?: null,
            'is_public' => $this->isPublic,
        ]);

        $this->dispatch('saved');
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
                    <li>Be patient: Google usually takes days to a few weeks to show new pages.</li>
                </ol>
                <flux:text class="mt-3 text-xs">
                    Every public page is listed in <a href="{{ url('/sitemap.xml') }}" target="_blank" class="underline">{{ url('/sitemap.xml') }}</a>, which tells search engines where your content is.
                </flux:text>
            </div>
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Your own website</flux:heading>
                <flux:text class="mt-1 text-sm">Have a website too? Run the <a href="{{ route('grow.seo') }}" wire:navigate class="underline">SEO checker</a> on it and fix the top issues.</flux:text>
            </div>
        </aside>
    </div>
</div>
