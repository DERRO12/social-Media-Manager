<?php

use App\Models\Contact;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component {
    use WithFileUploads, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $status = 'subscribed';
    #[Url] public string $tag = '';

    public string $email = '';
    public string $name = '';
    public string $tags = '';
    public bool $consent = false;

    public $csv = null;
    public string $importTags = '';
    public bool $importConsent = false;
    public ?string $importResult = null;

    public function updating($property): void
    {
        if (in_array($property, ['search', 'status', 'tag'], true)) {
            $this->resetPage();
        }
    }

    private function roomFor(int $more): bool
    {
        return auth()->user()->contacts()->count() + $more <= Contact::LIMIT;
    }

    public function add(): void
    {
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'string', 'max:200'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Only add people who agreed to get your emails.']);

        $existing = auth()->user()->contacts()->where('email', strtolower(trim($this->email)))->first();
        if ($existing) {
            $this->addError('email', 'This person is already on your list.');

            return;
        }
        if (! $this->roomFor(1)) {
            $this->addError('email', 'You’ve reached '.number_format(Contact::LIMIT).' contacts. Remove some to add more.');

            return;
        }

        auth()->user()->contacts()->create([
            'email' => $this->email,
            'name' => $this->name ?: null,
            'tags' => Contact::normaliseTags($this->tags),
            'source' => 'manual',
        ]);

        $this->reset('email', 'name', 'consent');
        $this->dispatch('contact-added');
    }

    /** CSV with an email column (and optionally name): from Excel, Google Sheets, Mailchimp, a phone… */
    public function import(): void
    {
        $this->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'importTags' => ['nullable', 'string', 'max:200'],
            'importConsent' => ['accepted'],
        ], ['importConsent.accepted' => 'Confirm these people agreed to get your emails.']);

        $handle = fopen($this->csv->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"")), fgetcsv($handle) ?: []);
        $emailCol = collect($header)->search(fn ($h) => str_contains($h, 'email') || str_contains($h, 'e-mail'));
        $nameCol = collect($header)->search(fn ($h) => in_array($h, ['name', 'full name', 'first name', 'firstname'], true));

        // No header row: the first column with an @ is the email.
        if ($emailCol === false) {
            rewind($handle);
            $emailCol = 0;
            $nameCol = 1;
        }

        $tags = Contact::normaliseTags($this->importTags);
        $existing = auth()->user()->contacts()->pluck('email')->flip();
        $room = Contact::LIMIT - $existing->count();
        [$added, $skipped] = [0, 0];

        while (($row = fgetcsv($handle)) !== false) {
            $email = strtolower(trim((string) ($row[$emailCol] ?? '')));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($existing[$email]) || $added >= $room) {
                $skipped++;

                continue;
            }

            $name = $nameCol !== false ? trim((string) ($row[$nameCol] ?? '')) : '';
            auth()->user()->contacts()->create(['email' => $email, 'name' => $name !== '' && ! str_contains($name, '@') ? mb_substr($name, 0, 100) : null, 'tags' => $tags, 'source' => 'import']);
            $existing[$email] = true;
            $added++;
        }
        fclose($handle);

        $this->reset('csv', 'importConsent');
        $this->importResult = "Added {$added} ".str('contact')->plural($added).($skipped ? ", skipped {$skipped} (invalid, duplicate or over the limit)." : '.');
    }

    public function toggle(int $id): void
    {
        $contact = auth()->user()->contacts()->findOrFail($id);

        $contact->status === Contact::SUBSCRIBED
            ? $contact->unsubscribe()
            : $contact->update(['status' => Contact::SUBSCRIBED, 'unsubscribed_at' => null]);
    }

    public function delete(int $id): void
    {
        auth()->user()->contacts()->findOrFail($id)->delete();
    }

    public function export(): StreamedResponse
    {
        $contacts = auth()->user()->contacts()->orderBy('email')->get();

        return response()->streamDownload(function () use ($contacts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'tags', 'status', 'source', 'subscribed_at']);
            foreach ($contacts as $c) {
                fputcsv($out, [$c->email, $c->name, $c->tags, $c->status, $c->source, $c->subscribed_at?->toDateTimeString()]);
            }
            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $user = auth()->user();

        $query = $user->contacts()->latest()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->tagged($this->tag ?: null)
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('email', 'like', "%{$this->search}%")->orWhere('name', 'like', "%{$this->search}%")));

        return [
            'contacts' => $query->paginate(25),
            'subscribed' => $user->contacts()->subscribed()->count(),
            'total' => $user->contacts()->count(),
            'allTags' => $user->contacts()->whereNotNull('tags')->pluck('tags')->flatMap(fn ($t) => explode(', ', $t))->unique()->sort()->values(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.email-tabs')

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <section class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search name or email" class="w-full sm:max-w-xs" />
                <flux:select wire:model.live="status" class="min-w-0 max-sm:flex-1 sm:w-40">
                    <option value="subscribed">Subscribed</option>
                    <option value="unsubscribed">Unsubscribed</option>
                    <option value="all">All</option>
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
                <flux:text class="text-sm">{{ number_format($subscribed) }} subscribed · {{ number_format($total) }} total</flux:text>
                @if ($total)
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="export">Export CSV</flux:button>
                @endif
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-2">Contact</th>
                            <th class="px-4 py-2">Tags</th>
                            <th class="px-4 py-2">Source</th>
                            <th class="px-4 py-2">Added</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($contacts as $contact)
                            <tr wire:key="c-{{ $contact->id }}" @class(['opacity-60' => $contact->status !== 'subscribed'])>
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ $contact->name ?: '—' }}</div>
                                    <div class="text-zinc-500">{{ $contact->email }}</div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($contact->tagList() as $t)<flux:badge size="sm">{{ $t }}</flux:badge>@endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-2 capitalize text-zinc-500">{{ $contact->source }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-zinc-500">{{ $contact->created_at->toFormattedDateString() }}</td>
                                <td class="px-4 py-2 text-right">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" aria-label="Contact options" />
                                        <flux:menu>
                                            <flux:menu.item :icon="$contact->status === 'subscribed' ? 'no-symbol' : 'check'" wire:click="toggle({{ $contact->id }})">
                                                {{ $contact->status === 'subscribed' ? 'Unsubscribe' : 'Subscribe again' }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $contact->id }})" wire:confirm="Delete this contact?">Delete</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-zinc-500">No contacts here yet. Add people on the right, or turn on the sign-up form on your public page.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $contacts->links() }}
        </section>

        <aside class="flex flex-col gap-4">
            <form wire:submit="add" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Add a contact</flux:heading>
                <flux:input wire:model="email" type="email" label="Email" required />
                <flux:input wire:model="name" label="Name" />
                <flux:input wire:model="tags" label="Tags" placeholder="customers, vip" description="Comma-separated. Send campaigns to one tag." />
                <flux:checkbox wire:model="consent" label="They agreed to get my emails" />
                <flux:error name="consent" />
                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">Add</flux:button>
                    <x-action-message on="contact-added">Added.</x-action-message>
                </div>
            </form>

            <form wire:submit="import" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>Import from a spreadsheet</flux:heading>
                <flux:text class="text-sm">A CSV file with an <strong>email</strong> column (and optionally <strong>name</strong>). Save from Excel or Google Sheets as CSV.</flux:text>
                <input type="file" wire:model="csv" accept=".csv,text/csv,text/plain"
                    class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-white">
                <flux:error name="csv" />
                <flux:input wire:model="importTags" label="Tag everyone in this file" placeholder="e.g. customers-2025" />
                <flux:checkbox wire:model="importConsent" label="These people agreed to get my emails" />
                <flux:error name="importConsent" />
                <flux:button type="submit" icon="arrow-up-tray">Import</flux:button>
                @if ($importResult)<flux:text class="text-sm text-green-700 dark:text-green-400">{{ $importResult }}</flux:text>@endif
            </form>
        </aside>
    </div>
</div>
