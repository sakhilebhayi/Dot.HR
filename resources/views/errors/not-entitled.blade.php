{{-- R-50: the company does not hold Dot.HR. Built on the shared error page, in its plain form: no error-code line,
     no dashboard link (it would only lead back here) and no tour of other platforms, so the page has one action.
     Nothing from this app's records is passed in: only the wording, InfoDot's address, and the names of the
     person's own other workspaces. --}}
@extends('errors._ecosystem-layout', ['code' => 403, 'plain' => true])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#21255a" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="8" rx="4"/><circle cx="8" cy="12" r="2"/></svg>
@endsection

@section('title', $wording['title'])
@section('message', $wording['message'])

@section('header-action')
    @if ($signedIn)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="ne-quiet" style="color: rgba(255,255,255,0.85); font-size: 14px; padding: 12px 0;"><span class="link-underline" style="padding-bottom: 1px;">Sign out</span></button>
        </form>
    @else
        {{-- Nobody is signed in and signing in again would only lead back here: the header stays empty. --}}
        <span></span>
    @endif
@endsection

@section('actions')
    <a href="{{ $infodotUrl }}" data-primary-action class="press ne-primary" style="padding: 12px 24px; background: var(--accent); color: #21255a; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Open Platforms in InfoDot</a>
@endsection

@section('below')
    <style>
        .ne-quiet:hover .link-underline { background-size: 100% 1px; }
        .ne-quiet { background-color: transparent; border: 0; font-family: inherit; cursor: pointer; }
        .ne-primary:focus-visible, .ne-quiet:focus-visible { outline: 2px solid var(--accent-deep); outline-offset: 3px; border-radius: 6px; }
        .ne-primary:focus-visible { border-radius: 999px; }
        header .ne-quiet:focus-visible { outline-color: var(--accent); }
        h1 { text-wrap: balance; }
        ::selection { background: var(--accent); color: var(--ink); }
    </style>
    @if ($workspaces->isNotEmpty())
        <div style="border-top: 1px solid var(--line); padding-top: 24px; margin-top: -24px;">
            <p style="font-size: 15px; line-height: 1.6; color: var(--ink-soft); margin: 0 0 8px;">Or carry on in another of your workspaces:</p>
            <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; justify-content: center; gap: 0 20px;">
                @foreach ($workspaces as $workspace)
                    <li>
                        <form method="POST" action="{{ route('current-team.update') }}" style="margin: 0;">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $workspace->id }}">
                            <button type="submit" class="ne-quiet" style="color: var(--ink); font-size: 15px; font-weight: 600; padding: 12px 0; max-width: 100%; overflow-wrap: anywhere;"><span class="link-underline" style="padding-bottom: 1px;">{{ $workspace->personal_team ? 'Your personal workspace' : $workspace->name }}</span></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
