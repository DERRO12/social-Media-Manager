<x-layouts.app.sidebar>
    <flux:main>
        @if (session('impersonator_id'))
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                <span>You are managing <strong>{{ auth()->user()->name }}</strong>'s workspace as admin.</span>
                <form method="POST" action="{{ route('impersonate.stop') }}">
                    @csrf
                    <flux:button size="sm" type="submit" icon="arrow-uturn-left">Back to admin</flux:button>
                </form>
            </div>
        @endif

        @foreach (['status' => 'green', 'error' => 'red'] as $key => $color)
            @if (session($key))
                <flux:callout :variant="$key === 'error' ? 'danger' : 'success'" class="mb-6" :heading="session($key)" />
            @endif
        @endforeach

        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
