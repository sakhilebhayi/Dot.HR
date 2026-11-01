<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('my-work.index') }}#leaving">My work</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">Before you go, <em>would you tell us</em> about your time here?</h1>
    <p class="rg-sub" style="margin:0 0 16px;">It is optional, and you can skip any question. Only HR administrators read your answers; your manager does not.</p>

    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if (! $interview->isOpen())
        <div class="dot-card dot-card--pad"><p style="margin:0;">{{ $interview->completed_at ? 'You answered on '.$interview->completed_at->format('j M Y').'. Thank you.' : 'You chose not to answer. Nothing more will be asked.' }}</p></div>
    @else
        <form method="POST" action="{{ route('my-work.exit-interview.answer', $interview) }}" class="dot-card dot-card--pad" style="display:grid;gap:14px;">
            @csrf
            <fieldset style="border:0;margin:0;padding:0;">
                <legend class="dot-label">What made you decide to leave? Choose any.</legend>
                <div class="rg-checks">
                    @foreach (\App\Models\ExitInterview::REASONS as $key => $label)
                        <label><input type="checkbox" name="reasons[]" value="{{ $key }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            @foreach (\App\Models\ExitInterview::QUESTIONS as $key => $question)
                <div><label for="q-{{ $key }}">{{ $question }}</label><textarea id="q-{{ $key }}" name="answers[{{ $key }}]" class="dot-input" rows="3" maxlength="5000"></textarea></div>
            @endforeach
            <fieldset style="border:0;margin:0;padding:0;">
                <legend class="dot-label">Would you work here again?</legend>
                <div class="rg-checks">
                    @foreach (\App\Models\ExitInterview::RETURN as $key => $label)
                        <label><input type="radio" name="would_return" value="{{ $key }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <label class="dot-note" style="display:flex;gap:8px;align-items:flex-start;margin:0;">
                <input type="checkbox" name="share_anonymously" value="1" style="margin-top:3px;">
                <span>Count my reasons in anonymous patterns. HR only sees those once at least {{ \App\Models\ExitInterview::ANONYMITY_FLOOR }} people have agreed, as counts, with no names or words.</span>
            </label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <button type="submit" class="dot-btn dot-btn-primary">Send my answers</button>
            </div>
        </form>
        <form method="POST" action="{{ route('my-work.exit-interview.decline', $interview) }}" style="margin-top:10px;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">No thank you</button></form>
    @endif
</div>
</x-app-layout>
