{{-- The workspace switcher (R-32 Phase 3): one identity, many professional relationships.
     The personal workspace is always there; each business the person belongs to is one
     more workspace, entered through Jetstream's own current-team switch (a POST with CSRF,
     authorised by membership — nobody can switch into a company they are not in). --}}
@auth
@php
    $personal = request()->routeIs('me.*');
    $current = auth()->user()->currentTeam;
    $businesses = auth()->user()->allTeams()->values();
@endphp
<details class="ws-switch">
    <summary class="topbar-team" aria-label="Switch workspace">
        {{ $personal ? 'Personal' : ($current->name ?? 'Personal') }}
        <span class="material-symbols-rounded" aria-hidden="true" style="font-size:16px;vertical-align:-3px;">unfold_more</span>
    </summary>
    <div class="ws-menu" role="menu">
        <a href="{{ route('me.home') }}" class="ws-item {{ $personal ? 'ws-item--on' : '' }}" role="menuitem">
            <span class="ws-name">Personal</span>
            <span class="ws-sub">Your profile and career</span>
        </a>
        @if ($businesses->isNotEmpty())
            <p class="ws-head">Businesses</p>
            @foreach ($businesses as $business)
                @php $roleLabel = $business->user_id === auth()->id() ? 'Owner' : (auth()->user()->teamRole($business)?->name ?? 'Member'); @endphp
                @if ($current?->id === $business->id)
                    {{-- Already the current team: entering it needs no switch, just its home. --}}
                    <a href="{{ route('dashboard') }}" class="ws-item {{ $personal ? '' : 'ws-item--on' }}" role="menuitem">
                        <span class="ws-name">{{ $business->name }}</span>
                        <span class="ws-sub">{{ $roleLabel }}</span>
                    </a>
                @else
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="team_id" value="{{ $business->id }}">
                        <button type="submit" class="ws-item" role="menuitem">
                            <span class="ws-name">{{ $business->name }}</span>
                            <span class="ws-sub">{{ $roleLabel }}</span>
                        </button>
                    </form>
                @endif
            @endforeach
        @endif
    </div>
</details>
@endauth
