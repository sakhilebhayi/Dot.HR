<x-app-layout>
<div class="page-pad">
    <h1 class="rg-greet">
        @if ($vacancies->total() === 0)
            No open roles match that yet.
        @else
            <em>{{ $vacancies->total() }} open {{ Str::plural('role', $vacancies->total()) }}</em> from businesses on Dot.HR.
        @endif
    </h1>
    <p class="rg-sub">Every role shows who is hiring and what it pays — or says plainly that the pay is not disclosed.</p>

    <form method="GET" action="{{ route('jobs.index') }}" class="dot-card dot-card--pad" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr) auto;gap:10px;align-items:end;" id="job-search">
        <div>
            <label for="q">Role, skill or place</label>
            <input id="q" name="q" class="dot-input" maxlength="80" value="{{ $filters['q'] ?? '' }}" placeholder="Bookkeeper, Pastel, Mahikeng">
        </div>
        <div>
            <label for="type">Kind of work</label>
            <select id="type" name="type" class="dot-input">
                <option value="">Any</option>
                @foreach (\App\Models\ProfessionalProfile::WORK_TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="where">Where</label>
            <select id="where" name="where" class="dot-input">
                <option value="">Anywhere</option>
                @foreach (\App\Models\ProfessionalProfile::ARRANGEMENTS as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['where'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Search</button>
    </form>
    <style>@media (max-width: 760px) { #job-search { grid-template-columns: minmax(0,1fr) !important; } }</style>

    <div style="margin-top:18px;" class="dot-card dot-card--pad">
        @forelse ($vacancies as $vacancy)
            @php $match = auth()->check() ? \App\Services\Recruitment\SkillMatch::sentence($vacancy->skills ?? [], $mySkills) : null; @endphp
            <a href="{{ route('jobs.show', $vacancy->id) }}" class="rg-entry" style="text-decoration:none;color:inherit;">
                <div style="min-width:0;">
                    <h4>{{ $vacancy->title }} — {{ $vacancy->team->name }}</h4>
                    <div class="m">{{ $vacancy->workTypeLabel() }} · {{ $vacancy->arrangementLabel() }}@if ($vacancy->location) · {{ $vacancy->location }}@endif · {{ $vacancy->payLabel() }}</div>
                    @if ($match && ! str_starts_with($match, 'none'))<div class="m" style="color:var(--rg-accent-ink);">{{ $match }}</div>@endif
                </div>
                <div class="m" style="text-align:right;white-space:nowrap;">
                    @if (in_array($vacancy->id, $appliedIds, true))<span class="dot-badge dot-badge-accent">Applied</span><br>@endif
                    {{ $vacancy->closes_on ? 'closes '.$vacancy->closes_on->format('j M') : 'open until filled' }}
                </div>
            </a>
        @empty
            <p class="dot-note" style="margin:0;">Try a wider search — or check back: businesses publish new roles here as they open.</p>
        @endforelse
    </div>

    <div style="margin-top:14px;">{{ $vacancies->links() }}</div>
</div>
</x-app-layout>
