@php
    $issuer = \App\Models\Setting::get('academy.issuer', \App\Models\Setting::get('legal.owner', config('app.name')));
    $signName = \App\Models\Setting::get('academy.signatory_name');
    $signTitle = \App\Models\Setting::get('academy.signatory_title');
    $valid = $certificate->isValid();
    $isOwner = auth()->id() === $certificate->user_id;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => "Certificate {$certificate->code}: {$certificate->recipient_name}"])
    <meta name="robots" content="noindex">
    <meta property="og:title" content="{{ $certificate->recipient_name }} completed {{ $certificate->course_title }}">
    <meta property="og:description" content="Verified certificate {{ $certificate->code }} issued by {{ $issuer }}.">
    <style>
        @page { size: A4 landscape; margin: 0; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .sheet { box-shadow: none !important; margin: 0 !important; width: 297mm !important; height: 210mm !important; max-width: none !important; border-radius: 0 !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="min-h-screen bg-zinc-100 text-zinc-900 antialiased">
    {{-- Verification bar --}}
    <div class="no-print mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-5">
        <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
            <span class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white"><x-app-logo-icon class="size-5" /></span>
            {{ config('app.name') }}
        </a>
        @if ($valid)
            <div class="flex items-center gap-2 rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
                <flux:icon name="check-badge" variant="solid" class="size-5" /> Verified certificate · {{ $certificate->code }}
            </div>
        @else
            <div class="flex items-center gap-2 rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-800">
                <flux:icon name="x-circle" variant="solid" class="size-5" /> This certificate has been revoked
            </div>
        @endif
    </div>

    {{-- Certificate --}}
    <div class="sheet relative mx-auto aspect-[297/210] w-full max-w-5xl overflow-hidden bg-white shadow-2xl sm:rounded-lg @unless($valid) opacity-60 @endunless">
        <div class="absolute inset-0 bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 p-[1.6%]">
            <div class="flex size-full flex-col items-center justify-between bg-white px-[7%] py-[5%] text-center">
                <div class="flex flex-col items-center">
                    <span class="flex size-[clamp(2.5rem,6vw,4rem)] items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 via-fuchsia-500 to-orange-400 text-white">
                        <x-app-logo-icon class="size-3/5" />
                    </span>
                    <div class="mt-[2%] text-[clamp(0.6rem,1.4vw,0.95rem)] font-semibold uppercase tracking-[0.3em] text-zinc-500">{{ $issuer }}</div>
                </div>

                <div class="flex flex-col items-center">
                    <div class="text-[clamp(1.2rem,4vw,2.8rem)] font-semibold tracking-tight">Certificate of Completion</div>
                    <div class="mt-[2%] text-[clamp(0.7rem,1.6vw,1.05rem)] text-zinc-500">This certifies that</div>
                    <div class="mt-[1.5%] bg-linear-to-r from-indigo-600 via-fuchsia-600 to-orange-500 bg-clip-text text-[clamp(1.4rem,5vw,3.4rem)] font-semibold text-transparent">
                        {{ $certificate->recipient_name }}
                    </div>
                    <div class="mt-[1.5%] text-[clamp(0.7rem,1.6vw,1.05rem)] text-zinc-500">has successfully completed the course</div>
                    <div class="mt-[1%] text-[clamp(0.9rem,2.4vw,1.6rem)] font-semibold">{{ $certificate->course_title }}</div>
                    @if ($certificate->score !== null)
                        <div class="mt-[1%] text-[clamp(0.6rem,1.3vw,0.9rem)] text-zinc-500">with a final exam score of {{ $certificate->score }}%</div>
                    @endif
                </div>

                <div class="grid w-full grid-cols-3 items-end gap-4 text-[clamp(0.55rem,1.2vw,0.85rem)]">
                    <div class="text-left">
                        <div class="font-semibold">{{ $certificate->issued_at->format('F j, Y') }}</div>
                        <div class="border-t border-zinc-300 pt-1 text-zinc-500">Date issued</div>
                    </div>
                    <div class="flex justify-center">
                        <div class="flex size-[clamp(3rem,9vw,6rem)] flex-col items-center justify-center rounded-full border-4 border-double border-fuchsia-400 text-fuchsia-600">
                            <flux:icon name="check-badge" variant="solid" class="size-2/5" />
                            <span class="text-[clamp(0.4rem,0.9vw,0.65rem)] font-bold uppercase tracking-wider">Certified</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold">{{ $signName ?: $issuer }}</div>
                        <div class="border-t border-zinc-300 pt-1 text-zinc-500">{{ $signTitle ?: 'Issued by '.$issuer }}</div>
                    </div>
                </div>

                <div class="text-[clamp(0.45rem,0.95vw,0.7rem)] text-zinc-400">
                    Certificate ID {{ $certificate->code }} · Verify at {{ $certificate->verifyUrl() }}
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="no-print mx-auto flex max-w-5xl flex-wrap items-center justify-center gap-3 px-4 py-8">
        @if ($valid)
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700">
                <flux:icon name="printer" variant="mini" /> Print or save as PDF
            </button>
            @if ($isOwner)
                <a href="{{ $certificate->linkedInUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-5 py-2.5 text-sm font-medium hover:bg-zinc-50">
                    <flux:icon name="plus" variant="mini" /> Add to LinkedIn profile
                </a>
            @endif
        @endif
        <a href="{{ route('courses.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-5 py-2.5 text-sm font-medium hover:bg-zinc-50">
            Explore {{ config('app.name') }} courses
        </a>
    </div>
    @if ($valid)
        <p class="no-print pb-10 text-center text-xs text-zinc-500">Tip: in the print dialog choose “Save as PDF”, landscape, and turn off headers and footers.</p>
    @endif
</body>
</html>
