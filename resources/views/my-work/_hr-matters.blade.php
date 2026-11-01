{{-- Grievances and HR cases (brief §15), the person's side: what concerns them and a
     confidential way to raise a grievance. Internal HR entries never reach this list. --}}
<div class="dot-card dot-card--pad" id="hr-matters">
    <div class="dot-label">Grievances and HR matters</div>
    @foreach ($myPlans as $plan)
        <a href="{{ route('my-work.plans.show', $plan) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
            <div style="min-width:0;">
                <h4>Improvement plan: {{ $plan->title }}</h4>
                <div class="m">{{ $plan->reference() }}, {{ $plan->starts_on->format('j M') }} to {{ $plan->ends_on->format('j M Y') }}</div>
            </div>
            <span class="dot-badge {{ $plan->status === 'active' ? ($plan->acknowledged_at ? 'dot-badge-accent' : 'dot-badge-warn') : 'dot-badge-muted' }}">{{ $plan->status === 'active' && ! $plan->acknowledged_at ? 'Please read' : $plan->statusLabel() }}</span>
        </a>
    @endforeach
    @foreach ($myCases as $case)
        <a href="{{ route('my-work.cases.show', $case) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
            <div style="min-width:0;">
                <h4>{{ $case->type === 'grievance' ? $case->title : $case->typeLabel() }}</h4>
                <div class="m">{{ $case->reference() }}, {{ $case->type === 'grievance' ? 'lodged' : 'opened' }} {{ $case->created_at->format('j M Y') }}</div>
            </div>
            <span class="dot-badge {{ $case->isOpen() ? 'dot-badge-accent' : 'dot-badge-muted' }}">{{ $case->statusLabel() }}</span>
        </a>
    @endforeach

    <details @if ($errors->has('summary')) open @endif style="margin-top:{{ $myCases->isEmpty() && $myPlans->isEmpty() ? '6px' : '12px' }};">
        <summary class="dot-btn dot-btn-ghost" style="list-style:none;">Raise a grievance</summary>
        <form method="POST" action="{{ route('my-work.cases.lodge') }}" style="display:grid;gap:10px;margin-top:10px;">
            @csrf
            <p class="dot-note" style="margin:0;">A grievance is a formal complaint about how you are treated at work. HR administrators read it, never your manager, and never the person it is about.</p>
            <div class="rg-form-row">
                <div>
                    <label for="gr-category">About</label>
                    <select id="gr-category" name="category" class="dot-input">
                        @foreach (\App\Models\HrCase::CATEGORIES['grievance'] as $key => $label)
                            <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label for="gr-date">When it happened (if one day)</label><input id="gr-date" name="occurred_on" type="date" class="dot-input" max="{{ now()->toDateString() }}" value="{{ old('occurred_on') }}"></div>
            </div>
            <div><label for="gr-title">In a few words</label><input id="gr-title" name="title" class="dot-input" minlength="3" maxlength="160" required value="{{ old('title') }}"></div>
            <div><label for="gr-summary">What happened, and what you would like done</label><textarea id="gr-summary" name="summary" class="dot-input" rows="5" minlength="10" maxlength="10000" required>{{ old('summary') }}</textarea></div>
            <div><label for="gr-about">Who it is about (optional)</label><input id="gr-about" name="about" class="dot-input" maxlength="160" value="{{ old('about') }}"></div>
            <label class="dot-note" style="display:flex;gap:8px;align-items:flex-start;margin:0;">
                <input type="checkbox" name="owner_only" value="1" @checked(old('owner_only')) style="margin-top:3px;">
                <span>It is about someone in HR: send it only to the business owner.</span>
            </label>
            <div><button type="submit" class="dot-btn dot-btn-primary">Lodge the grievance</button></div>
        </form>
    </details>
</div>
