@php
    $trialDays = (int) \App\Models\Setting::get('billing.trial_days', 14);
    $groups = $lessons->groupBy(fn ($l) => $l->course_module_id ?? 0);
    $sections = $modules->push((object) ['id' => 0, 'title' => $modules->isEmpty() ? 'Lessons' : 'More lessons']);
    $cta = auth()->check() ? route('academy.course', $course) : route('register');
    $ctaLabel = auth()->check() ? 'Open in my Academy' : ($trialDays > 0 ? "Start free for {$trialDays} days" : 'Create your account');
@endphp

<x-layouts.public :title="$course->title" :description="$course->summary">
    <section class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_360px]">
            <div>
                <a href="{{ route('courses.index') }}" class="text-sm text-zinc-500 hover:underline">← All courses</a>
                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-indigo-100 px-2.5 py-1 font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ $course->levelLabel() }}</span>
                    <span class="rounded-full bg-zinc-200 px-2.5 py-1 font-medium dark:bg-zinc-800">{{ $lessons->count() }} lessons</span>
                    @if ($course->durationLabel())<span class="rounded-full bg-zinc-200 px-2.5 py-1 font-medium dark:bg-zinc-800">{{ $course->durationLabel() }}</span>@endif
                    @if ($course->certificate_enabled)<span class="rounded-full bg-green-100 px-2.5 py-1 font-medium text-green-700 dark:bg-green-950 dark:text-green-300">Certificate included</span>@endif
                </div>
                <h1 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $course->title }}</h1>
                @if ($course->summary)
                    <p class="mt-3 text-lg text-zinc-600 dark:text-zinc-400">{{ $course->summary }}</p>
                @endif
                @if ($certifiedCount >= 5)
                    <p class="mt-4 text-sm text-zinc-500"><strong class="text-zinc-900 dark:text-white">{{ $certifiedCount }}</strong> people certified</p>
                @endif
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-lg lg:-mb-24 dark:border-zinc-800 dark:bg-zinc-900">
                @if ($cover = $course->coverUrl())
                    <img src="{{ $cover }}" alt="" class="aspect-video w-full rounded-xl object-cover" referrerpolicy="no-referrer">
                @endif
                <a href="{{ $cta }}" class="mt-4 block rounded-xl bg-zinc-900 px-4 py-3 text-center font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900">{{ $ctaLabel }}</a>
                <ul class="mt-4 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <li class="flex items-center gap-2"><flux:icon name="play-circle" variant="mini" /> {{ $lessons->count() }} lessons{{ $course->durationLabel() ? ', '.$course->durationLabel() : '' }}</li>
                    @if ($course->questions_count)<li class="flex items-center gap-2"><flux:icon name="pencil-square" variant="mini" /> Final quiz with explanations</li>@endif
                    @if ($course->certificate_enabled)<li class="flex items-center gap-2"><flux:icon name="check-badge" variant="mini" /> Verifiable certificate, add it to LinkedIn</li>@endif
                    <li class="flex items-center gap-2"><flux:icon name="device-phone-mobile" variant="mini" /> Learn on phone or computer</li>
                </ul>
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_360px]">
        <div class="flex min-w-0 flex-col gap-10">
            @if ($course->description)
                <section>
                    <h2 class="text-xl font-semibold">About this course</h2>
                    <div class="mt-3 leading-relaxed text-zinc-700 dark:text-zinc-300 [&_li]:mt-1 [&_p]:mt-3 [&_strong]:text-zinc-900 dark:[&_strong]:text-white [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:ps-6">
                        {!! \Illuminate\Support\Str::markdown($course->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                </section>
            @endif

            @if ($previews->isNotEmpty())
                <section>
                    <h2 class="text-xl font-semibold">Free preview</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($previews as $preview)
                            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">
                                @if ($preview->isVideo())
                                    <div class="aspect-video bg-black">
                                        <iframe src="{{ $preview->embedUrl() }}" title="{{ $preview->title }}" class="size-full" loading="lazy"
                                            allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                                    </div>
                                @endif
                                <div class="p-3 text-sm font-medium">{{ $preview->title }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section>
                <h2 class="text-xl font-semibold">What you’ll learn</h2>
                <div class="mt-4 flex flex-col gap-3">
                    @foreach ($sections as $section)
                        @if ($groups->has($section->id))
                            <details class="group overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800" @if ($loop->first) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between bg-zinc-50 px-4 py-3 font-medium dark:bg-zinc-900">
                                    {{ $section->title }}
                                    <span class="flex items-center gap-2 text-xs font-normal text-zinc-500">
                                        {{ $groups[$section->id]->count() }} lessons
                                        <flux:icon name="chevron-down" variant="micro" class="transition group-open:rotate-180" />
                                    </span>
                                </summary>
                                @foreach ($groups[$section->id] as $lesson)
                                    <div class="flex items-center gap-3 border-t border-zinc-200 px-4 py-3 text-sm dark:border-zinc-800">
                                        <flux:icon :name="$lesson->isVideo() ? 'play-circle' : 'document-text'" class="size-5 shrink-0 text-zinc-400" />
                                        <span class="min-w-0 flex-1">{{ $lesson->title }}</span>
                                        @if ($lesson->is_preview)
                                            <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">Free preview</span>
                                        @else
                                            <flux:icon name="lock-closed" variant="micro" class="text-zinc-400" />
                                        @endif
                                        @if ($lesson->duration_minutes)<span class="text-xs text-zinc-500">{{ $lesson->duration_minutes }} min</span>@endif
                                    </div>
                                @endforeach
                            </details>
                        @endif
                    @endforeach
                </div>
            </section>
        </div>
        <div></div>
    </div>

    <section class="px-4 pb-20 sm:px-6">
        <div class="mx-auto max-w-5xl rounded-3xl bg-linear-to-br from-indigo-600 via-fuchsia-600 to-orange-500 px-6 py-12 text-center text-white">
            <h2 class="text-2xl font-semibold sm:text-3xl">Learn it, then do it, all in one place</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/85">Every plan includes the Academy plus the tools to post everywhere, schedule, and answer every comment from one inbox.</p>
            <a href="{{ $cta }}" class="mt-6 inline-block rounded-xl bg-white px-6 py-3 font-medium text-zinc-900 hover:bg-zinc-100">{{ $ctaLabel }}</a>
        </div>
    </section>
</x-layouts.public>
