<x-layouts.public title="Email preferences">
    <x-slot:head><meta name="robots" content="noindex"></x-slot:head>
    <section class="mx-auto flex max-w-md flex-col items-center gap-4 px-4 py-20 text-center">
        <flux:icon name="envelope" class="size-10 text-zinc-400" />
        @if ($done ?? false)
            <h1 class="text-2xl font-semibold">You’re unsubscribed</h1>
            <p class="text-zinc-600 dark:text-zinc-400">{{ $contact->email }} won’t get more emails from {{ $sender }}.</p>
            <form method="POST" action="{{ route('email.resubscribe', $contact->token) }}">
                @csrf
                <button class="text-sm underline">Unsubscribed by mistake? Subscribe again</button>
            </form>
        @elseif ($back ?? false)
            <h1 class="text-2xl font-semibold">Welcome back</h1>
            <p class="text-zinc-600 dark:text-zinc-400">{{ $contact->email }} is subscribed to {{ $sender }} again.</p>
        @elseif ($contact->status === \App\Models\Contact::UNSUBSCRIBED)
            <h1 class="text-2xl font-semibold">Already unsubscribed</h1>
            <p class="text-zinc-600 dark:text-zinc-400">{{ $contact->email }} doesn’t get emails from {{ $sender }}.</p>
        @else
            <h1 class="text-2xl font-semibold">Unsubscribe from {{ $sender }}?</h1>
            <p class="text-zinc-600 dark:text-zinc-400">{{ $contact->email }} will stop getting their emails.</p>
            <form method="POST" action="{{ route('email.unsubscribe.confirm', $contact->token) }}">
                @csrf
                <button class="rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white dark:bg-white dark:text-zinc-900">Unsubscribe</button>
            </form>
        @endif
    </section>
</x-layouts.public>
