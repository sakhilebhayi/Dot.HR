<x-app-layout>
<div class="page-pad">
    @php $pending = $asked->filter(fn ($r) => $r->isPending() && $r->round->status === 'open'); @endphp
    <h1 class="rg-greet">
        @if ($pending->isNotEmpty())
            <em>{{ $pending->count() }} {{ Str::plural('colleague', $pending->count()) }}</em> would value your feedback.
        @else
            Feedback, in words.
        @endif
    </h1>
    <p class="rg-sub">Colleagues write what someone should keep doing and could do differently. Nobody is scored. The person reads the words without names, and only once at least {{ \App\Models\FeedbackRound::ANONYMITY_FLOOR }} people have answered; HR administrators can see who wrote what, so misuse can be dealt with.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Asked of you</div>
                @forelse ($asked as $response)
                    <div class="rg-entry rg-entry--tools">
                        <div style="min-width:0;">
                            <h4>About {{ $response->round->employee->fullName() }}</h4>
                            <div class="m">By {{ $response->round->due_on->format('j M Y') }}{{ $response->round->focus ? '. '.$response->round->focus : '' }}</div>
                        </div>
                        @if ($response->isPending() && $response->round->status === 'open')
                            <a href="{{ route('feedback.respond', $response) }}" class="dot-btn dot-btn-primary">Answer</a>
                        @else
                            <span class="dot-badge dot-badge-muted">{{ $response->answered_at ? 'Answered' : ($response->declined_at ? 'Declined' : 'Closed') }}</span>
                        @endif
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">Nobody has asked you yet.</p>
                @endforelse
            </div>

            @if ($me)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">About you</div>
                    @forelse ($mine as $round)
                        <a href="{{ route('feedback.rounds.show', $round) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4>{{ $round->reference() }}, asked {{ $round->created_at->format('j M Y') }}</h4><div class="m">{{ $round->answeredCount() }} of {{ $round->responses->count() }} answered</div></div>
                            <span class="dot-badge {{ $round->status === 'shared' ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ \App\Models\FeedbackRound::STATUSES[$round->status] }}</span>
                        </a>
                    @empty
                        <p class="dot-note" style="margin:6px 0 0;">You have not asked for feedback yet.</p>
                    @endforelse
                </div>
            @endif

            @if ($admin)
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Every round</div>
                    @forelse ($all as $round)
                        <a href="{{ route('feedback.rounds.show', $round) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                            <div style="min-width:0;"><h4>{{ $round->employee->fullName() }}</h4><div class="m">{{ $round->reference() }}, {{ $round->answeredCount() }} of {{ $round->responses->count() }} answered, due {{ $round->due_on->format('j M') }}</div></div>
                            <span class="dot-badge {{ $round->status === 'shared' ? 'dot-badge-ok' : ($round->status === 'open' ? 'dot-badge-accent' : 'dot-badge-muted') }}">{{ \App\Models\FeedbackRound::STATUSES[$round->status] }}</span>
                        </a>
                    @empty
                        <p class="dot-note" style="margin:6px 0 0;">No rounds yet.</p>
                    @endforelse
                </div>
            @endif
        </div>

        <div class="s4">
            @if ($me || $admin)
                <form method="POST" action="{{ route('feedback.rounds.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;">
                    @csrf
                    <div class="dot-label" style="margin:0;">{{ $admin ? 'Ask colleagues for feedback' : 'Ask colleagues about you' }}</div>
                    @if ($admin)
                        <div>
                            <label for="fb-about">About</label>
                            <select id="fb-about" name="employee_id" class="dot-input">
                                @if ($me)<option value="">Me</option>@endif
                                @foreach ($employees as $e)@if ($e->id !== $me?->id)<option value="{{ $e->id }}">{{ $e->fullName() }}</option>@endif @endforeach
                            </select>
                        </div>
                    @endif
                    <fieldset style="border:0;margin:0;padding:0;">
                        <legend class="dot-label">Who to ask ({{ \App\Models\FeedbackRound::ANONYMITY_FLOOR }} to {{ \App\Models\FeedbackRound::MAX_GIVERS }})</legend>
                        <div style="display:grid;gap:4px;max-height:220px;overflow:auto;">
                            @foreach ($members as $member)
                                <label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="givers[]" value="{{ $member->id }}"> {{ $member->name }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div><label for="fb-focus">Anything to focus on (optional)</label><input id="fb-focus" name="focus" class="dot-input" maxlength="500" placeholder="How I run the Monday stock meeting"></div>
                    <div><label for="fb-due">Answers by</label><input id="fb-due" name="due_on" type="date" class="dot-input" required min="{{ now()->toDateString() }}" value="{{ now()->addWeeks(2)->toDateString() }}"></div>
                    <div><button type="submit" class="dot-btn dot-btn-primary">Ask them</button></div>
                </form>
            @endif
        </div>
    </div>
</div>
</x-app-layout>
