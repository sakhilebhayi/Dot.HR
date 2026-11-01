<x-app-layout>
<div class="page-pad" style="max-width:820px;">
    @php
        $editing = $plan !== null;
        $rows = old('objectives', $editing ? $plan->objectives->map(fn ($o) => ['title' => $o->title, 'measure' => $o->measure, 'due_on' => $o->due_on?->toDateString()])->all() : []);
        $rows = array_pad(array_values($rows), min(8, max(3, count($rows) + 1)), ['title' => '', 'measure' => '', 'due_on' => '']);
        $start = old('starts_on', $plan?->starts_on?->toDateString() ?? now()->toDateString());
    @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ $editing ? route('plans.show', $plan) : route('plans.index') }}">{{ $editing ? $plan->reference() : 'Improvement plans' }}</a></p>
    <h1 style="margin:0;">{{ $editing ? 'Edit the draft' : 'Draft an improvement plan' }}</h1>
    <p class="rg-sub" style="margin:4px 0 16px;">Say plainly what is below standard, what good looks like, and how you will help. The person sees nothing until you share it.</p>

    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ $editing ? route('plans.update', $plan) : route('plans.store') }}" style="display:grid;gap:14px;">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="dot-card dot-card--pad" style="display:grid;gap:12px;">
            <div class="rg-form-row">
                <div>
                    <label for="p-employee">For</label>
                    @if ($editing)
                        <p style="margin:6px 0 0;">{{ $plan->employee->fullName() }}</p>
                    @else
                        <select id="p-employee" name="employee_id" class="dot-input" required>
                            <option value="">Choose a person</option>
                            @foreach ($employees as $person)
                                <option value="{{ $person->id }}" @selected((int) old('employee_id', $employee?->id) === $person->id)>{{ $person->fullName() }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div><label for="p-title">A short title</label><input id="p-title" name="title" class="dot-input" minlength="3" maxlength="160" required value="{{ old('title', $plan?->title) }}" placeholder="Accuracy of stock counts"></div>
            </div>
            <div><label for="p-concern">What is below standard, with examples</label><textarea id="p-concern" name="concern" class="dot-input" rows="4" minlength="10" maxlength="10000" required placeholder="Three of the last five month-end counts were out by more than 2%.">{{ old('concern', $plan?->concern) }}</textarea></div>
            <div><label for="p-standard">What meeting the standard looks like</label><textarea id="p-standard" name="standard" class="dot-input" rows="3" minlength="10" maxlength="10000" required placeholder="Counts within 0.5% of the system, signed off on the day.">{{ old('standard', $plan?->standard) }}</textarea></div>
            <div><label for="p-support">How you will help</label><textarea id="p-support" name="support" class="dot-input" rows="3" minlength="10" maxlength="10000" required placeholder="Two counts done together, a refresher on the scanner, a weekly half hour with the stores manager.">{{ old('support', $plan?->support) }}</textarea>
                <p class="dot-note" style="margin:4px 0 0;">Guidance, training or coaching is part of a fair plan (Schedule 8, item 9), not an extra.</p>
            </div>
            @unless ($editing)
                <div><label for="p-training">Book training as part of it (optional)</label><input id="p-training" name="training" class="dot-input" maxlength="160" value="{{ old('training') }}" placeholder="Scanner and stock system refresher"></div>
            @endunless
        </div>

        <div class="dot-card dot-card--pad" style="display:grid;gap:10px;">
            <div class="dot-label" style="margin:0;">Objectives, each with how anyone can tell it is met</div>
            @foreach ($rows as $i => $row)
                <div class="rg-form-row rg-form-row--objective">
                    <div><label for="o-{{ $i }}-title">Objective {{ $i + 1 }}</label><input id="o-{{ $i }}-title" name="objectives[{{ $i }}][title]" class="dot-input" maxlength="200" value="{{ $row['title'] ?? '' }}" @required($i === 0)></div>
                    <div><label for="o-{{ $i }}-measure">Measured by</label><input id="o-{{ $i }}-measure" name="objectives[{{ $i }}][measure]" class="dot-input" maxlength="255" value="{{ $row['measure'] ?? '' }}" @required($i === 0)></div>
                    <div><label for="o-{{ $i }}-due">By</label><input id="o-{{ $i }}-due" name="objectives[{{ $i }}][due_on]" type="date" class="dot-input" value="{{ $row['due_on'] ?? '' }}"></div>
                </div>
            @endforeach
            <p class="dot-note" style="margin:0;">Leave a row empty to skip it. Up to eight; save the draft to get another empty row.</p>
        </div>

        <div class="dot-card dot-card--pad" style="display:grid;gap:12px;">
            <div class="rg-form-row rg-form-row--3">
                <div><label for="p-start">Starts</label><input id="p-start" name="starts_on" type="date" class="dot-input" required value="{{ $start }}"></div>
                <div><label for="p-end">Ends</label><input id="p-end" name="ends_on" type="date" class="dot-input" required value="{{ old('ends_on', $plan?->ends_on?->toDateString() ?? now()->addWeeks(8)->toDateString()) }}"></div>
                <div><label for="p-every">Check in every (days)</label><input id="p-every" name="check_in_days" type="number" min="7" max="60" class="dot-input" required value="{{ old('check_in_days', $plan?->check_in_days ?? 14) }}"></div>
            </div>
            <p class="dot-note" style="margin:0;">A plan gives a reasonable time to improve. For most work that is weeks, not days; under {{ \App\Models\ImprovementPlan::SHORT_PERIOD_DAYS }} days you are asked to check.</p>
            <div class="rg-form-row">
                <div>
                    <label for="p-manager">Supporting manager</label>
                    <select id="p-manager" name="manager_user_id" class="dot-input" @disabled(! $admin)>
                        <option value="">HR runs it</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}" @selected((int) old('manager_user_id', $plan?->manager_user_id) === $manager->id)>{{ $manager->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($cases->isNotEmpty())
                    <div>
                        <label for="p-case">Part of an incapacity case (optional)</label>
                        <select id="p-case" name="hr_case_id" class="dot-input">
                            <option value="">Not linked</option>
                            @foreach ($cases as $case)
                                <option value="{{ $case->id }}" @selected((int) old('hr_case_id', $plan?->hr_case_id) === $case->id)>{{ $case->reference() }} {{ $case->employee?->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </div>

        <div><button type="submit" class="dot-btn dot-btn-primary">{{ $editing ? 'Save the draft' : 'Save as a draft' }}</button></div>
    </form>
</div>
</x-app-layout>
