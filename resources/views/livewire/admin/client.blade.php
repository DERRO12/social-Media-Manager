<?php

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Support\Carbon;
use Livewire\Volt\Component;

new class extends Component {
    public User $client;

    public $trialDays = 14;
    public $trialPlanId = null;
    public $extendDays = 7;

    public $planId = null;
    public string $paidUntil = '';
    public string $notes = '';

    public function mount(User $user): void
    {
        abort_if($user->isAdmin(), 404);

        $this->client = $user->load('subscription.plan');
        $manager = app(SubscriptionManager::class);
        $this->trialDays = $manager->defaultTrialDays() ?: 14;
        $this->trialPlanId = $this->client->subscription?->plan_id ?? $manager->defaultTrialPlan()?->id;
        $this->planId = $this->client->subscription?->plan_id ?? Plan::active()->value('id');
        $this->paidUntil = now()->addMonth()->format('Y-m-d');
        $this->notes = (string) $this->client->subscription?->notes;
    }

    public function giveTrial(SubscriptionManager $manager): void
    {
        $this->validate([
            'trialDays' => ['required', 'integer', 'min:1', 'max:3650'],
            'trialPlanId' => ['nullable', 'exists:plans,id'],
        ]);

        $manager->startTrial($this->client, (int) $this->trialDays, $this->trialPlanId ? Plan::find($this->trialPlanId) : null);
        $this->done("Free trial of {$this->trialDays} days started.");
    }

    public function extendTrial(SubscriptionManager $manager): void
    {
        $this->validate(['extendDays' => ['required', 'integer', 'min:1', 'max:3650']]);

        $manager->extendTrial($this->client, (int) $this->extendDays);
        $this->done("Trial extended by {$this->extendDays} days.");
    }

    public function activate(SubscriptionManager $manager): void
    {
        $this->validate([
            'planId' => ['required', 'exists:plans,id'],
            'paidUntil' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $until = $this->paidUntil ? Carbon::parse($this->paidUntil, $this->client->timezone)->endOfDay()->utc() : null;
        $manager->activateManually($this->client, Plan::find($this->planId), $until, $this->notes ?: null);
        $this->done('Plan activated'.($until ? ' until '.$until->toFormattedDateString() : ' with no end date').'.');
    }

    public function saveNotes(SubscriptionManager $manager): void
    {
        $manager->record($this->client, ['notes' => $this->notes ?: null]);
        $this->done('Notes saved.');
    }

    public function expireNow(SubscriptionManager $manager): void
    {
        $manager->expire($this->client);
        $this->done('Access ended. The client will be asked to choose a plan.');
    }

    public function toggleSuspend(): void
    {
        $this->client->forceFill(['suspended_at' => $this->client->isSuspended() ? null : now()])->save();
        $this->done($this->client->isSuspended() ? 'Client suspended.' : 'Client reactivated.');
    }

    private function done(string $message): void
    {
        session()->flash('status', $message);
        $this->redirectRoute('admin.client', $this->client, navigate: true);
    }

    public function with(): array
    {
        $client = $this->client->fresh(['subscription.plan']);

        return [
            'sub' => $client->subscription,
            'plans' => Plan::orderBy('sort')->orderBy('price')->get(),
            'accounts' => $client->socialAccounts()->withCount('contentItems')->get(),
            'posts' => $client->posts()->with('targets.account')->latest()->limit(8)->get(),
            'usage' => [
                'accounts' => $client->socialAccounts()->count(),
                'maxAccounts' => $client->currentPlan()?->max_social_accounts,
                'posts' => $client->postsThisMonth(),
                'maxPosts' => $client->currentPlan()?->max_posts_per_month,
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <flux:avatar size="lg" :name="$client->name" />
            <div>
                <flux:heading size="xl">{{ $client->name }}</flux:heading>
                <flux:text>{{ $client->email }} · joined {{ $client->created_at->toFormattedDateString() }}</flux:text>
                <div class="mt-2 flex gap-2">
                    @if ($sub)
                        <flux:badge size="sm" :color="$sub->effectiveStatus()->color()">{{ $sub->effectiveStatus()->label() }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="red">No subscription</flux:badge>
                    @endif
                    @if ($client->isSuspended())
                        <flux:badge size="sm" color="red">Suspended</flux:badge>
                    @endif
                </div>
            </div>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('admin.clients')" wire:navigate variant="ghost" icon="arrow-left">All clients</flux:button>
            <form method="POST" action="{{ route('admin.impersonate', $client) }}">
                @csrf
                <flux:button type="submit" icon="arrow-right-end-on-rectangle">Manage workspace</flux:button>
            </form>
            <flux:button :variant="$client->isSuspended() ? 'filled' : 'danger'" wire:click="toggleSuspend"
                wire:confirm="{{ $client->isSuspended() ? 'Reactivate this client?' : 'Suspend this client? They will be logged out and blocked.' }}">
                {{ $client->isSuspended() ? 'Reactivate' : 'Suspend' }}
            </flux:button>
        </div>
    </div>

    {{-- Subscription summary --}}
    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">Plan</div>
            <div class="mt-1 text-lg font-semibold">{{ $sub?->plan?->name ?? '—' }}</div>
            <div class="text-sm text-zinc-500">{{ $sub?->plan?->formattedPrice() }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">{{ $sub?->onTrial() ? 'Trial ends' : 'Access until' }}</div>
            <div class="mt-1 text-lg font-semibold">
                @if (! $sub)
                    —
                @elseif ($sub->accessEndsAt())
                    {{ $sub->accessEndsAt()->toFormattedDateString() }}
                @else
                    No end date
                @endif
            </div>
            <div class="text-sm text-zinc-500">
                @if ($sub?->onTrial()) {{ $sub->trialDaysLeft() }} days left @elseif ($sub?->accessEndsAt()) {{ $sub->accessEndsAt()->diffForHumans() }} @endif
            </div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">Social accounts</div>
            <div class="mt-1 text-lg font-semibold tabular-nums">{{ $usage['accounts'] }} / {{ $usage['maxAccounts'] ?? '∞' }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">Posts this month</div>
            <div class="mt-1 text-lg font-semibold tabular-nums">{{ $usage['posts'] }} / {{ $usage['maxPosts'] ?? '∞' }}</div>
        </div>
    </div>

    @if ($sub?->isStripe() && $sub->isValid())
        <flux:callout variant="warning" icon="credit-card" heading="This client pays by card through Stripe."
            text="Manual changes here apply right away, but Stripe will overwrite them at the next billing event. To change their plan or cancel, use your Stripe dashboard." />
    @endif

    {{-- Subscription controls --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="giveTrial" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">Give a free trial</flux:heading>
            <flux:text class="text-sm">Starts a new trial from today. Replaces any current plan.</flux:text>
            <flux:input type="number" min="1" wire:model="trialDays" label="Number of days" />
            <flux:select wire:model="trialPlanId" label="Trial on plan">
                <option value="">Default</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" class="mt-auto">Start trial</flux:button>

            @if ($sub?->onTrial())
                <flux:separator class="my-2" />
                <div class="flex items-end gap-2">
                    <flux:input type="number" min="1" wire:model="extendDays" label="Add days to current trial" class="flex-1" />
                    <flux:button wire:click="extendTrial">Extend</flux:button>
                </div>
            @endif
        </form>

        <form wire:submit="activate" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">Activate a plan manually</flux:heading>
            <flux:text class="text-sm">For payments received by bank transfer, mobile money, cash or a special deal.</flux:text>
            <flux:select wire:model="planId" label="Plan">
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->formattedPrice() }}</option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="paidUntil" label="Paid until" description="Leave empty for no end date." />
            <flux:button type="submit" variant="primary" class="mt-auto" :disabled="$plans->isEmpty()">Activate plan</flux:button>
        </form>

        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">Notes &amp; access</flux:heading>
            <flux:textarea wire:model="notes" rows="4" label="Private notes" placeholder="e.g. Paid 3 months via MoMo on Oct 15, ref 12345" />
            <flux:button wire:click="saveNotes">Save notes</flux:button>
            <flux:separator class="my-2" />
            <flux:button variant="danger" wire:click="expireNow" wire:confirm="End this client's access now? They'll be asked to choose a plan next time they open the app." class="mt-auto">
                End access now
            </flux:button>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg" class="mb-3">Connected accounts</flux:heading>
            @forelse ($accounts as $account)
                <div class="flex items-center gap-3 border-b border-zinc-200 py-2 last:border-0 dark:border-zinc-700">
                    <flux:avatar size="xs" :src="$account->avatar_url" :name="$account->name" />
                    <span class="flex-1 truncate text-sm">{{ $account->name }}</span>
                    <x-platform-badge :platform="$account->platform" />
                    @if ($account->last_error)
                        <flux:badge size="sm" color="red" title="{{ $account->last_error }}">Error</flux:badge>
                    @endif
                </div>
            @empty
                <flux:text>None yet.</flux:text>
            @endforelse
        </div>

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg" class="mb-3">Recent posts</flux:heading>
            @forelse ($posts as $post)
                <div class="flex items-center gap-3 border-b border-zinc-200 py-2 last:border-0 dark:border-zinc-700">
                    <flux:badge size="sm" :color="$post->status->color()">{{ $post->status->label() }}</flux:badge>
                    <span class="flex-1 truncate text-sm">{{ $post->title ?: Str::limit($post->caption, 60) ?: 'Untitled' }}</span>
                    <span class="text-xs text-zinc-500">{{ $post->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <flux:text>No posts yet.</flux:text>
            @endforelse
        </div>
    </div>
</div>
