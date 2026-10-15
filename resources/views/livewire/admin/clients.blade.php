<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $billing = '';

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public $trialDays = null;
    public $planId = null;

    public function mount(SubscriptionManager $manager): void
    {
        $this->trialDays = $manager->defaultTrialDays();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'billing'])) {
            $this->resetPage();
        }
    }

    public function createClient(SubscriptionManager $manager): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            'trialDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'planId' => ['nullable', 'exists:plans,id'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // Override the default sign-up trial with what the admin chose here.
        if ((int) $this->trialDays > 0) {
            $manager->startTrial($user, (int) $this->trialDays, $this->planId ? Plan::find($this->planId) : null);
        } else {
            $manager->expire($user);
        }

        $this->reset('name', 'email', 'password', 'planId');
        $this->modal('add-client')->close();
        session()->flash('status', "Client {$user->name} created. Share their login details with them.");
        $this->redirectRoute('admin.client', $user, navigate: true);
    }

    public function with(): array
    {
        return [
            'plans' => Plan::orderBy('sort')->get(),
            'clients' => User::clients()
                ->with('subscription.plan')
                ->withCount(['socialAccounts', 'posts'])
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")))
                ->when($this->billing === 'none', fn ($q) => $q->doesntHave('subscription'))
                ->when($this->billing && $this->billing !== 'none', fn ($q) => $q->withBillingStatus($this->billing))
                ->latest()
                ->paginate(20),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Clients</flux:heading>
            <flux:text>Each client only sees their own workspace. Open a client to manage their trial, plan and access.</flux:text>
        </div>
        <flux:modal.trigger name="add-client">
            <flux:button variant="primary" icon="plus">Add client</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search name or email…" class="max-w-sm" />
        <flux:select wire:model.live="billing" class="w-48">
            <option value="">All billing statuses</option>
            @foreach (SubscriptionStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Client</th>
                    <th class="px-4 py-3 font-medium">Plan</th>
                    <th class="px-4 py-3 font-medium">Billing</th>
                    <th class="px-4 py-3 font-medium">Access until</th>
                    <th class="px-4 py-3 font-medium">Accounts</th>
                    <th class="px-4 py-3 font-medium">Posts</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($clients as $client)
                    @php($sub = $client->subscription)
                    <tr wire:key="client-{{ $client->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.client', $client) }}" wire:navigate class="flex items-center gap-3 hover:underline">
                                <x-avatar size="sm" :name="$client->name" />
                                <div>
                                    <div class="font-medium">{{ $client->name }}</div>
                                    <div class="text-zinc-500">{{ $client->email }}</div>
                                </div>
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ $sub?->plan?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @if ($sub)
                                    <flux:badge size="sm" :color="$sub->effectiveStatus()->color()">{{ $sub->effectiveStatus()->label() }}</flux:badge>
                                    @if ($sub->isStripe())
                                        <flux:badge size="sm" icon="credit-card">Stripe</flux:badge>
                                    @endif
                                @else
                                    <flux:badge size="sm" color="red">None</flux:badge>
                                @endif
                                @if ($client->isSuspended())
                                    <flux:badge size="sm" color="red">Suspended</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-500">
                            @if ($sub?->onTrial())
                                {{ $sub->trial_ends_at->toFormattedDateString() }} ({{ $sub->trialDaysLeft() }}d left)
                            @elseif ($sub?->accessEndsAt())
                                {{ $sub->accessEndsAt()->toFormattedDateString() }}
                            @elseif ($sub?->isValid())
                                No end date
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ $client->social_accounts_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $client->posts_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" :href="route('admin.client', $client)" wire:navigate>Open</flux:button>
                                <form method="POST" action="{{ route('admin.impersonate', $client) }}">
                                    @csrf
                                    <flux:button size="sm" type="submit" icon="arrow-right-end-on-rectangle">Manage</flux:button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-zinc-500">No clients found. Clients can sign up at {{ route('register') }}, or you can add one.</td>
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
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="trialDays" type="number" min="0" label="Free trial (days)" description="0 = no trial" />
                <flux:select wire:model="planId" label="Trial plan">
                    <option value="">Default</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create client</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
