<x-app-layout>
<div class="page-pad" style="max-width:820px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('leaving.index') }}">Leaving</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">Exit interview: <em>{{ $interview->employee->fullName() }}</em></h1>
    <p class="rg-sub" style="margin:0 0 14px;">Offered {{ $interview->offered_at->format('j M Y') }}. Read by HR administrators only; never shown to managers.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($interview->completed_at)
        <div class="dot-card dot-card--pad" style="display:grid;gap:14px;">
            <dl class="rg-facts" style="margin:0;">
                <dt>{{ $interview->mode === 'meeting' ? 'Recorded' : 'Answered' }}</dt><dd>{{ $interview->completed_at->format('j M Y') }}{{ $interview->mode === 'meeting' ? ', from a conversation' : ', by them' }}</dd>
                <dt>Why they left</dt><dd>{{ collect($interview->reasons ?? [])->map(fn ($k) => \App\Models\ExitInterview::REASONS[$k] ?? $k)->join(', ') ?: 'Not said' }}</dd>
                <dt>Would come back</dt><dd>{{ \App\Models\ExitInterview::RETURN[$interview->would_return] ?? 'Not said' }}</dd>
                <dt>Anonymous patterns</dt><dd>{{ $interview->share_anonymously ? 'They agreed to be counted' : 'Not counted: they did not agree' }}</dd>
            </dl>
            @foreach (\App\Models\ExitInterview::QUESTIONS + ['notes' => 'Notes from the conversation'] as $key => $question)
                @if ($answer = $interview->answerList()[$key] ?? null)
                    <div><div class="dot-label">{{ $question }}</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $answer }}</p></div>
                @endif
            @endforeach
        </div>
    @elseif ($interview->declined_at)
        <div class="dot-card dot-card--pad"><p style="margin:0;">They declined on {{ $interview->declined_at->format('j M Y') }}. Nothing more is asked of them.</p></div>
    @else
        <div class="dot-card dot-card--pad">
            <p style="margin:0 0 12px;">Waiting for them to answer from My work. If you talked instead, record the conversation here, and ask them first whether it may be counted in anonymous patterns.</p>
            <form method="POST" action="{{ route('leaving.interview.record', $interview) }}" style="display:grid;gap:10px;">
                @csrf
                <fieldset style="border:0;margin:0;padding:0;">
                    <legend class="dot-label">Why they are leaving</legend>
                    <div class="rg-checks">
                        @foreach (\App\Models\ExitInterview::REASONS as $key => $label)
                            <label><input type="checkbox" name="reasons[]" value="{{ $key }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <div><label for="i-notes">What they said</label><textarea id="i-notes" name="notes" class="dot-input" rows="5" minlength="10" maxlength="10000" required></textarea></div>
                <fieldset style="border:0;margin:0;padding:0;">
                    <legend class="dot-label">Would they come back?</legend>
                    <div class="rg-checks">
                        @foreach (\App\Models\ExitInterview::RETURN as $key => $label)
                            <label><input type="radio" name="would_return" value="{{ $key }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="share_anonymously" value="1"> They agreed to be counted in anonymous patterns</label>
                <div><button type="submit" class="dot-btn dot-btn-primary">Record the conversation</button></div>
            </form>
        </div>
    @endif
</div>
</x-app-layout>
