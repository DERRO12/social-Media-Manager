<?php

use App\Models\BusinessProfile;
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
        $this->description = (string) $p?->description;
        $this->services = implode("\n", $p?->services ?? []);
        $this->placeId = (string) $p?->place_id;
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

        return [
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
