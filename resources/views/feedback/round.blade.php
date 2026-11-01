<x-app-layout>
<div class="page-pad" style="max-width:860px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('feedback.index') }}">Feedback</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">{{ $subject ? 'What your colleagues said' : 'Feedback about '.$round->employee->fullName() }}</h1>
    <p class="rg-sub" style="margin:0 0 14px;">
        {{ $round->reference() }}, {{ $round->answeredCount() }} of {{ $round->responses->count() }} answered, due {{ $round->due_on->format('j M Y') }}. {{ \App\Models\FeedbackRound::STATUSES[$round->status] }}.
        @if ($round->focus) Focus: {{ $round->focus }} @endif
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @if ($canShare)
        <div class="dot-card dot-card--pad" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between;">
            <p style="margin:0;">{{ $round->answeredCount() >= \App\Models\FeedbackRound::ANONYMITY_FLOOR ? 'Enough people have answered to share the words without anyone being singled out.' : 'Sharing opens once '.\App\Models\FeedbackRound::ANONYMITY_FLOOR.' people have answered.' }}</p>
            <div style="display:flex;gap:8px;">
                <form method="POST" action="{{ route('feedback.rounds.share', $round) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-primary" @disabled($round->answeredCount() < \App\Models\FeedbackRound::ANONYMITY_FLOOR)>Share{{ $subject ? '' : ' with '.$round->employee->first_name }}, without names</button></form>
                @if ($admin)<form method="POST" action="{{ route('feedback.rounds.close', $round) }}" style="margin:0;">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Close without sharing</button></form>@endif
            </div>
        </div>
    @endif

    @if ($admin)
        <div class="dot-card dot-card--pad">
            <div class="dot-label">Answers, with names (HR only)</div>
            @foreach ($round->responses as $response)
                <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                    <div style="min-width:0;">
                        <h4>{{ $response->giver->name }} <span class="dot-note" style="font-weight:400;">{{ $response->answered_at ? 'answered '.$response->answered_at->format('j M') : ($response->declined_at ? 'declined' : 'not yet') }}</span></h4>
                        @foreach (\App\Models\FeedbackResponse::QUESTIONS as $key => $question)
                            @if ($response->{$key})<p style="margin:6px 0 0;white-space:pre-line;"><span class="dot-note">{{ $question }}</span><br>{{ $response->{$key} }}</p>@endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($answers === [])
        <div class="dot-card dot-card--pad"><p class="dot-note" style="margin:0;">The answers appear here once the round is shared. {{ $round->answeredCount() }} {{ $round->answeredCount() === 1 ? 'person has' : 'people have' }} answered so far.</p></div>
    @else
        @foreach (\App\Models\FeedbackResponse::QUESTIONS as $key => $question)
            @php $said = collect($answers)->pluck($key)->filter(); @endphp
            @if ($said->isNotEmpty())
                <div class="dot-card dot-card--pad" style="margin-bottom:12px;">
                    <div class="dot-label">{{ $question }}</div>
                    @foreach ($said as $words)
                        <p style="margin:8px 0 0;white-space:pre-line;max-width:68ch;padding-top:8px;{{ $loop->first ? '' : 'border-top:1px solid var(--rg-rule);' }}">{{ $words }}</p>
                    @endforeach
                </div>
            @endif
        @endforeach
    @endif
</div>
</x-app-layout>
