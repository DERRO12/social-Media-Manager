@php($trialDays = (int) \App\Models\Setting::get('billing.trial_days', 14))

<x-layouts.public title="Social media marketing courses" description="Learn social media marketing with short video lessons and practical notes, then earn a verifiable certificate.">
    <section class="relative overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center">
            <div class="h-[30rem] w-[56rem] rounded-full bg-linear-to-tr from-indigo-300/50 via-fuchsia-300/40 to-orange-200/50 blur-3xl dark:from-indigo-600/25 dark:via-fuchsia-600/20 dark:to-orange-500/15"></div>
        </div>
        <div class="mx-auto max-w-6xl px-4 pb-10 pt-16 text-center sm:px-6">
            <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white/70 px-3 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-300">
                <flux:icon name="academic-cap" variant="micro" /> {{ config('app.name') }} Academy
            </span>
            <h1 class="mx-auto mt-5 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">
                Learn social media marketing.
                <span class="bg-linear-to-r from-indigo-600 via-fuchsia-600 to-orange-500 bg-clip-text text-transparent">Get certified.</span>
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                Short video lessons and practical notes, a final quiz, and a certificate employers can verify. Included with every plan.
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                @auth
                    <a href="{{ route('academy') }}" class="rounded-xl bg-zinc-900 px-6 py-3 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">Go to my Academy</a>
                @else
                    <a href="{{ route('register') }}" class="rounded-xl bg-zinc-900 px-6 py-3 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">
                        {{ $trialDays > 0 ? "Start learning free for {$trialDays} days" : 'Create your account' }}
                    </a>
                @endauth
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($courses as $course)
                <x-academy.course-card :course="$course" :href="route('courses.show', $course)" />
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-zinc-300 p-12 text-center text-zinc-500 dark:border-zinc-700">New courses are coming soon.</div>
            @endforelse
        </div>

        <div class="mt-16 grid gap-6 md:grid-cols-3">
            @foreach ([
                ['play-circle', 'Learn at your pace', 'Bite-sized video lessons with written notes. Pick up exactly where you left off.'],
                ['pencil-square', 'Test what you learned', 'A final quiz for every course, with explanations, so the knowledge sticks.'],
                ['check-badge', 'Share your certificate', 'Every certificate has its own verification link and one click adds it to LinkedIn.'],
            ] as [$icon, $title, $text])
                <div class="rounded-2xl border border-zinc-200 p-6 dark:border-zinc-800">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 to-fuchsia-500 text-white"><flux:icon :name="$icon" class="size-5" /></div>
                    <h3 class="mt-4 font-semibold">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>
</x-layouts.public>
