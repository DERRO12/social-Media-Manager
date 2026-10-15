<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $name = '';
    public string $email = '';
    public string $password = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function createClient(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = User::create([...$validated, 'password' => Hash::make($validated['password'])]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->reset('name', 'email', 'password');
        $this->modal('add-client')->close();
        session()->flash('status', "Client {$user->name} created. Share their login details with them.");
        $this->redirectRoute('admin.clients', navigate: true);
    }

    public function toggleSuspend(int $id): void
    {
        $client = User::clients()->findOrFail($id);
        $client->forceFill(['suspended_at' => $client->isSuspended() ? null : now()])->save();
    }

    public function with(): array
    {
        return [
            'clients' => User::clients()
                ->withCount(['socialAccounts', 'posts'])
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")))
                ->latest()
                ->paginate(20),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Clients</flux:heading>
            <flux:text>Each client only sees their own workspace. Use “Manage” to open a client’s workspace as them.</flux:text>
        </div>
        <flux:modal.trigger name="add-client">
            <flux:button variant="primary" icon="plus">Add client</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search name or email…" class="max-w-sm" />

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Client</th>
                    <th class="px-4 py-3 font-medium">Joined</th>
                    <th class="px-4 py-3 font-medium">Accounts</th>
                    <th class="px-4 py-3 font-medium">Posts</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($clients as $client)
                    <tr wire:key="client-{{ $client->id }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :name="$client->name" />
                                <div>
                                    <div class="font-medium">{{ $client->name }}</div>
                                    <div class="text-zinc-500">{{ $client->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-500">{{ $client->created_at->toFormattedDateString() }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $client->social_accounts_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $client->posts_count }}</td>
                        <td class="px-4 py-3">
                            @if ($client->isSuspended())
                                <flux:badge size="sm" color="red">Suspended</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">Active</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('admin.impersonate', $client) }}">
                                    @csrf
                                    <flux:button size="sm" type="submit" icon="arrow-right-end-on-rectangle">Manage</flux:button>
                                </form>
                                <flux:button size="sm" :variant="$client->isSuspended() ? 'filled' : 'danger'"
                                    wire:click="toggleSuspend({{ $client->id }})"
                                    wire:confirm="{{ $client->isSuspended() ? 'Reactivate' : 'Suspend' }} {{ $client->name }}?">
                                    {{ $client->isSuspended() ? 'Reactivate' : 'Suspend' }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500">No clients yet. They can sign up at {{ route('register') }}, or you can add one.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $clients->links() }}

    <flux:modal name="add-client" class="w-full max-w-md">
        <form wire:submit="createClient" class="flex flex-col gap-4">
            <flux:heading size="lg">Add client</flux:heading>
            <flux:input wire:model="name" label="Name" required />
            <flux:input wire:model="email" type="email" label="Email" required />
            <flux:input wire:model="password" type="password" label="Temporary password" required viewable />
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create client</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
