<?php

use App\Models\Plan;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public string $price = '0';
    public string $currency = 'USD';
    public string $interval = 'month';
    public string $maxAccounts = '';
    public string $maxPosts = '';
    public string $stripePriceId = '';
    public string $paypalUrl = '';
    public string $yoUrl = '';
    public bool $isActive = true;
    public int $sort = 0;

    public function create(): void
    {
        $this->resetForm();
        $this->modal('plan-form')->show();
    }

    public function edit(int $id): void
    {
        $plan = Plan::findOrFail($id);

        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->description = (string) $plan->description;
        $this->price = (string) $plan->price;
        $this->currency = $plan->currency;
        $this->interval = $plan->interval;
        $this->maxAccounts = (string) $plan->max_social_accounts;
        $this->maxPosts = (string) $plan->max_posts_per_month;
        $this->stripePriceId = (string) $plan->stripe_price_id;
        $this->paypalUrl = (string) $plan->paypal_url;
        $this->yoUrl = (string) $plan->yo_url;
        $this->isActive = $plan->is_active;
        $this->sort = $plan->sort;
        $this->resetErrorBag();

        $this->modal('plan-form')->show();
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', 'in:month,year'],
            'maxAccounts' => ['nullable', 'integer', 'min:1'],
            'maxPosts' => ['nullable', 'integer', 'min:1'],
            'stripePriceId' => ['nullable', 'string', 'starts_with:price_'],
            'paypalUrl' => ['nullable', 'url:https', 'max:2000'],
            'yoUrl' => ['nullable', 'url:https', 'max:2000'],
            'sort' => ['integer', 'min:0'],
        ], [
            'stripePriceId.starts_with' => 'Stripe price IDs start with "price_".',
            'paypalUrl.url' => 'Paste the full PayPal link, starting with https://',
            'yoUrl.url' => 'Paste the full Yo! Payments link, starting with https://',
        ]);

        ($this->editingId ? Plan::findOrFail($this->editingId) : new Plan)->fill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'price' => $this->price,
            'currency' => strtoupper($this->currency),
            'interval' => $this->interval,
            'max_social_accounts' => $this->maxAccounts === '' ? null : (int) $this->maxAccounts,
            'max_posts_per_month' => $this->maxPosts === '' ? null : (int) $this->maxPosts,
            'stripe_price_id' => $this->stripePriceId ?: null,
            'paypal_url' => $this->paypalUrl ?: null,
            'yo_url' => $this->yoUrl ?: null,
            'is_active' => $this->isActive,
            'sort' => $this->sort,
        ])->save();

        $this->modal('plan-form')->close();
        $this->resetForm();
    }

    public function toggle(int $id): void
    {
        $plan = Plan::findOrFail($id);
        $plan->update(['is_active' => ! $plan->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'price', 'currency', 'interval', 'maxAccounts', 'maxPosts', 'stripePriceId', 'paypalUrl', 'yoUrl', 'isActive', 'sort');
        $this->resetErrorBag();
    }

    public function with(): array
    {
        return [
            'plans' => Plan::withCount(['subscriptions as clients_count'])->orderBy('sort')->orderBy('price')->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Plans</flux:heading>
            <flux:text>Set what clients pay and what each plan allows. Leave a limit empty for unlimited.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">New plan</flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $plan)
            <div wire:key="plan-{{ $plan->id }}" @class(['flex flex-col rounded-xl border p-5', 'border-zinc-200 dark:border-zinc-700', 'opacity-60' => ! $plan->is_active])>
                <div class="flex items-start justify-between gap-2">
                    <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                    @if ($plan->is_active)
                        <flux:badge size="sm" color="green">Available</flux:badge>
                    @else
                        <flux:badge size="sm">Hidden</flux:badge>
                    @endif
                </div>
                <div class="mt-2 text-2xl font-semibold">{{ $plan->formattedPrice() }}</div>
                @if ($plan->description)
                    <flux:text class="mt-1">{{ $plan->description }}</flux:text>
                @endif
                <ul class="mt-4 flex-1 space-y-1 text-sm">
                    <li>Social accounts: <strong>{{ $plan->limitLabel($plan->max_social_accounts) }}</strong></li>
                    <li>Posts per month: <strong>{{ $plan->limitLabel($plan->max_posts_per_month) }}</strong></li>
                    <li>Payment: <strong>{{ collect([$plan->stripe_price_id ? 'Stripe' : null, $plan->paypal_url ? 'PayPal' : null, $plan->yo_url ? 'Yo! Payments' : null])->filter()->implode(', ') ?: 'Manual only' }}</strong></li>
                    <li>Clients on this plan: <strong>{{ $plan->clients_count }}</strong></li>
                </ul>
                <div class="mt-4 flex gap-2">
                    <flux:button size="sm" icon="pencil" wire:click="edit({{ $plan->id }})">Edit</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="toggle({{ $plan->id }})">{{ $plan->is_active ? 'Hide from clients' : 'Make available' }}</flux:button>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>No plans yet. Create one so clients can subscribe.</flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal name="plan-form" class="w-full max-w-lg">
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit plan' : 'New plan' }}</flux:heading>
            <flux:input wire:model="name" label="Name" placeholder="Pro" required />
            <flux:input wire:model="description" label="Short description" placeholder="For growing creators" />
            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="price" type="number" step="0.01" min="0" label="Price" required />
                <flux:input wire:model="currency" label="Currency" maxlength="3" required />
                <flux:select wire:model="interval" label="Billed">
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </flux:select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="maxAccounts" type="number" min="1" label="Max social accounts" placeholder="Unlimited" />
                <flux:input wire:model="maxPosts" type="number" min="1" label="Max posts / month" placeholder="Unlimited" />
            </div>
            <div class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                <div class="text-sm font-medium">Payment links (optional)</div>
                <flux:input wire:model="paypalUrl" type="url" label="PayPal payment link" placeholder="https://www.paypal.com/ncp/payment/… or https://paypal.me/yourname/39"
                    description="Create it in PayPal → Pay links & buttons, for this plan's price." />
                <flux:input wire:model="yoUrl" type="url" label="Yo! Payments link (Mobile Money)" placeholder="https://…"
                    description="Your Yo! Payments payment page or link for this plan's price (MTN / Airtel Mobile Money)." />
                <flux:text class="text-xs">Clients pay with the link, then send you their transaction reference. You approve it under Admin → Payments to activate the plan.</flux:text>
            </div>
            <flux:input wire:model="stripePriceId" label="Stripe Price ID (optional)" placeholder="price_..."
                description="Only needed for online card payments. Create the price in your Stripe dashboard and paste its ID." />
            <div class="grid grid-cols-2 items-end gap-3">
                <flux:input wire:model="sort" type="number" min="0" label="Display order" />
                <flux:checkbox wire:model="isActive" label="Available to clients" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save plan</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
