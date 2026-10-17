<?php

use App\Models\Certificate;
use App\Models\Setting;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $issuer = '';
    public string $signatoryName = '';
    public string $signatoryTitle = '';

    public function mount(): void
    {
        $this->issuer = (string) Setting::get('academy.issuer', '');
        $this->signatoryName = (string) Setting::get('academy.signatory_name', '');
        $this->signatoryTitle = (string) Setting::get('academy.signatory_title', '');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function saveDesign(): void
    {
        $this->validate([
            'issuer' => ['nullable', 'string', 'max:120'],
            'signatoryName' => ['nullable', 'string', 'max:120'],
            'signatoryTitle' => ['nullable', 'string', 'max:120'],
        ]);

        Setting::put('academy.issuer', $this->issuer);
        Setting::put('academy.signatory_name', $this->signatoryName);
        Setting::put('academy.signatory_title', $this->signatoryTitle);

        $this->dispatch('saved-design');
    }

    public function toggle(int $id): void
    {
        $certificate = Certificate::findOrFail($id);
        $certificate->update(['revoked_at' => $certificate->revoked_at ? null : now()]);
    }

    public function rename(int $id, string $name): void
    {
        $name = trim($name);
        abort_if($name === '' || mb_strlen($name) > 120, 422);

        Certificate::findOrFail($id)->update(['recipient_name' => $name]);
    }

    public function with(): array
    {
        return [
            'certificates' => Certificate::with('user')
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('code', 'like', "%{$this->search}%")
                    ->orWhere('recipient_name', 'like', "%{$this->search}%")
                    ->orWhere('course_title', 'like', "%{$this->search}%")))
                ->latest('issued_at')
                ->paginate(25),
            'sample' => Certificate::latest('issued_at')->first(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:link :href="route('admin.courses')" wire:navigate class="text-sm">← Courses</flux:link>
        <flux:heading size="xl" class="mt-1">Certificates</flux:heading>
        <flux:text>Every certificate has a public verification page, so employers can check it’s real.</flux:text>
    </div>

    <form wire:submit="saveDesign" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">Certificate wording</flux:heading>
            @if ($sample)
                <flux:link :href="route('certificates.show', $sample)" target="_blank" class="text-sm">Preview the latest certificate</flux:link>
            @endif
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="issuer" label="Issued by" :placeholder="\App\Models\Setting::get('legal.owner', config('app.name'))" description="Organisation name on the certificate and on LinkedIn." />
            <flux:input wire:model="signatoryName" label="Signed by (name)" placeholder="Derrick M." />
            <flux:input wire:model="signatoryTitle" label="Signer’s title" placeholder="Founder & Lead Instructor" />
        </div>
        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save</flux:button>
            <x-action-message on="saved-design">Saved.</x-action-message>
        </div>
    </form>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search by code, name or course…" class="max-w-sm" />

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Code</th>
                    <th class="px-4 py-3 font-medium">Name on certificate</th>
                    <th class="px-4 py-3 font-medium">Course</th>
                    <th class="px-4 py-3 font-medium">Score</th>
                    <th class="px-4 py-3 font-medium">Issued</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($certificates as $cert)
                    <tr wire:key="cert-{{ $cert->id }}" x-data="{ editing: false, name: @js($cert->recipient_name) }">
                        <td class="px-4 py-3"><a href="{{ route('certificates.show', $cert) }}" target="_blank" class="font-mono text-xs underline">{{ $cert->code }}</a></td>
                        <td class="px-4 py-3">
                            <template x-if="! editing">
                                <button type="button" class="text-left hover:underline" x-on:click="editing = true" title="Click to correct the name">{{ $cert->recipient_name }}</button>
                            </template>
                            <template x-if="editing">
                                <form class="flex gap-1" x-on:submit.prevent="$wire.rename({{ $cert->id }}, name); editing = false">
                                    <input x-model="name" class="rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800" maxlength="120">
                                    <flux:button size="xs" type="submit">Save</flux:button>
                                </form>
                            </template>
                            <div class="text-xs text-zinc-500">{{ $cert->user?->email }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $cert->course_title }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $cert->score !== null ? $cert->score.'%' : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-500">
                            {{ $cert->issued_at->toFormattedDateString() }}
                            @unless ($cert->isValid())
                                <flux:badge size="sm" color="red">Revoked</flux:badge>
                            @endunless
                        </td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="xs" :variant="$cert->isValid() ? 'danger' : 'filled'" wire:click="toggle({{ $cert->id }})"
                                wire:confirm="{{ $cert->isValid() ? 'Revoke this certificate?' : 'Restore this certificate?' }}">
                                {{ $cert->isValid() ? 'Revoke' : 'Restore' }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">No certificates issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $certificates->links() }}
</div>
