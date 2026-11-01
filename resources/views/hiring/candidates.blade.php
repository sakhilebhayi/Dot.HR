<x-app-layout>
<div class="page-pad">
    <p class="dot-note" style="margin:0 0 6px;"><a href="{{ route('hiring.index') }}">Hiring</a></p>
    <h1 class="rg-greet">
        @if (! $searched)
            Everyone who has applied, <em>{{ $people->count() }} {{ Str::plural('person', $people->count()) }}</em>.
        @elseif ($people->isEmpty())
            Nobody matches that.
        @else
            <em>{{ $people->count() }} {{ Str::plural('person', $people->count()) }}</em> {{ $people->count() === 1 ? 'matches' : 'match' }}.
        @endif
    </h1>
    <p class="rg-sub">Search the people who applied to your roles, by name, email, headline, place or skill. Only what they shared by applying here is searched.</p>

    <form method="GET" action="{{ route('hiring.candidates') }}" class="dot-card dot-card--pad" style="display:grid;gap:10px;margin-bottom:14px;" role="search">
        <div class="rg-form-row rg-form-row--3">
            <div><label for="c-q">Find</label><input id="c-q" name="q" type="search" class="dot-input" value="{{ $q }}" maxlength="120" placeholder="Pastel, Mahikeng, bookkeeper"></div>
            <div>
                <label for="c-state">Applications</label>
                <select id="c-state" name="state" class="dot-input">
                    <option value="all" @selected($state === 'all')>All</option>
                    <option value="open" @selected($state === 'open')>Still open</option>
                    <option value="decided" @selected($state === 'decided')>Decided</option>
                </select>
            </div>
            <div>
                <label for="c-role">Role</label>
                <select id="c-role" name="role" class="dot-input">
                    <option value="">Every role</option>
                    @foreach ($roles as $role)<option value="{{ $role->id }}" @selected($roleId === $role->id)>{{ $role->title }}</option>@endforeach
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" class="dot-btn dot-btn-primary">Search</button>
            @if ($searched)<a href="{{ route('hiring.candidates') }}" class="dot-btn dot-btn-ghost">Clear</a>@endif
        </div>
    </form>

    <div class="dot-card dot-card--pad">
        @forelse ($people as $person)
            <div class="rg-entry" style="grid-template-columns:minmax(0,1fr);">
                <div style="min-width:0;">
                    <h4>{{ $person['user']->name }}</h4>
                    <div class="m">{{ collect([$person['headline'], $person['location'], $person['user']->email])->filter()->join(', ') }}</div>
                    @if ($person['skills'])
                        <div class="rg-chips" style="margin-top:6px;">
                            @foreach ($person['skills'] as $skill)
                                <span class="dot-badge {{ in_array($skill, $person['matchedSkills'], true) ? 'dot-badge-ok' : 'dot-badge-muted' }}">{{ $skill }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div style="display:grid;gap:4px;margin-top:8px;">
                        @foreach ($person['applications'] as $application)
                            <a href="{{ route('hiring.applications.show', $application) }}" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13.5px;text-decoration:none;">
                                <span>{{ $application->vacancy->title }}, applied {{ $application->created_at->format('j M Y') }}</span>
                                <span class="dot-badge {{ $application->isOpen() ? 'dot-badge-accent' : ($application->stage === 'hired' ? 'dot-badge-ok' : 'dot-badge-muted') }}">{{ $application->stageLabel() }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @empty
            <p class="dot-note" style="margin:0;">{{ $searched ? 'Try fewer words, or a skill instead of a job title.' : 'Nobody has applied yet. People apply from the job board.' }}</p>
        @endforelse
    </div>
</div>
</x-app-layout>
