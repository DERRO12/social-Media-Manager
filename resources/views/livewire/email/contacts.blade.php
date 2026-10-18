<?php

use App\Models\Contact;
use App\Services\Sms\Phone;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component {
    use WithFileUploads, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $show = 'all';
    #[Url] public string $tag = '';

    public string $email = '';
    public string $phone = '';
    public string $name = '';
    public string $tags = '';
    public bool $consent = false;

    public $csv = null;
    public string $importTags = '';
    public bool $importConsent = false;
    public ?string $importResult = null;

    public function updating($property): void
    {
        if (in_array($property, ['search', 'show', 'tag'], true)) {
            $this->resetPage();
        }
    }

    public function add(): void
    {
        $this->validate([
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:25'],
            'name' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'string', 'max:200'],
            'consent' => ['accepted'],
        ], [
            'email.required_without' => 'Enter an email address or a phone number.',
            'phone.required_without' => 'Enter an email address or a phone number.',
            'consent.accepted' => 'Only add people who agreed to hear from you.',
        ]);

        $phone = Phone::normalise($this->phone);
        if ($this->phone !== '' && ! $phone) {
            $this->addError('phone', 'That doesn’t look like a phone number. Use e.g. 0772 123456 or +256 772 123456.');

            return;
        }

        $contacts = auth()->user()->contacts();
        if ($this->email !== '' && (clone $contacts)->where('email', strtolower(trim($this->email)))->exists()) {
            $this->addError('email', 'This email is already on your list.');

            return;
        }
        if ($phone && (clone $contacts)->where('phone', $phone)->exists()) {
            $this->addError('phone', 'This phone number is already on your list.');

            return;
        }
        if ((clone $contacts)->count() >= Contact::LIMIT) {
            $this->addError('email', 'You’ve reached '.number_format(Contact::LIMIT).' contacts. Remove some to add more.');

            return;
        }

        $contacts->create([
            'email' => $this->email ?: null,
            'phone' => $phone,
            'name' => $this->name ?: null,
            'tags' => Contact::normaliseTags($this->tags),
            'source' => 'manual',
        ]);

        $this->reset('email', 'phone', 'name', 'consent');
        $this->dispatch('contact-added');
    }

    /** CSV from Excel, Google Sheets, a phone's contacts export, Mailchimp…: email and/or phone, optionally name. */
    public function import(): void
    {
        $this->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'importTags' => ['nullable', 'string', 'max:200'],
            'importConsent' => ['accepted'],
        ], ['importConsent.accepted' => 'Confirm these people agreed to hear from you.']);

        $handle = fopen($this->csv->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"")), fgetcsv($handle) ?: []);
        $find = fn (array $words) => collect($header)->search(fn ($h) => collect($words)->contains(fn ($w) => str_contains($h, $w)));
        $emailCol = $find(['email', 'e-mail']);
        $phoneCol = $find(['phone', 'mobile', 'tel', 'whatsapp', 'cell', 'number', 'contact']);
        $nameCol = $find(['name']);

        // No header row: guess each column from the first row's values.
        if ($emailCol === false && $phoneCol === false) {
            rewind($handle);
            $first = fgetcsv($handle) ?: [];
            rewind($handle);
            $emailCol = collect($first)->search(fn ($v) => str_contains((string) $v, '@'));
            $phoneCol = collect($first)->search(fn ($v) => ! str_contains((string) $v, '@') && Phone::normalise((string) $v));
            $nameCol = collect($first)->keys()->first(fn ($k) => $k !== $emailCol && $k !== $phoneCol && preg_match('/[a-z]/i', (string) $first[$k])) ?? false;
        }

        $tags = Contact::normaliseTags($this->importTags);
        $existing = auth()->user()->contacts()->get(['email', 'phone']);
        $emails = $existing->pluck('email')->filter()->flip();
        $phones = $existing->pluck('phone')->filter()->flip();
        $room = Contact::LIMIT - $existing->count();
        [$added, $skipped] = [0, 0];

        while (($row = fgetcsv($handle)) !== false) {
            $email = $emailCol !== false ? strtolower(trim((string) ($row[$emailCol] ?? ''))) : '';
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            $phone = $phoneCol !== false ? Phone::normalise((string) ($row[$phoneCol] ?? '')) : null;

            if ((! $email && ! $phone) || ($email && isset($emails[$email])) || ($phone && isset($phones[$phone])) || $added >= $room) {
                $skipped++;

                continue;
            }

            $name = $nameCol !== false ? trim((string) ($row[$nameCol] ?? '')) : '';
            auth()->user()->contacts()->create([
                'email' => $email,
                'phone' => $phone,
                'name' => $name !== '' && ! str_contains($name, '@') ? mb_substr($name, 0, 100) : null,
                'tags' => $tags,
                'source' => 'import',
            ]);
            $email && $emails[$email] = true;
            $phone && $phones[$phone] = true;
            $added++;
        }
        fclose($handle);

        $this->reset('csv', 'importConsent');
        $this->importResult = "Added {$added} ".str('contact')->plural($added).($skipped ? ", skipped {$skipped} (no valid email or phone, duplicate, or over the limit)." : '.');
    }

    /** Turn email or SMS on/off for a contact (e.g. they asked by phone). */
    public function toggle(int $id, string $channel = 'email'): void
    {
        $contact = auth()->user()->contacts()->findOrFail($id);

        if ($channel === 'sms') {
            $contact->update(['sms_status' => $contact->sms_status === Contact::SUBSCRIBED ? Contact::UNSUBSCRIBED : Contact::SUBSCRIBED]);
        } else {
            $contact->status === Contact::SUBSCRIBED
                ? $contact->unsubscribe()
                : $contact->update(['status' => Contact::SUBSCRIBED, 'unsubscribed_at' => null]);
        }
    }

    public function delete(int $id): void
    {
        auth()->user()->contacts()->findOrFail($id)->delete();
    }

    public function export(): StreamedResponse
    {
        $contacts = auth()->user()->contacts()->orderBy('name')->get();

        return response()->streamDownload(function () use ($contacts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'email', 'phone', 'tags', 'email_status', 'sms_status', 'source', 'added']);
            foreach ($contacts as $c) {
                fputcsv($out, [$c->name, $c->email, $c->phone ? '+'.$c->phone : null, $c->tags, $c->email ? $c->status : null, $c->phone ? $c->sms_status : null, $c->source, $c->created_at->toDateString()]);
            }
            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = $user->contacts()->latest()
            ->when($this->show === 'email', fn ($q) => $q->subscribed())
            ->when($this->show === 'sms', fn ($q) => $q->smsSubscribed())
            ->when($this->show === 'unsubscribed', fn ($q) => $q->where(fn ($q) => $q->where('status', Contact::UNSUBSCRIBED)->orWhere('sms_status', Contact::UNSUBSCRIBED)))
            ->tagged($this->tag ?: null)
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('email', 'like', "%{$this->search}%")
                ->orWhere('name', 'like', "%{$this->search}%")
                ->when(strlen($digits = ltrim(preg_replace('/\D/', '', $this->search), '0')) >= 3, fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%"))));

        return [
            'contacts' => $query->paginate(25),
            'emailCount' => $user->contacts()->subscribed()->count(),
            'smsCount' => $user->contacts()->smsSubscribed()->count(),
            'total' => $user->contacts()->count(),
            'allTags' => $user->contacts()->whereNotNull('tags')->pluck('tags')->flatMap(fn ($t) => explode(', ', $t))->unique()->sort()->values(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.email-tabs')

    <div class="grid grid-cols-3 gap-3 sm:gap-4">
        @foreach ([['All contacts', $total, 'users'], ['Email subscribers', $emailCount, 'envelope'], ['SMS subscribers', $smsCount, 'device-phone-mobile']] as [$label, $value, $icon])
            <div class="min-w-0 rounded-xl border border-zinc-200 p-3 sm:p-4 dark:border-zinc-700">
                <div class="flex items-center gap-1.5 text-xs text-zinc-500 sm:text-sm"><flux:icon :name="$icon" variant="mini" class="shrink-0" /> <span class="truncate">{{ $label }}</span></div>
                <div class="mt-1 text-xl font-semibold tabular-nums sm:text-2xl">{{ number_format($value) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <section class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search name, email or phone" class="w-full sm:max-w-xs" />
                <flux:select wire:model.live="show" class="min-w-0 max-sm:flex-1 sm:w-44">
                    <option value="all">Everyone</option>
                    <option value="email">Email subscribers</option>
                    <option value="sms">SMS subscribers</option>
                    <option value="unsubscribed">Opted out</option>
                </flux:select>
                @if ($allTags->isNotEmpty())
                    <flux:select wire:model.live="tag" class="min-w-0 max-sm:flex-1 sm:w-40">
                        <option value="">All tags</option>
                        @foreach ($allTags as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:spacer />
                @if ($total)
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="export">Export CSV</flux:button>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-2">Contact</th>
                            <th class="px-4 py-2">Reach by</th>
                            <th class="px-4 py-2">Tags</th>
                            <th class="px-4 py-2">Added</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($contacts as $contact)
                            <tr wire:key="c-{{ $contact->id }}">
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ $contact->name ?: '—' }}</div>
                                    @if ($contact->email)<div class="text-zinc-500">{{ $contact->email }}</div>@endif
                                    @if ($contact->phone)<div class="whitespace-nowrap text-zinc-500">{{ Phone::display($contact->phone) }}</div>@endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($contact->email)
                                            <flux:badge size="sm" :color="$contact->status === 'subscribed' ? 'green' : 'zinc'" icon="envelope">{{ $contact->status === 'subscribed' ? 'Email' : 'No email' }}</flux:badge>
                                        @endif
                                        @if ($contact->phone)
                                            <flux:badge size="sm" :color="$contact->sms_status === 'subscribed' ? 'green' : 'zinc'" icon="device-phone-mobile">{{ $contact->sms_status === 'subscribed' ? 'SMS' : 'No SMS' }}</flux:badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($contact->tagList() as $t)<flux:badge size="sm">{{ $t }}</flux:badge>@endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-zinc-500">{{ $contact->created_at->toFormattedDateString() }}<div class="text-xs capitalize">{{ $contact->source }}</div></td>
                                <td class="px-4 py-2 text-right">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Contact options" />
                                        <flux:menu>
                                            @if ($contact->email)
                                                <flux:menu.item icon="envelope" wire:click="toggle({{ $contact->id }}, 'email')">{{ $contact->status === 'subscribed' ? 'Stop emails' : 'Allow emails again' }}</flux:menu.item>
                                            @endif
                                            @if ($contact->phone)
                                                <flux:menu.item icon="device-phone-mobile" wire:click="toggle({{ $contact->id }}, 'sms')">{{ $contact->sms_status === 'subscribed' ? 'Stop SMS' : 'Allow SMS again' }}</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $contact->id }})" wire:confirm="Delete this contact?">Delete</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">No contacts here yet. Add people on the right, import a spreadsheet, or turn on the sign-up form on your public page.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $contacts->links() }}
            <flux:text class="text-xs">Only you can see your contacts. They are never shared with other {{ config('app.name') }} users.</flux:text>
        </section>

        <aside class="flex flex-col gap-4">
            <form wire:submit="add" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Add a contact</flux:heading>
                <flux:input wire:model="name" label="Name" />
                <flux:input wire:model="email" type="email" label="Email" />
                <flux:input wire:model="phone" type="tel" label="Phone (for SMS)" placeholder="0772 123456" />
                <flux:input wire:model="tags" label="Tags" placeholder="customers, vip" description="Comma-separated. Send campaigns to one tag." />
                <flux:checkbox wire:model="consent" label="They agreed to hear from me" />
                <flux:error name="consent" />
                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">Add</flux:button>
                    <x-action-message on="contact-added">Added.</x-action-message>
                </div>
            </form>

            <form wire:submit="import" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Import emails and phone numbers</flux:heading>
                <flux:text class="text-sm">A CSV with <strong>email</strong> and/or <strong>phone</strong> columns (and optionally <strong>name</strong>). From Excel or Google Sheets: File → Save as / Download → CSV. Local numbers like 0772 123456 are understood.</flux:text>
                <input type="file" wire:model="csv" accept=".csv,text/csv,text/plain"
                    class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-white">
                <flux:error name="csv" />
                <flux:input wire:model="importTags" label="Tag everyone in this file" placeholder="e.g. customers-2025" />
                <flux:checkbox wire:model="importConsent" label="These people agreed to hear from me" />
                <flux:error name="importConsent" />
                <flux:button type="submit" icon="arrow-up-tray">Import</flux:button>
                @if ($importResult)<flux:text class="text-sm text-green-700 dark:text-green-400">{{ $importResult }}</flux:text>@endif
            </form>
        </aside>
    </div>
</div>
