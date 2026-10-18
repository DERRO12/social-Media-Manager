<?php

use App\Enums\Platform;
use App\Models\BusinessProfile;
use App\Models\SocialAccount;
use App\Services\Social\PlatformException;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $primaryCategory = '';
    public string $secondaryCategories = '';
    public string $keywords = '';
    public string $city = '';
    public string $address = '';
    public string $serviceArea = '';
    public string $phone = '';
    public string $website = '';
    public string $bookingUrl = '';
    public string $description = '';
    public string $services = '';
    public string $placeId = '';
    public array $hours = [];
    public array $checklist = [];

    /** Creating / updating the real listing on Google (needs a connected Google Business Profile). */
    public const REGIONS = ['UG' => 'Uganda', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'RW' => 'Rwanda', 'NG' => 'Nigeria', 'GH' => 'Ghana', 'ZA' => 'South Africa',
        'ZM' => 'Zambia', 'ET' => 'Ethiopia', 'CM' => 'Cameroon', 'GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada', 'AE' => 'United Arab Emirates', 'IN' => 'India'];
    public ?int $googleAccountId = null;
    public string $region = 'UG';
    public string $categorySearch = '';
    public array $categoryOptions = [];
    public string $googleCategory = '';
    public ?string $googleMessage = null;
    public bool $googleOk = true;

    public function mount(): void
    {
        $p = auth()->user()->businessProfile;

        $this->name = (string) ($p->name ?? auth()->user()->name);
        $this->primaryCategory = (string) $p?->primary_category;
        $this->secondaryCategories = (string) $p?->secondary_categories;
        $this->keywords = (string) $p?->keywords;
        $this->city = (string) $p?->city;
        $this->address = (string) $p?->address;
        $this->serviceArea = (string) $p?->service_area;
        $this->phone = (string) $p?->phone;
        $this->website = (string) $p?->website;
        $this->bookingUrl = (string) $p?->booking_url;

        // Once Google knows the business, its Place ID (for the review link) comes from there.
        $this->placeId = (string) ($p?->place_id ?: auth()->user()->socialAccounts()->where('platform', Platform::GoogleBusiness)->get()->pluck('meta.place_id')->filter()->first());
        $this->description = (string) $p?->description;
        $this->services = implode("\n", $p?->services ?? []);
        $this->checklist = $p?->checklist ?? [];

        foreach (BusinessProfile::DAYS as $day) {
            $this->hours[$day] = $p?->hours[$day] ?? ['open' => ! in_array($day, ['sunday'], true), 'from' => '08:00', 'to' => '18:00'];
        }
    }

    private function attributes(): array
    {
        return [
            'name' => $this->name ?: null,
            'primary_category' => $this->primaryCategory ?: null,
            'secondary_categories' => $this->secondaryCategories ?: null,
            'keywords' => $this->keywords ?: null,
            'city' => $this->city ?: null,
            'address' => $this->address ?: null,
            'service_area' => $this->serviceArea ?: null,
            'phone' => $this->phone ?: null,
            'website' => $this->website ?: null,
            'booking_url' => $this->bookingUrl ?: null,
            'description' => $this->description ?: null,
            'services' => array_values(array_filter(array_map('trim', explode("\n", $this->services)))),
            'place_id' => $this->placeId ?: null,
            'hours' => $this->hours,
            'checklist' => array_filter($this->checklist),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'primaryCategory' => ['nullable', 'string', 'max:120'],
            'secondaryCategories' => ['nullable', 'string', 'max:255'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'serviceArea' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'bookingUrl' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:750'],
            'services' => ['nullable', 'string', 'max:3000'],
            'placeId' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'hours.*.from' => ['nullable', 'date_format:H:i'],
            'hours.*.to' => ['nullable', 'date_format:H:i'],
        ], [
            'description.max' => 'Google allows up to 750 characters.',
            'placeId.regex' => 'A Place ID looks like ChIJ… (letters, numbers, - and _ only).',
        ]);

        auth()->user()->businessProfile()->updateOrCreate([], $this->attributes());
        $this->dispatch('saved');
    }

    private function googleAccount(): SocialAccount
    {
        return auth()->user()->socialAccounts()->where('platform', Platform::GoogleBusiness)->findOrFail($this->googleAccountId);
    }

    public function searchCategories(): void
    {
        $this->validate(['categorySearch' => ['required', 'string', 'min:2', 'max:60']]);
        $account = $this->googleAccount();

        try {
            $this->categoryOptions = $account->driver()->categories($account, $this->categorySearch, $this->region);
            $this->googleCategory = $this->categoryOptions[0]['name'] ?? '';
            $this->googleMessage = $this->categoryOptions ? null : 'No Google category matches that. Try a simpler word, like “salon” or “restaurant”.';
            $this->googleOk = (bool) $this->categoryOptions;
        } catch (PlatformException $e) {
            [$this->googleOk, $this->googleMessage] = [false, $e->getMessage()];
        }
    }

    public function createOnGoogle(): void
    {
        $this->save();
        $account = $this->googleAccount();
        abort_if($account->meta['location'] ?? null, 403);

        $this->validate([
            'name' => ['required'],
            'address' => ['required'],
            'city' => ['required'],
            'googleCategory' => ['required', 'starts_with:categories/'],
            'region' => ['required', 'in:'.implode(',', array_keys(self::REGIONS))],
        ], [
            'address.required' => 'Google needs a street address to put the business on the map.',
            'googleCategory.required' => 'Search and choose your Google category.',
        ]);

        try {
            $account->driver()->createLocation($account, auth()->user()->businessProfile()->firstOrFail(), $this->googleCategory, $this->region);
            $this->checklist['created'] = true;
            auth()->user()->businessProfile()->update(['checklist' => $this->checklist]);
            [$this->googleOk, $this->googleMessage] = [true, 'Your business was created on Google. Next, verify it so it appears on Maps.'];
        } catch (PlatformException $e) {
            [$this->googleOk, $this->googleMessage] = [false, $e->getMessage()];
        }
    }

    public function pushToGoogle(): void
    {
        $this->save();
        $account = $this->googleAccount();
        abort_unless($account->meta['location'] ?? null, 403);

        try {
            $account->driver()->updateLocation($account, auth()->user()->businessProfile()->firstOrFail(), $this->region);
            [$this->googleOk, $this->googleMessage] = [true, 'Google has your latest details. Changes can take a few minutes to show (Google may review some).'];
        } catch (PlatformException $e) {
            [$this->googleOk, $this->googleMessage] = [false, $e->getMessage()];
        }
    }

    public function draft(): void
    {
        $profile = new BusinessProfile($this->attributes());
        $this->description = $profile->draftDescription();
    }

    public function toggle(string $key): void
    {
        abort_unless(array_key_exists($key, BusinessProfile::CHECKLIST), 404);
        $this->checklist[$key] = ! ($this->checklist[$key] ?? false);
        auth()->user()->businessProfile()->updateOrCreate([], $this->attributes());
    }

    public function with(): array
    {
        $profile = new BusinessProfile($this->attributes());

        $googleAccounts = auth()->user()->socialAccounts()->where('platform', Platform::GoogleBusiness)->orderBy('name')->get();
        if (! $googleAccounts->contains('id', $this->googleAccountId)) {
            $this->googleAccountId = $googleAccounts->first()?->id;
        }
        $google = $googleAccounts->firstWhere('id', $this->googleAccountId);

        return [
            'googleAccounts' => $googleAccounts,
            'google' => $google,
            'reviewsItem' => $google ? $google->contentItems()->where('type', 'business')->first() : null,
            'completeness' => $profile->completeness(),
            'reviewUrl' => $profile->reviewUrl(),
            'days' => BusinessProfile::DAYS,
            'steps' => BusinessProfile::CHECKLIST,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.grow-tabs')

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <form wire:submit="save" class="flex min-w-0 flex-col gap-6">
            <flux:callout icon="information-circle" heading="Prepare everything here, then create your profile on Google">
                <flux:callout.text>
                    Google only lets business owners create and verify profiles on its own site. Fill in your details here, use the description writer,
                    then copy everything into <a href="https://business.google.com/create" target="_blank" rel="noopener" class="underline">business.google.com</a>.
                </flux:callout.text>
            </flux:callout>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">1. Basics</flux:heading>
                <flux:input wire:model.live.debounce.500ms="name" label="Business name" description="Exactly as customers see it on your sign. Don’t add extra keywords; Google can suspend profiles for that." required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="primaryCategory" label="Primary category" placeholder="e.g. Hair salon, Bakery, Marketing agency" description="The most specific category for your main service." />
                    <flux:input wire:model.live.debounce.500ms="secondaryCategories" label="Other categories" placeholder="e.g. Nail salon, Beauty salon" />
                </div>
                <flux:input wire:model.live.debounce.500ms="keywords" label="What customers love about you" placeholder="e.g. fast delivery, natural products, friendly staff" description="Used in the description writer. Separate with commas." />
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">2. Location and contact</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="city" label="Town or city" placeholder="Kampala" />
                    <flux:input wire:model.live.debounce.500ms="phone" label="Phone / WhatsApp" placeholder="+256 7…" />
                </div>
                <flux:input wire:model.live.debounce.500ms="address" label="Street address (if customers visit you)" placeholder="Plot 12, Main Street, Kampala" />
                <flux:input wire:model.live.debounce.500ms="serviceArea" label="Service area (if you go to customers)" placeholder="Kampala, Wakiso and Entebbe" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="website" type="url" label="Website" placeholder="https://…" />
                    <flux:input wire:model.live.debounce.500ms="bookingUrl" type="url" label="Booking or WhatsApp link" placeholder="https://wa.me/256…" />
                </div>
            </section>

            <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">3. Services and description</flux:heading>
                <flux:textarea wire:model.live.debounce.500ms="services" label="Products or services (one per line)" rows="4" placeholder="Bridal makeup&#10;Hair braiding&#10;Nail art" />
                <flux:field>
                    <div class="flex items-center justify-between">
                        <flux:label>Business description</flux:label>
                        <flux:button size="xs" icon="sparkles" wire:click="draft">Write a draft for me</flux:button>
                    </div>
                    <flux:textarea wire:model.live.debounce.300ms="description" rows="5" maxlength="750" />
                    <flux:description>
                        {{ mb_strlen($description) }}/750 characters. Say what you do, who you serve, where, and what makes you different. Write naturally; no links or phone numbers here.
                    </flux:description>
                    <flux:error name="description" />
                </flux:field>
            </section>

            <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg">4. Opening hours</flux:heading>
                @foreach ($days as $day)
                    <div wire:key="day-{{ $day }}" class="grid grid-cols-2 items-center gap-x-3 gap-y-2 text-sm sm:grid-cols-[110px_auto_minmax(0,1fr)_minmax(0,1fr)]">
                        <span class="font-medium capitalize">{{ $day }}</span>
                        <flux:switch wire:model.live="hours.{{ $day }}.open" class="max-sm:justify-self-end" />
                        @if ($hours[$day]['open'] ?? false)
                            <flux:input type="time" size="sm" wire:model.live="hours.{{ $day }}.from" />
                            <flux:input type="time" size="sm" wire:model.live="hours.{{ $day }}.to" />
                        @else
                            <span class="col-span-2 text-zinc-500">Closed</span>
                        @endif
                    </div>
                @endforeach
            </section>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">Save details</flux:button>
                <x-action-message on="saved">Saved.</x-action-message>
                <flux:button href="https://business.google.com/create" target="_blank" icon-trailing="arrow-top-right-on-square">Create on Google</flux:button>
            </div>
        </form>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading>On Google</flux:heading>
                    @if ($google && ($google->meta['location'] ?? null))
                        <flux:badge size="sm" :color="($google->meta['verified'] ?? false) ? 'green' : 'amber'">{{ ($google->meta['verified'] ?? false) ? 'Verified' : 'Not verified yet' }}</flux:badge>
                    @endif
                </div>

                @if ($googleAccounts->isEmpty())
                    <flux:text class="mt-1 text-sm">Connect your Google Business Profile to create the business on Google Maps from these details, keep it updated, and answer Google reviews in your inbox.</flux:text>
                    <flux:button class="mt-3 w-full" variant="primary" icon="link" :href="route('accounts.connect', Platform::GoogleBusiness)">Connect Google Business Profile</flux:button>
                @else
                    @if ($googleAccounts->count() > 1)
                        <flux:select wire:model.live="googleAccountId" class="mt-3" size="sm">
                            @foreach ($googleAccounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </flux:select>
                    @endif

                    @if ($google->meta['location'] ?? null)
                        <div class="mt-3 text-sm font-medium">{{ $google->name }}</div>
                        @if ($reviewsItem)
                            <flux:text class="text-sm">{{ $reviewsItem->caption }}</flux:text>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <flux:button size="sm" variant="primary" icon="arrow-up-on-square" wire:click="pushToGoogle">Send these details to Google</flux:button>
                            <flux:button size="sm" icon="chat-bubble-left-right" :href="route('inbox', ['platform' => 'google_business'])" wire:navigate>Reviews in inbox</flux:button>
                            @if ($google->meta['maps_url'] ?? null)
                                <flux:button size="sm" variant="ghost" icon="map" :href="$google->meta['maps_url']" target="_blank">Maps</flux:button>
                            @endif
                        </div>
                        @unless ($google->meta['verified'] ?? false)
                            <flux:text class="mt-3 text-xs">Google shows the business on Maps after you verify it (video, phone, email or postcard). <a href="https://business.google.com/locations" target="_blank" rel="noopener" class="underline">Verify on Google</a></flux:text>
                        @endunless
                    @else
                        <flux:text class="mt-2 text-sm">Create the business on Google from the details on this page. Save your address, city and phone first.</flux:text>
                        <div class="mt-3 flex flex-col gap-3">
                            <flux:select wire:model="region" label="Country" size="sm">
                                @foreach ($this::REGIONS as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            <div class="flex items-end gap-2">
                                <flux:input wire:model="categorySearch" wire:keydown.enter.prevent="searchCategories" label="Google category" size="sm" :placeholder="$primaryCategory ?: 'e.g. hair salon'" class="flex-1" />
                                <flux:button size="sm" wire:click="searchCategories" icon="magnifying-glass" aria-label="Search categories" />
                            </div>
                            <flux:error name="categorySearch" />
                            @if ($categoryOptions)
                                <flux:select wire:model="googleCategory" size="sm">
                                    @foreach ($categoryOptions as $c)
                                        <option value="{{ $c['name'] }}">{{ $c['label'] }}</option>
                                    @endforeach
                                </flux:select>
                            @endif
                            @foreach (['googleCategory', 'address', 'city', 'name'] as $field)
                                <flux:error :name="$field" />
                            @endforeach
                            <flux:button variant="primary" icon="plus" wire:click="createOnGoogle" wire:confirm="Create this business on Google with the details on this page?">Create on Google</flux:button>
                        </div>
                    @endif
                @endif

                @if ($googleMessage)
                    <flux:callout class="mt-3" :variant="$googleOk ? 'success' : 'danger'" :heading="$googleMessage" />
                @endif
                <div wire:loading wire:target="createOnGoogle,pushToGoogle,searchCategories" class="mt-2 text-xs text-zinc-500">Talking to Google…</div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-end justify-between">
                    <flux:heading>Profile strength</flux:heading>
                    <span class="text-3xl font-semibold tabular-nums">{{ $completeness['score'] }}%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div @class(['h-full rounded-full transition-all', 'bg-green-500' => $completeness['score'] >= 80, 'bg-amber-500' => $completeness['score'] >= 50 && $completeness['score'] < 80, 'bg-red-500' => $completeness['score'] < 50]) style="width: {{ $completeness['score'] }}%"></div>
                </div>
                @if ($completeness['missing'])
                    <div class="mt-3 text-xs text-zinc-500">Still to add: {{ implode(', ', $completeness['missing']) }}.</div>
                @endif
            </div>

            {{-- Preview, roughly how the listing reads on Google --}}
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Preview</div>
                <div class="text-lg font-medium text-blue-700 dark:text-blue-400">{{ $name ?: 'Your business' }}</div>
                <div class="text-sm text-zinc-500">{{ $primaryCategory ?: 'Category' }}{{ $city ? ' in '.$city : '' }}</div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    @if ($website)<span class="rounded-full border border-zinc-300 px-2 py-1 dark:border-zinc-600">Website</span>@endif
                    <span class="rounded-full border border-zinc-300 px-2 py-1 dark:border-zinc-600">Directions</span>
                    @if ($phone)<span class="rounded-full border border-zinc-300 px-2 py-1 dark:border-zinc-600">Call</span>@endif
                    @if ($bookingUrl)<span class="rounded-full border border-zinc-300 px-2 py-1 dark:border-zinc-600">Book</span>@endif
                </div>
                @if ($description)
                    <p class="mt-3 line-clamp-4 text-sm text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>On Google: checklist</flux:heading>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($steps as $key => [$label, $help])
                        <label wire:key="step-{{ $key }}" class="flex cursor-pointer items-start gap-2 text-sm">
                            <input type="checkbox" class="mt-0.5 rounded" @checked($checklist[$key] ?? false) wire:click="toggle('{{ $key }}')">
                            <span>
                                <span @class(['line-through text-zinc-400' => $checklist[$key] ?? false])>{{ $label }}</span>
                                <span class="block text-xs text-zinc-500">{{ $help }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Your review link</flux:heading>
                <flux:text class="mt-1 text-xs">
                    Paste your Place ID (find it with
                    <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener" class="underline">Google’s Place ID finder</a>
                    after your profile is live) to get a link that opens the review box directly.
                </flux:text>
                <flux:input wire:model.live.debounce.500ms="placeId" placeholder="ChIJ…" class="mt-2" />
                <flux:error name="placeId" />
                @if ($reviewUrl)
                    <div class="mt-3 flex gap-2" x-data>
                        <flux:input readonly :value="$reviewUrl" class="flex-1" />
                        <flux:button icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($reviewUrl)); $el.innerText = 'Copied'">Copy</flux:button>
                    </div>
                    <flux:text class="mt-2 text-xs">Send it to happy customers by WhatsApp or SMS. Never pay or reward people for reviews.</flux:text>
                @endif
            </div>
        </aside>
    </div>
</div>
