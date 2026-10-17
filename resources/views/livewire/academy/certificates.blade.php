<?php

use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'certificates' => auth()->user()->certificates()->with('course')->latest('issued_at')->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:link :href="route('academy')" wire:navigate class="text-sm">← Academy</flux:link>
        <flux:heading size="xl" class="mt-1">My certificates</flux:heading>
        <flux:text>Each certificate has its own verification page you can share with employers and clients.</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($certificates as $cert)
            <div wire:key="cert-{{ $cert->id }}" class="flex flex-col gap-3 rounded-2xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-start gap-3">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white">
                        <flux:icon name="academic-cap" class="size-6" />
                    </span>
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $cert->course_title }}</div>
                        <div class="text-sm text-zinc-500">
                            Issued {{ $cert->issued_at->toFormattedDateString() }}
                            @if ($cert->score !== null) · Score {{ $cert->score }}% @endif
                        </div>
                        <div class="font-mono text-xs text-zinc-500">{{ $cert->code }}</div>
                    </div>
                    @unless ($cert->isValid())
                        <flux:badge size="sm" color="red" class="ms-auto">Revoked</flux:badge>
                    @endunless
                </div>
                @if ($cert->isValid())
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" icon="eye" :href="route('certificates.show', $cert)" target="_blank">View & print</flux:button>
                        <flux:button size="sm" icon="plus" :href="$cert->linkedInUrl()" target="_blank">Add to LinkedIn</flux:button>
                        <flux:button size="sm" variant="ghost" icon="link" x-data x-on:click="navigator.clipboard.writeText('{{ $cert->verifyUrl() }}'); $el.querySelector('[data-label]').textContent = 'Copied!'">
                            <span data-label>Copy link</span>
                        </flux:button>
                    </div>
                @endif
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:icon name="academic-cap" class="mx-auto size-10 text-zinc-300" />
                <flux:heading class="mt-2">No certificates yet</flux:heading>
                <flux:text class="mt-1">Finish a course and pass its final quiz to earn your first one.</flux:text>
                <flux:button class="mt-4" variant="primary" :href="route('academy')" wire:navigate>Browse courses</flux:button>
            </div>
        @endforelse
    </div>
</div>
