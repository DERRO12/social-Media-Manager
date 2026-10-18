<x-layouts.app.sidebar>
    <flux:main class="max-sm:!px-4 max-sm:!pt-4">
        @if (session('impersonator_id'))
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                <span>You are managing <strong>{{ auth()->user()->name }}</strong>'s workspace as admin.</span>
                <form method="POST" action="{{ route('impersonate.stop') }}">
                    @csrf
                    <flux:button size="sm" type="submit" icon="arrow-uturn-left">Back to admin</flux:button>
                </form>
            </div>
        @endif

        @php($sub = auth()->user()->isAdmin() ? null : auth()->user()->subscription)
        @if ($sub?->onTrial() && ! request()->routeIs('billing'))
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-sky-50 px-4 py-2 text-sm text-sky-900 dark:bg-sky-900/30 dark:text-sky-100">
                <span>
                    Free trial: <strong>{{ $sub->trialDaysLeft() }} {{ Str::plural('day', $sub->trialDaysLeft()) }} left</strong>
                    (ends {{ auth()->user()->localTime($sub->trial_ends_at, 'M j, Y') }}).
                </span>
                <flux:button size="sm" :href="route('billing')" wire:navigate>Choose a plan</flux:button>
            </div>
        @elseif ($sub && $sub->effectiveStatus()->value === 'past_due')
            <flux:callout variant="warning" class="mb-6" heading="Your last payment didn't go through. Please update your payment method to keep access." />
        @endif

        @foreach (['status' => 'green', 'error' => 'red'] as $key => $color)
            @if (session($key))
                <flux:callout :variant="$key === 'error' ? 'danger' : 'success'" class="mb-6" :heading="session($key)" />
            @endif
        @endforeach

        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
