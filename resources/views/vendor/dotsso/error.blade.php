{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4">
            <h1 class="font-display text-2xl font-semibold text-[var(--ink)]">{{ $headline }}</h1>
        </div>

        <p class="text-sm text-[var(--ink)]" role="alert">{{ $body }}</p>

        <div class="flex items-center justify-between mt-6 gap-4">
            <a class="underline text-sm text-[var(--ink-soft)] hover:text-[var(--ink)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--gold)]" href="{{ $homeUrl }}">Back to Dot.HR</a>
            @if ($retry)<a href="{{ $retryUrl }}" class="inline-flex items-center px-4 py-2 bg-[var(--ink)] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[var(--ink-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2">Start again</a>@endif
        </div>
        <p class="mt-4 text-sm text-[var(--ink-soft)]">Reference: {{ $code }}</p>
    </x-authentication-card>
</x-guest-layout>
