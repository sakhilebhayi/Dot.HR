<x-app-layout>
<div class="page-pad" style="max-width:760px;">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('cases.index') }}">Cases</a></p>
    <h1 style="margin:0;">Open a case</h1>
    <p class="rg-sub" style="margin:4px 0 16px;">What you write here is encrypted and seen only by whoever handles the case. The person it is about reads nothing until you share it.</p>

    <nav class="rg-tabs" aria-label="Kind of case">
        @foreach (\App\Models\HrCase::TYPES as $key => $label)
            <a href="{{ route('cases.create', ['type' => $key]) }}" @if ($type === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('cases.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:12px;">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">

        <div class="rg-form-row">
            <div>
                <label for="c-employee">{{ ['grievance' => 'Who raised it', 'disciplinary' => 'Who it is about', 'incident' => 'Who was involved (if anyone)'][$type] }}</label>
                <select id="c-employee" name="employee_id" class="dot-input" @required($type !== 'incident')>
                    <option value="">{{ $type === 'incident' ? 'Nobody in particular' : 'Choose a person' }}</option>
                    @foreach ($employees as $person)
                        <option value="{{ $person->id }}" @selected((string) old('employee_id') === (string) $person->id)>{{ $person->fullName() }}{{ $person->status === 'terminated' ? ' (left)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="c-category">What kind</label>
                <select id="c-category" name="category" class="dot-input" required>
                    @foreach (\App\Models\HrCase::CATEGORIES[$type] as $key => $label)
                        <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($type === 'grievance')
            <div>
                <label for="c-respondent">Who the grievance is about (optional)</label>
                <select id="c-respondent" name="respondent_employee_id" class="dot-input">
                    <option value="">Not about one person</option>
                    @foreach ($employees as $person)
                        <option value="{{ $person->id }}" @selected((string) old('respondent_employee_id') === (string) $person->id)>{{ $person->fullName() }}</option>
                    @endforeach
                </select>
                <p class="dot-note" style="margin:4px 0 0;">They are never shown this grievance. If they need to answer it, share a notice with them in a case of their own.</p>
            </div>
        @endif

        <div class="rg-form-row">
            <div><label for="c-title">A short title</label><input id="c-title" name="title" class="dot-input" minlength="3" maxlength="160" required value="{{ old('title') }}" placeholder="{{ ['grievance' => 'Shift changes without notice', 'disciplinary' => 'Cash shortage on 14 October', 'incident' => 'Cut hand at the slicer'][$type] }}"></div>
            <div><label for="c-date">When it happened</label><input id="c-date" name="occurred_on" type="date" class="dot-input" max="{{ now()->toDateString() }}" value="{{ old('occurred_on') }}"></div>
        </div>

        <div>
            <label for="c-summary">{{ ['grievance' => 'What they raised', 'disciplinary' => 'What is alleged, and what you know so far', 'incident' => 'What happened, where, and what was done right away'][$type] }}</label>
            <textarea id="c-summary" name="summary" class="dot-input" rows="6" minlength="10" maxlength="10000" required>{{ old('summary') }}</textarea>
        </div>

        @if ($type === 'disciplinary')
            <p class="dot-note" style="margin:0;">Poor work performance is an incapacity matter: give guidance, training and a fair time to improve before any sanction. Record each step here.</p>
        @elseif ($type === 'incident')
            <p class="dot-note" style="margin:0;">For an injury at work, report it to the Compensation Fund within 7 days of the business being told. The case shows the date it is due.</p>
        @endif

        <div class="rg-form-row">
            <div>
                <label for="c-handler">Handled by</label>
                <select id="c-handler" name="handler_user_id" class="dot-input" @disabled(! $admin)>
                    @foreach ($handlers as $handler)
                        <option value="{{ $handler->id }}" @selected((int) old('handler_user_id', auth()->id()) === $handler->id)>{{ $handler->name }}{{ $handler->id === auth()->id() ? ' (you)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <label class="dot-note" style="display:flex;gap:8px;align-items:flex-start;margin:22px 0 0;">
                <input type="checkbox" name="restricted" value="1" @checked(old('restricted')) style="margin-top:3px;">
                <span>Restricted: only the handler and the business owner can open it. Use this when the case involves someone in HR.</span>
            </label>
        </div>

        <div><button type="submit" class="dot-btn dot-btn-primary">Open the case</button></div>
    </form>
</div>
</x-app-layout>
