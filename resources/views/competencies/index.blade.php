<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($competencies->isEmpty())
            No competencies yet.
        @else
            <em>{{ $competencies->count() }} {{ Str::plural('competency', $competencies->count()) }}</em> in your framework.
        @endif
    </h1>
    <p class="rg-sub">Each competency has three levels written in plain words: what the work looks like when someone is learning it, practising it, and leading others in it. Levels describe the work, not the person, and are never added up or compared between people.</p>

    <x-flash />
    @if ($errors->any())<div class="dot-flash dot-flash-err" role="alert">{{ $errors->first() }}</div>@endif

    <div class="dot-card dot-card--pad">
        @forelse ($competencies->groupBy(fn ($c) => $c->area ?: 'General') as $area => $group)
            <div class="dot-label" style="margin-top:{{ $loop->first ? '0' : '14px' }};">{{ $area }}</div>
            @foreach ($group as $competency)
                <div class="rg-entry rg-entry--tools" style="align-items:flex-start;">
                    <div style="min-width:0;">
                        <h4>{{ $competency->name }}</h4>
                        @if ($competency->description)<div class="m">{{ $competency->description }}</div>@endif
                        <dl class="rg-facts" style="margin-top:6px;">
                            @foreach (\App\Models\Competency::LEVELS as $n => $label)
                                <dt>{{ $label }}</dt><dd>{{ $competency->levelText($n) }}</dd>
                            @endforeach
                        </dl>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                        <a href="{{ route('competencies.index', ['edit' => $competency->id]) }}#form" class="dot-btn dot-btn-ghost">Edit</a>
                        <form method="POST" action="{{ route('competencies.retire', $competency) }}" style="margin:0;" data-confirm="Retire {{ $competency->name }}? It leaves the framework and the matrix; past assessments stay on record.">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Retire</button></form>
                    </div>
                </div>
            @endforeach
        @empty
            <p class="dot-note" style="margin:0;">Start with the three or four things that matter most in your work, such as serving customers, handling cash, or working safely.</p>
        @endforelse
    </div>

    <section class="rg-section" id="form">
        <h2>{{ $editing ? 'Edit '.$editing->name : 'Add a competency' }}</h2>
        <form method="POST" action="{{ $editing ? route('competencies.update', $editing) : route('competencies.store') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="rg-form-row">
                <div><label for="cp-name">Name</label><input id="cp-name" name="name" class="dot-input" maxlength="120" required value="{{ old('name', $editing?->name) }}" placeholder="Handling cash"></div>
                <div><label for="cp-area">Area (optional)</label><input id="cp-area" name="area" class="dot-input" maxlength="80" value="{{ old('area', $editing?->area) }}" placeholder="Shop floor"></div>
            </div>
            <div><label for="cp-desc">What it is about (optional)</label><input id="cp-desc" name="description" class="dot-input" maxlength="2000" value="{{ old('description', $editing?->description) }}"></div>
            @foreach (\App\Models\Competency::LEVELS as $n => $label)
                <div>
                    <label for="cp-l{{ $n }}">{{ $label }}: what the work looks like</label>
                    <textarea id="cp-l{{ $n }}" name="level_{{ $n }}" class="dot-input" rows="2" maxlength="1000" required placeholder="{{ [1 => 'Counts a till with a supervisor checking; asks before refunds.', 2 => 'Cashes up alone, balances to the cent, follows the refund rules.', 3 => 'Trains others, spots shortfalls early, sorts out discrepancies.'][$n] }}">{{ old('level_'.$n, $editing?->levelText($n)) }}</textarea>
                </div>
            @endforeach
            <div style="display:flex;gap:8px;"><button type="submit" class="dot-btn dot-btn-primary">{{ $editing ? 'Save' : 'Add it' }}</button>@if ($editing)<a href="{{ route('competencies.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>@endif</div>
        </form>
    </section>

    @if ($competencies->isNotEmpty() && $positions->isNotEmpty())
        <section class="rg-section" id="matrix">
            <h2>Which positions need what</h2>
            <p class="dot-note" style="margin:-4px 0 10px;">The level each position expects. People see where they stand against their own position, and the gap is a place to grow, with training one click away.</p>
            <div class="dot-card dot-card--pad" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;min-width:{{ 220 + $competencies->count() * 130 }}px;">
                    <thead>
                        <tr>
                            <th class="dot-th" style="text-align:left;">Position</th>
                            @foreach ($competencies as $competency)<th class="dot-th" style="text-align:left;">{{ $competency->name }}</th>@endforeach
                            <th class="dot-th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($positions as $position)
                            @php $row = $matrix->get($position->id, collect()); @endphp
                            <tr>
                                <td class="dot-td">{{ $position->title }}</td>
                                @foreach ($competencies as $competency)
                                    <td class="dot-td">
                                        <label for="m-{{ $position->id }}-{{ $competency->id }}" style="position:absolute;left:-9999px;">{{ $position->title }}, {{ $competency->name }}</label>
                                        <select id="m-{{ $position->id }}-{{ $competency->id }}" name="levels[{{ $competency->id }}]" form="matrix-{{ $position->id }}" class="dot-input" style="min-height:32px;padding:3px 8px;">
                                            <option value="">Not needed</option>
                                            @foreach (\App\Models\Competency::LEVELS as $n => $label)<option value="{{ $n }}" @selected((int) $row->get($competency->id) === $n)>{{ $label }}</option>@endforeach
                                        </select>
                                    </td>
                                @endforeach
                                <td class="dot-td">
                                    <form method="POST" action="{{ route('competencies.matrix', $position) }}" id="matrix-{{ $position->id }}" style="margin:0;">@csrf @method('PUT')<button type="submit" class="dot-btn" style="min-height:32px;padding:3px 10px;">Save</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
</x-app-layout>
