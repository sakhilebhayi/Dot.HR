<x-app-layout>
<div class="page-pad" style="max-width:820px;">
    @php $next = $plan->nextCheckIn(); @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('my-work.index') }}#hr-matters">My work</a></p>
    <h1 class="rg-greet" style="margin-bottom:4px;">Your improvement plan: <em>{{ $plan->title }}</em></h1>
    <p class="rg-sub" style="margin:0 0 14px;">
        {{ $plan->reference() }}, {{ $plan->starts_on->format('j M') }} to {{ $plan->ends_on->format('j M Y') }}{{ $plan->manager ? ', with '.$plan->manager->name.' supporting you' : '' }}.
        Only you, HR{{ $plan->manager ? ' and '.$plan->manager->name : '' }} can see it.
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif
    @if ($plan->status === 'completed')
        <div class="dot-flash {{ $plan->outcome === 'met' ? 'dot-flash-ok' : 'dot-flash-err' }}" role="status">{{ \App\Models\ImprovementPlan::OUTCOMES[$plan->outcome] }}. {{ $plan->outcome_notes }}</div>
    @elseif ($plan->status === 'cancelled')
        <div class="dot-flash" role="status">This plan was stopped. {{ $plan->outcome_notes }}</div>
    @endif

    <div class="dot-card dot-card--pad" style="display:grid;gap:14px;">
        <div><div class="dot-label">What is below the standard</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->concern }}</p></div>
        <div><div class="dot-label">What meeting the standard looks like</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->standard }}</p></div>
        <div><div class="dot-label">How we will help you</div><p style="margin:4px 0 0;white-space:pre-line;max-width:68ch;">{{ $plan->support }}</p></div>
    </div>

    <div class="dot-card dot-card--pad" style="margin-top:14px;">
        <div class="dot-label">What to aim for</div>
        @foreach ($plan->objectives as $objective)
            <div class="rg-entry">
                <div style="min-width:0;">
                    <h4>{{ $objective->title }}</h4>
                    <div class="m">Measure: {{ $objective->measure }}{{ $objective->due_on ? ', by '.$objective->due_on->format('j M Y') : '' }}</div>
                </div>
                <span class="dot-badge {{ ['open' => 'dot-badge-muted', 'met' => 'dot-badge-ok', 'not_met' => 'dot-badge-warn'][$objective->status] }}">{{ \App\Models\ImprovementPlanObjective::STATUSES[$objective->status] }}</span>
            </div>
        @endforeach
    </div>

    @if ($plan->status === 'active')
        <div class="dot-card dot-card--pad" style="margin-top:14px;">
            @if ($plan->acknowledged_at)
                <div class="dot-label">You read this plan on {{ $plan->acknowledged_at->format('j M Y') }}</div>
                @if ($plan->employee_response)<p style="margin:4px 0 0;white-space:pre-line;">{{ $plan->employee_response }}</p>@endif
            @else
                <div class="dot-label">Confirm you have read it</div>
                <form method="POST" action="{{ route('my-work.plans.acknowledge', $plan) }}" style="display:grid;gap:8px;margin-top:6px;">
                    @csrf
                    <label for="ack-response">Your response, if you want to add one</label>
                    <textarea id="ack-response" name="response" class="dot-input" rows="3" maxlength="10000" placeholder="What you think of the plan, or support you would find useful"></textarea>
                    <label class="dot-note" style="display:inline-flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="confirm" value="1" required> I have read the plan. This does not mean I agree with it.</label>
                    <div><button type="submit" class="dot-btn dot-btn-primary">Confirm</button></div>
                </form>
            @endif
        </div>
    @endif

    <div class="dot-card dot-card--pad" style="margin-top:14px;">
        <div class="dot-label">Check-ins{{ $next ? ', next by '.$next->format('j M Y') : '' }}</div>
        @if ($plan->checkIns->isEmpty())
            <p class="dot-note" style="margin:6px 0 0;">None yet. You go through each one together, and the notes appear here.</p>
        @else
        <ol class="rg-timeline" style="margin-top:6px;">
            @foreach ($plan->checkIns as $checkIn)
                <li>
                    <time datetime="{{ $checkIn->held_on->toDateString() }}">{{ $checkIn->held_on->format('j M Y') }}</time>
                    <div>
                        <p class="body" style="margin-top:0;">{{ $checkIn->notes }}</p>
                        @if ($checkIn->employee_comment)
                            <div class="from-them" style="margin-top:8px;"><div class="head"><strong>You commented</strong></div><p class="body" style="margin-top:4px;">{{ $checkIn->employee_comment }}</p></div>
                        @elseif ($plan->status === 'active')
                            <form method="POST" action="{{ route('my-work.plans.comment', $checkIn) }}" style="display:grid;gap:6px;margin-top:8px;">
                                @csrf
                                <label for="cm-{{ $checkIn->id }}" style="position:absolute;left:-9999px;">Your comment</label>
                                <textarea id="cm-{{ $checkIn->id }}" name="comment" class="dot-input" rows="2" maxlength="5000" required placeholder="Add your side of this check-in"></textarea>
                                <div><button type="submit" class="dot-btn">Add your comment</button></div>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        @endif
    </div>
</div>
</x-app-layout>
