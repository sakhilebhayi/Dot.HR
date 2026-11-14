{{-- The shared Dot Apps switcher (R-51). Only the company's InfoDot uuid and its name reach it: no employee, no record.
     Left out of the public job pages and public profiles (jobs.*, people.*): a signed-in person browsing them is a
     visitor, not inside a workspace. In the personal career space (me.*) no company is passed, so InfoDot is not asked
     and the component says so in its personal state. --}}
@auth
@unless (request()->routeIs('jobs.*', 'people.*'))
@php
    $psTeam = auth()->user()->currentTeam;
    $psPersonal = request()->routeIs('me.*') || ! $psTeam || $psTeam->personal_team;
@endphp
<x-dot.switcher platform="hr" :company="$psPersonal ? null : $psTeam->infodot_team_uuid" :company-name="$psPersonal ? null : $psTeam->name" logos="/dot-ui/img/platforms" />
@endunless
@endauth
