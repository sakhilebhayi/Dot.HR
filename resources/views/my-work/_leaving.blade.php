{{-- Leaving (brief §15), the person's side: notice in writing, the exit interview if they want
     it, and their certificate of service. --}}
@php
    $minimum = \App\Models\Resignation::minimumNoticeDays($employee->start_date, today());
@endphp
<div class="dot-card dot-card--pad" id="leaving">
    <div class="dot-label">Leaving</div>

    @if ($resignation)
        <p style="margin:6px 0 0;">
            You gave notice on {{ $resignation->given_at->format('j F Y') }} ({{ $resignation->reference() }}).
            Your last day is <strong style="font-weight:500;">{{ $resignation->leavingOn()->format('l j F Y') }}</strong>{{ $resignation->agreed_last_day ? ', as agreed with HR' : '' }}.
        </p>
        <p class="dot-note" style="margin:4px 0 0;">{{ \App\Models\Resignation::STATUSES[$resignation->status] }}.</p>
        @if ($resignation->withdrawal_requested_at && ! $resignation->withdrawal_decided_at)
            <p class="dot-note" style="margin:8px 0 0;">You asked to withdraw it on {{ $resignation->withdrawal_requested_at->format('j M') }}. HR will answer.</p>
        @elseif (! $resignation->withdrawal_decided_at)
            <details style="margin-top:10px;">
                <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Ask to withdraw your resignation</summary>
                <form method="POST" action="{{ route('my-work.resignation.withdraw', $resignation) }}" style="display:grid;gap:8px;margin-top:8px;">
                    @csrf
                    <p class="dot-note" style="margin:0;">Once given, notice stands unless HR agrees to let you withdraw it.</p>
                    <label for="wd-note" style="position:absolute;left:-9999px;">Why</label>
                    <textarea id="wd-note" name="note" class="dot-input" rows="2" maxlength="2000" required placeholder="What changed"></textarea>
                    <div><button type="submit" class="dot-btn">Ask HR</button></div>
                </form>
            </details>
        @endif
    @elseif ($employee->status !== 'terminated')
        <details @if ($errors->has('last_day') || $errors->has('confirm')) open @endif style="margin-top:6px;">
            <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Resign</summary>
            <form method="POST" action="{{ route('my-work.resign') }}" style="display:grid;gap:10px;margin-top:10px;" data-confirm="Send your resignation to HR? Notice once given stands unless HR agrees otherwise.">
                @csrf
                <p class="dot-note" style="margin:0;">
                    This is your notice in writing. For your length of service the law's minimum notice is {{ $minimum / 7 }} {{ Str::plural('week', $minimum / 7) }} (BCEA s37), so your last day would be {{ today()->addDays($minimum)->format('j F Y') }} at the earliest, unless your contract asks for longer or HR agrees to less.
                </p>
                <div class="rg-form-row">
                    <div><label for="rs-last">Your last working day</label><input id="rs-last" name="last_day" type="date" class="dot-input" required min="{{ today()->toDateString() }}" value="{{ old('last_day', today()->addDays($minimum)->toDateString()) }}"></div>
                    <div>
                        <label for="rs-reason">Why, if you want to say</label>
                        <select id="rs-reason" name="reason" class="dot-input">
                            <option value="">I would rather not say</option>
                            @foreach (\App\Models\Resignation::REASONS as $key => $label)<option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div><label for="rs-message">A message for HR (optional)</label><textarea id="rs-message" name="message" class="dot-input" rows="3" maxlength="5000">{{ old('message') }}</textarea></div>
                <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="confirm" value="1" required> I am resigning from {{ $employee->team->name }}</label>
                <div><button type="submit" class="dot-btn dot-btn-primary">Send my resignation</button></div>
            </form>
        </details>
    @endif

    @if ($exitInterview && $exitInterview->isOpen())
        <a href="{{ route('my-work.exit-interview', $exitInterview) }}" class="rg-entry" style="text-decoration:none;color:inherit;margin-top:10px;">
            <div style="min-width:0;"><h4>Tell us about your time here</h4><div class="m">An optional exit interview. Only HR administrators read it.</div></div>
            <span class="dot-badge dot-badge-accent">Optional</span>
        </a>
    @endif

    @foreach ($certificates as $certificate)
        <a href="{{ route('my-work.certificate', $certificate) }}" class="rg-entry" style="text-decoration:none;color:inherit;margin-top:10px;">
            <div style="min-width:0;"><h4>Your certificate of service</h4><div class="m">Issued {{ $certificate->issued_at->format('j M Y') }}. Print it or save it as a PDF.</div></div>
        </a>
    @endforeach
</div>
