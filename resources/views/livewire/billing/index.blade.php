<?php

use App\Models\PaymentClaim;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PaymentClaimSubmitted;
use App\Services\Billing\StripeBilling;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    // "I've paid" form
    public $claimPlanId = null;
    public string $claimMethod = '';
    public string $claimReference = '';
    public string $claimNote = '';

    public function mount(): void
    {
        // Admins have no subscription of their own.
        if (auth()->user()->isAdmin() && ! session('impersonator_id')) {
            $this->redirectRoute('admin.plans', navigate: true);
        }
    }

    public function startClaim(int $planId, string $method): void
    {
        $this->claimPlanId = $planId;
        $this->claimMethod = $method;
        $this->reset('claimReference', 'claimNote');
        $this->resetErrorBag();
        $this->modal('claim-form')->show();
    }

    public function submitClaim(): void
    {
        $user = auth()->user();

        $this->validate([
            'claimPlanId' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'claimMethod' => ['required', Rule::in(array_keys(PaymentClaim::METHODS))],
            'claimReference' => ['required', 'string', 'max:120'],
            'claimNote' => ['nullable', 'string', 'max:500'],
        ], [
            'claimReference.required' => 'Enter the transaction ID or reference from your payment receipt or SMS.',
        ]);

        if ($user->paymentClaims()->pending()->count() >= 3) {
            $this->addError('claimReference', 'You already have payments waiting to be checked. We’ll confirm them shortly.');

            return;
        }

        $plan = Plan::find($this->claimPlanId);
        $claim = $user->paymentClaims()->create([
            'plan_id' => $plan->id,
            'method' => $this->claimMethod,
            'reference' => trim($this->claimReference),
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'note' => $this->claimNote ?: null,
        ]);

        // Email is best-effort: a mail problem must never block recording the payment.
        rescue(fn () => Notification::send(User::where('is_admin', true)->get(), new PaymentClaimSubmitted($claim)));

        $this->modal('claim-form')->close();
        session()->flash('status', 'Thanks! We’ve received your payment details and will activate your plan as soon as we confirm it.');
        $this->redirectRoute('billing', navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();
        $sub = $user->subscription()->with('plan')->first();

        return [
            'sub' => $sub,
            'plans' => Plan::active()->get(),
            'stripe' => app(StripeBilling::class)->enabled(),
            'claims' => $user->paymentClaims()->with('plan')->latest()->limit(5)->get(),
            'methods' => PaymentClaim::METHODS,
            'contact' => Setting::get('billing.support_contact'),
            'usage' => [
                'accounts' => $user->socialAccounts()->count(),
                'posts' => $user->postsThisMonth(),
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-8">
    <div>
        <flux:heading size="xl">Plan &amp; billing</flux:heading>
        <flux:text>Your subscription, usage and available plans.</flux:text>
    </div>

    {{-- Current status --}}
    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-sm text-zinc-500">Current plan</div>
                <div class="mt-1 flex items-center gap-2">
                    <span class="text-xl font-semibold">{{ $sub?->plan?->name ?? 'No plan' }}</span>
                    @if ($sub)
                        <flux:badge size="sm" :color="$sub->effectiveStatus()->color()">{{ $sub->effectiveStatus()->label() }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1">
                    @if ($sub?->onTrial())
                        Your free trial ends {{ auth()->user()->localTime($sub->trial_ends_at, 'l, M j, Y') }} ({{ $sub->trialDaysLeft() }} days left).
                    @elseif ($sub?->isValid() && $sub->accessEndsAt())
                        {{ $sub->effectiveStatus()->value === 'canceled' ? 'Access ends' : 'Renews / paid until' }} {{ auth()->user()->localTime($sub->accessEndsAt(), 'M j, Y') }}.
                    @elseif ($sub?->isValid())
                        Active with no end date.
                    @else
                        You don't have an active plan. Choose one below to use your workspace.
                    @endif
                </flux:text>
            </div>

            @if ($sub?->isStripe())
                <form method="POST" action="{{ route('billing.portal') }}">
                    @csrf
                    <flux:button type="submit" icon="credit-card">Manage payment &amp; invoices</flux:button>
                </form>
            @endif
        </div>

        @if ($sub?->isValid() && $sub->plan)
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['Social accounts', $usage['accounts'], $sub->plan->max_social_accounts],
                    ['Posts this month', $usage['posts'], $sub->plan->max_posts_per_month],
                ] as [$label, $used, $max])
                    <div>
                        <div class="flex justify-between text-sm">
                            <span>{{ $label }}</span>
                            <span class="tabular-nums">{{ $used }} / {{ $max ?? 'Unlimited' }}</span>
                        </div>
                        @if ($max)
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div @class(['h-full rounded-full', 'bg-red-500' => $used >= $max, 'bg-zinc-800 dark:bg-zinc-200' => $used < $max])
                                    style="width: {{ min(100, round($used / $max * 100)) }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Payments sent through payment links --}}
    @if ($claims->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading class="mb-3">Your payments</flux:heading>
            <div class="divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                @foreach ($claims as $claim)
                    <div class="flex flex-wrap items-center gap-3 py-2">
                        <flux:badge size="sm" :color="$claim->statusColor()">{{ ucfirst($claim->status) }}</flux:badge>
                        <span class="font-medium">{{ $claim->plan?->name }}</span>
                        <span class="text-zinc-500">{{ $claim->methodLabel() }} · ref {{ $claim->reference }}</span>
                        <span class="ms-auto text-zinc-500">{{ $claim->created_at->diffForHumans() }}</span>
                        @if ($claim->status === 'pending')
                            <span class="w-full text-xs text-zinc-500">We’re checking this payment. Your plan will be activated as soon as it’s confirmed.</span>
                        @elseif ($claim->status === 'rejected' && $claim->admin_note)
                            <span class="w-full text-xs text-red-600">{{ $claim->admin_note }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Plans --}}
    <div>
        <flux:heading size="lg" class="mb-4">Plans</flux:heading>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($plans as $plan)
                @php($current = $sub?->isValid() && ! $sub->onTrial() && $sub->plan_id === $plan->id)
                <div wire:key="plan-{{ $plan->id }}" @class([
                    'flex flex-col rounded-xl border p-5',
                    'border-zinc-800 dark:border-zinc-200' => $current,
                    'border-zinc-200 dark:border-zinc-700' => ! $current,
                ])>
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                        @if ($current)
                            <flux:badge size="sm" color="green">Your plan</flux:badge>
                        @endif
                    </div>
                    <div class="mt-2 text-2xl font-semibold">{{ $plan->formattedPrice() }}</div>
                    @if ($plan->description)
                        <flux:text class="mt-1">{{ $plan->description }}</flux:text>
                    @endif
                    <ul class="mt-4 flex-1 space-y-2 text-sm">
                        <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-600" /> {{ $plan->limitLabel($plan->max_social_accounts) }} social accounts</li>
                        <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-600" /> {{ $plan->limitLabel($plan->max_posts_per_month) }} posts per month</li>
                        <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-600" /> Unified inbox &amp; replies</li>
                        <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-600" /> Scheduling &amp; analytics</li>
                        <li class="flex items-center gap-2"><flux:icon name="check" variant="mini" class="text-green-600" /> Marketing courses &amp; certificates</li>
                    </ul>

                    <div class="mt-5 flex flex-col gap-2">
                        @if ($current && ! $sub->isStripe() && $plan->paymentLinks())
                            <flux:text class="text-center text-xs">Renew early: the new period is added to your current one.</flux:text>
                        @elseif ($current)
                            <flux:button class="w-full" disabled>Current plan</flux:button>
                        @endif

                        @if (! $current && $stripe && $plan->stripe_price_id)
                            <form method="POST" action="{{ route('billing.checkout', $plan) }}">
                                @csrf
                                <flux:button type="submit" variant="primary" class="w-full" icon="credit-card">
                                    {{ $sub?->isStripe() && $sub->isValid() ? 'Switch to '.$plan->name : 'Pay by card' }}
                                </flux:button>
                            </form>
                        @endif

                        @unless ($current && $sub->isStripe())
                            @foreach ($plan->paymentLinks() as $method => $url)
                                <div class="flex gap-2" wire:key="pay-{{ $plan->id }}-{{ $method }}">
                                    <flux:button :href="$url" target="_blank" rel="noopener" class="flex-1"
                                        :variant="$loop->first && ! ($stripe && $plan->stripe_price_id) ? 'primary' : 'filled'"
                                        :icon="$method === 'paypal' ? 'globe-alt' : 'device-phone-mobile'">
                                        {{ $method === 'paypal' ? 'Pay with PayPal' : 'Pay with Mobile Money' }}
                                    </flux:button>
                                    <flux:button wire:click="startClaim({{ $plan->id }}, '{{ $method }}')" title="Tell us you've paid">I’ve paid</flux:button>
                                </div>
                            @endforeach
                        @endunless

                        @if (! $current && ! ($stripe && $plan->stripe_price_id) && ! $plan->paymentLinks())
                            <flux:text class="text-center text-sm">
                                To subscribe, contact us{{ $contact ? ':' : '.' }}
                                @if ($contact)
                                    <strong class="block select-all">{{ $contact }}</strong>
                                @endif
                                Your plan is activated as soon as payment is confirmed.
                            </flux:text>
                            <flux:button size="sm" variant="ghost" wire:click="startClaim({{ $plan->id }}, 'other')">I’ve paid another way</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <flux:text>No plans are available yet. Please contact support.</flux:text>
            @endforelse
        </div>
    </div>

    <flux:modal name="claim-form" class="w-full max-w-md">
        <form wire:submit="submitClaim" class="flex flex-col gap-4">
            <flux:heading size="lg">Tell us you’ve paid</flux:heading>
            <flux:text class="text-sm">After paying, enter the transaction ID from your PayPal receipt or Mobile Money SMS. We’ll check it and activate your plan.</flux:text>
            <flux:select wire:model="claimPlanId" label="Plan">
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->formattedPrice() }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="claimMethod" label="Paid with">
                @foreach ($methods as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="claimReference" label="Transaction ID / reference" placeholder="e.g. 7XK12345AB9 or MTN ID 1234567890" required />
            <flux:textarea wire:model="claimNote" label="Note (optional)" rows="2" placeholder="Phone number used, payer name, anything that helps us find it" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Send</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
