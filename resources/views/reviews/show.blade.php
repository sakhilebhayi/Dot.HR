<x-app-layout>
<div class="page-pad">
    @php
        $r = $review;
        $person = $r->employee;
        $open = $r->cycle->status === 'open';
    @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ $isSubject ? route('my-work.index') : route('reviews.index') }}">{{ $isSubject ? 'My work' : 'Reviews' }}</a></p>
    <h1 class="rg-greet">
        @if ($isSubject)
            Your review — <em>{{ $r->cycle->name }}</em>
        @else
            {{ $person->fullName() }} — <em>{{ $r->cycle->name }}</em>
        @endif
    </h1>
    <p class="rg-sub">{{ $person->position?->title ?? 'No position recorded' }} · reviewed by {{ $isReviewer ? 'you' : $r->reviewer->name }} · {{ $isSubject
        ? ['self' => 'Waiting for your reflection', 'reviewer' => 'With your reviewer', 'shared' => 'Ready for you to read', 'acknowledged' => 'Complete'][$r->status]
        : \App\Models\Review::STATUSES[$r->status] }}</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="rg-grid">
        <div class="s8" style="display:grid;gap:14px;align-content:start;">
            {{-- 1. The person's own reflection --}}
            <div class="dot-card dot-card--pad">
                <div class="dot-label">{{ $isSubject ? 'Your reflection' : 'Their reflection' }}</div>
                @if ($r->status === 'self')
                    @if ($isSubject && $open)
                        <form method="POST" action="{{ route('reviews.reflect', $r) }}" style="display:grid;gap:10px;margin-top:6px;">
                            @csrf
                            <label for="self_assessment" class="dot-note" style="font-weight:400;">What went well, what was hard, what you want to grow into, and what would help. Only you, {{ $r->reviewer->name }} and HR administrators read this.</label>
                            <textarea id="self_assessment" name="self_assessment" class="dot-input" rows="9" maxlength="10000" required>{{ old('self_assessment') }}</textarea>
                            <div><button type="submit" class="dot-btn dot-btn-primary">Send to {{ $r->reviewer->name }}</button></div>
                        </form>
                    @else
                        <p class="dot-note" style="margin:6px 0 0;">Not written yet.</p>
                        @if (($isReviewer || $isAdmin) && $open)
                            <form method="POST" action="{{ route('reviews.skip', $r) }}" style="margin-top:10px;" data-confirm="Go ahead without their reflection?">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Go ahead without it</button></form>
                        @endif
                    @endif
                @elseif ($r->self_skipped)
                    <p class="dot-note" style="margin:6px 0 0;">Not written — the review went ahead without it.</p>
                @else
                    <p style="margin:8px 0 0;white-space:pre-line;">{{ $r->self_assessment }}</p>
                    <p class="dot-note" style="margin:8px 0 0;">Written {{ $r->self_done_at?->format('j M Y') }}</p>
                @endif
            </div>

            {{-- 2. The reviewer's feedback --}}
            <div class="dot-card dot-card--pad">
                <div class="dot-label">{{ $isReviewer ? 'Your feedback' : 'Feedback from '.$r->reviewer->name }}</div>
                @if (in_array($r->status, ['shared', 'acknowledged'], true))
                    <p style="margin:8px 0 0;white-space:pre-line;">{{ $r->feedback }}</p>
                    <p class="dot-note" style="margin:8px 0 0;">Shared {{ $r->shared_at?->format('j M Y') }}</p>
                @elseif ($r->status === 'reviewer' && $isReviewer && $open)
                    <form method="POST" action="{{ route('reviews.share', $r) }}" style="display:grid;gap:10px;margin-top:6px;">
                        @csrf
                        <label for="feedback" class="dot-note" style="font-weight:400;">Specific and kind: what you saw, what it achieved, what to do more or differently, and the support you will give. {{ $person->first_name }} reads exactly this.</label>
                        <textarea id="feedback" name="feedback" class="dot-input" rows="10" maxlength="10000" required>{{ old('feedback') }}</textarea>
                        <div><button type="submit" class="dot-btn dot-btn-primary" data-confirm="Share it now? It cannot be edited afterwards.">Share with {{ $person->first_name }}</button></div>
                    </form>
                @else
                    <p class="dot-note" style="margin:6px 0 0;">{{ $r->status === 'self' ? 'Written after the reflection.' : 'Being written.' }}</p>
                @endif
            </div>

            {{-- 3. The person's response --}}
            @if (in_array($r->status, ['shared', 'acknowledged'], true))
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">{{ $isSubject ? 'Your response' : 'Their response' }}</div>
                    @if ($r->status === 'acknowledged')
                        @if ($r->response)
                            <p style="margin:8px 0 0;white-space:pre-line;">{{ $r->response }}</p>
                        @else
                            <p class="dot-note" style="margin:6px 0 0;">Read without comment.</p>
                        @endif
                        <p class="dot-note" style="margin:8px 0 0;">Read {{ $r->acknowledged_at?->format('j M Y') }}</p>
                    @elseif ($isSubject && $open)
                        <form method="POST" action="{{ route('reviews.acknowledge', $r) }}" style="display:grid;gap:10px;margin-top:6px;">
                            @csrf
                            <label for="response" class="dot-note" style="font-weight:400;">Anything you want on the record — agreement, a different view, or what you need. Optional.</label>
                            <textarea id="response" name="response" class="dot-input" rows="4" maxlength="5000">{{ old('response') }}</textarea>
                            <div><button type="submit" class="dot-btn dot-btn-primary">I have read it</button></div>
                        </form>
                    @else
                        <p class="dot-note" style="margin:6px 0 0;">Waiting for {{ $person->first_name }} to read it.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="s4" style="display:grid;gap:14px;align-content:start;">
            <div class="dot-card dot-card--pad">
                <div class="dot-label">Goals</div>
                @forelse ($goals as $goal)
                    <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                        <div style="min-width:0;">
                            <h4 style="font-size:14px;">{{ $goal->title }}</h4>
                            <div class="m">{{ \App\Models\Goal::KINDS[$goal->kind] }}@if ($goal->due_on) · by {{ $goal->due_on->format('j M Y') }}@endif</div>
                            <form method="POST" action="{{ route('goals.update', $goal) }}" style="margin:6px 0 0;">
                                @csrf
                                @method('PUT')
                                <label for="goal-{{ $goal->id }}" style="position:absolute;left:-9999px;">Progress on {{ $goal->title }}</label>
                                <select id="goal-{{ $goal->id }}" name="status" class="dot-input" style="width:auto;min-height:30px;padding:3px 8px;" data-autosubmit>
                                    @foreach (\App\Models\Goal::STATUSES as $value => $label)<option value="{{ $value }}" @selected($goal->status === $value)>{{ $label }}</option>@endforeach
                                </select>
                                <noscript><button type="submit" class="dot-btn">Save</button></noscript>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="dot-note" style="margin:6px 0 0;">No goals yet. Agree one or two you can both recognise when they are done.</p>
                @endforelse
                <form method="POST" action="{{ route('goals.store', $person) }}" style="display:grid;gap:8px;margin-top:12px;">
                    @csrf
                    <div><label for="g-title">New goal</label><input id="g-title" name="title" class="dot-input" maxlength="160" required></div>
                    <div class="rg-form-row">
                        <div><label for="g-kind">For</label><select id="g-kind" name="kind" class="dot-input">@foreach (\App\Models\Goal::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        <div><label for="g-due">By</label><input id="g-due" name="due_on" type="date" class="dot-input"></div>
                    </div>
                    <div><button type="submit" class="dot-btn">Add the goal</button></div>
                </form>
            </div>

            @if ($isAdmin && $r->status !== 'acknowledged')
                <div class="dot-card dot-card--pad">
                    <div class="dot-label">Reviewer</div>
                    <form method="POST" action="{{ route('reviews.reassign', $r) }}" style="display:flex;gap:6px;align-items:end;margin-top:6px;">
                        @csrf
                        @method('PUT')
                        <div style="flex:1;">
                            <label for="reviewer_id" style="position:absolute;left:-9999px;">Reviewer</label>
                            <select id="reviewer_id" name="reviewer_id" class="dot-input">
                                @foreach ($members as $member)@if ($member->id !== $person->user_id)<option value="{{ $member->id }}" @selected($member->id === $r->reviewer_id)>{{ $member->name }}</option>@endif @endforeach
                            </select>
                        </div>
                        <button type="submit" class="dot-btn">Change</button>
                    </form>
                </div>
            @endif

            <p class="dot-note" style="margin:0;">Only {{ $isSubject ? 'you' : $person->first_name }}, the reviewer and HR administrators can open this review. The text is encrypted where it is stored. There is no score.</p>
        </div>
    </div>
</div>
</x-app-layout>
