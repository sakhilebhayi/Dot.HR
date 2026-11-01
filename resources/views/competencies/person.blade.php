<x-app-layout>
<div class="page-pad" style="max-width:900px;">
    @php
        $toGrow = collect($rows)->where('toGrow', true)->count();
    @endphp
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ $admin ? route('employees.show', $employee) : route('my-work.index') }}">{{ $admin ? $employee->fullName() : 'My work' }}</a></p>
    <h1 class="rg-greet">
        {{ $admin ? $employee->first_name.'’s' : 'Your' }} competencies{{ $toGrow ? ',' : '.' }}
        @if ($toGrow) <em>{{ $toGrow }} {{ Str::plural('place', $toGrow) }} to grow</em>. @endif
    </h1>
    <p class="rg-sub">
        {{ $employee->position ? 'What the '.$employee->position->title.' position expects, ' : '' }}where {{ $admin ? 'they place themselves' : 'you place yourself' }}, and the level agreed with HR. Levels describe the work, never the person; only {{ $admin ? $employee->first_name.' and HR' : 'you and HR' }} see this.
    </p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    @forelse ($rows as $row)
        @php $c = $row['competency']; $a = $row['assessment']; @endphp
        <div class="dot-card dot-card--pad" style="margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;">
                <div style="min-width:0;"><h4 style="margin:0;font-size:15px;font-weight:500;">{{ $c->name }}</h4>@if ($c->area)<div class="dot-note">{{ $c->area }}</div>@endif</div>
                @if ($row['toGrow'])
                    <span class="dot-badge dot-badge-accent">Room to grow</span>
                @elseif ($row['expected'] && $row['agreed'])
                    <span class="dot-badge dot-badge-ok">Meets what the position expects</span>
                @endif
            </div>
            <dl class="rg-facts">
                <dt>The position expects</dt><dd>{{ \App\Models\Competency::levelName($row['expected']) ?? 'Not part of the position' }}</dd>
                <dt>{{ $admin ? 'They place themselves' : 'You place yourself' }}</dt><dd>{{ \App\Models\Competency::levelName($row['self']) ?? 'Not yet' }}@if ($a?->self_evidence)<span class="dot-note" style="display:block;white-space:pre-line;">{{ $a->self_evidence }}</span>@endif</dd>
                <dt>Agreed with HR</dt><dd>{{ \App\Models\Competency::levelName($row['agreed']) ?? 'Not yet' }}@if ($a?->agreed_note)<span class="dot-note" style="display:block;white-space:pre-line;">{{ $a->agreed_note }}</span>@endif</dd>
            </dl>

            <details style="margin-top:10px;" @if (! $admin && $row['self'] === null) open @endif>
                <summary class="dot-btn dot-btn-ghost" style="list-style:none;min-height:30px;padding:3px 10px;">{{ $admin ? 'Agree a level' : ($row['self'] ? 'Change where you place yourself' : 'Place yourself') }}</summary>
                <form method="POST" action="{{ $admin ? route('employees.competencies.agree', [$employee, $c]) : route('my-work.competencies.assess', $c) }}" style="display:grid;gap:8px;margin-top:8px;">
                    @csrf
                    <fieldset style="border:0;margin:0;padding:0;display:grid;gap:6px;">
                        <legend class="dot-label">Which describes the work best?</legend>
                        @foreach (\App\Models\Competency::LEVELS as $n => $label)
                            <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;">
                                <input type="radio" name="level" value="{{ $n }}" required @checked(($admin ? ($row['agreed'] ?? $row['self']) : $row['self']) === $n) style="margin-top:4px;">
                                <span><strong style="font-weight:500;">{{ $label }}.</strong> {{ $c->levelText($n) }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @if ($admin)
                        <div><label for="note-{{ $c->id }}">A note for {{ $employee->first_name }} (optional)</label><textarea id="note-{{ $c->id }}" name="note" class="dot-input" rows="2" maxlength="2000"></textarea></div>
                        <div><button type="submit" class="dot-btn dot-btn-primary">Agree this level</button></div>
                    @else
                        <div><label for="ev-{{ $c->id }}">What shows it (optional)</label><textarea id="ev-{{ $c->id }}" name="evidence" class="dot-input" rows="2" maxlength="2000" placeholder="Cashed up alone every Friday since March, no differences."></textarea></div>
                        <div><button type="submit" class="dot-btn dot-btn-primary">Save</button></div>
                    @endif
                </form>
            </details>
            @if ($row['toGrow'])
                <p class="dot-note" style="margin:8px 0 0;">
                    @if ($admin)
                        <a href="{{ route('training.index', ['employee_id' => $employee->id, 'for' => $c->name]) }}#book">Plan training for {{ $c->name }}</a>
                    @else
                        <a href="{{ route('my-work.index') }}#training">Ask for training in {{ $c->name }}</a>
                    @endif
                </p>
            @endif
        </div>
    @empty
        <div class="dot-card dot-card--pad"><p class="dot-note" style="margin:0;">The business has no competency framework yet. @if ($admin) <a href="{{ route('competencies.index') }}">Start one</a>.@endif</p></div>
    @endforelse

</div>
</x-app-layout>
