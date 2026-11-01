<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('feedback.index') }}">Feedback</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">Feedback for <em>{{ $response->round->employee->first_name }}</em></h1>
    <p class="rg-sub" style="margin:0 0 14px;">
        {{ $response->round->employee->first_name }} reads your words without your name, together with at least {{ \App\Models\FeedbackRound::ANONYMITY_FLOOR - 1 }} others. HR administrators can see who wrote what. Be specific and kind: describe what you saw, not who they are.
        @if ($response->round->focus) They asked about: {{ $response->round->focus }} @endif
    </p>

    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('feedback.answer', $response) }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
        @csrf
        @foreach (\App\Models\FeedbackResponse::QUESTIONS as $key => $question)
            <div><label for="fr-{{ $key }}">{{ $question }}</label><textarea id="fr-{{ $key }}" name="{{ $key }}" class="dot-input" rows="3" maxlength="3000">{{ old($key) }}</textarea></div>
        @endforeach
        <div><button type="submit" class="dot-btn dot-btn-primary">Send</button></div>
    </form>
    <form method="POST" action="{{ route('feedback.decline', $response) }}" style="margin-top:10px;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">No thanks</button></form>
</div>
</x-app-layout>
