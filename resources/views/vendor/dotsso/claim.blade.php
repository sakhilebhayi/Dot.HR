{{-- R-54: the claim page, in the sign-in card Dot.HR already uses. Shown when an account here already uses the email address of
     the InfoDot sign-in. Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6">
            <h1 class="font-display text-2xl font-semibold text-[var(--ink)]">Confirm it is you</h1>
            <p class="mt-1 text-sm text-[var(--ink-soft)]">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your employee record, applications and teams stay exactly as they are.</p>
        </div>

        @if (session('dotsso_status'))
            <div class="mb-4 font-medium text-sm text-green-700" role="status">{{ session('dotsso_status') }}</div>
        @endif

        <form method="POST" action="{{ $verifyUrl }}" novalidate>
            @csrf
            <div>
                <x-label for="claim-code" value="Code from the email" />
                <x-input id="claim-code" class="block mt-1 w-full tracking-[0.3em]" type="text" name="code" inputmode="numeric" pattern="[0-9]*"
                    autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus aria-describedby="claim-code-hint claim-code-error" />
                <p id="claim-code-hint" class="mt-1 text-sm text-[var(--ink-soft)]">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
                <p id="claim-code-error" class="mt-1 text-sm text-red-700" role="alert" aria-live="polite">{{ $errors->first('code') }}</p>
            </div>
            <div class="flex items-center justify-end mt-4">
                <x-button>Confirm and continue</x-button>
            </div>
        </form>

        <div class="flex items-center justify-between mt-4">
            <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="underline text-sm text-[var(--ink-soft)] hover:text-[var(--ink)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--gold)]">Send a new code</button></form>
            <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="underline text-sm text-[var(--ink-soft)] hover:text-[var(--ink)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--gold)]">Cancel</button></form>
        </div>
    </x-authentication-card>
</x-guest-layout>
