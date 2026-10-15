<?php

use App\Models\Plan;
use App\Models\Setting;
use App\Services\Billing\StripeBilling;
use Livewire\Volt\Component;

new class extends Component {
    public function mount(): void
    {
        // Admins have no subscription of their own.
        if (auth()->user()->isAdmin() && ! session('impersonator_id')) {
            $this->redirectRoute('admin.plans', navigate: true);
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $sub = $user->subscription()->with('plan')->first();

        return [
            'sub' => $sub,
            'plans' => Plan::active()->get(),
            'stripe' => app(StripeBilling::class)->enabled(),
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
                    </ul>

                    <div class="mt-5">
                        @if ($current)
                            <flux:button class="w-full" disabled>Current plan</flux:button>
                        @elseif ($stripe && $plan->stripe_price_id)
                            <form method="POST" action="{{ route('billing.checkout', $plan) }}">
                                @csrf
                                <flux:button type="submit" variant="primary" class="w-full">
                                    {{ $sub?->isStripe() && $sub->isValid() ? 'Switch to '.$plan->name : 'Subscribe' }}
                                </flux:button>
                            </form>
                        @else
                            <flux:text class="text-center text-sm">
                                To subscribe, contact us{{ $contact ? ':' : '.' }}
                                @if ($contact)
                                    <strong class="block select-all">{{ $contact }}</strong>
                                @endif
                                Your plan is activated as soon as payment is confirmed.
                            </flux:text>
                        @endif
                    </div>
                </div>
            @empty
                <flux:text>No plans are available yet. Please contact support.</flux:text>
            @endforelse
        </div>
    </div>
</div>
