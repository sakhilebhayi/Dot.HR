<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="dot-theme" data-dh-theme-default="system">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.HR</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Roboto, self-hosted from this site's own public/dot-ui (R-52). No third-party font request, no CDN. --}}
    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
    {{-- Theme before the first paint (dot-ui's own boot script, an external file): a stored choice wins, otherwise the system
         preference. The Register is paper by default and maps the same tokens onto ink under html.dark. --}}
    <script src="{{ asset('dot-ui/js/dot-theme-boot.js') }}"></script>
    {{-- Shared Dot UI (header, search pill, platform switcher), served from this site's own public/dot-ui: no inline style or
         script, nothing external. Loaded before the app CSS so the host mapping in register.css wins. --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-header.css') }}">
    {{-- app.css carries the compiled Tailwind utilities the Jetstream pages use; register.css is The Register itself and wins after it. --}}
    @vite(['resources/css/app.css', 'resources/css/icons.css', 'resources/css/register.css', 'resources/js/app.js'])
    {{-- A page may send its own first-paint rules with the document, as the shell does. --}}
    @stack('head')
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render ('Alpine.cloneNode is not a function'). --}}
</head>
<body>
    @php
        $user = Auth::user();
        $team = $user?->currentTeam;
        $personal = App\Support\Hr\WorkspaceNav::isPersonal(request());
        $nav = App\Support\Hr\WorkspaceNav::nav(request(), $user);
        $account = $user ? App\Support\Hr\WorkspaceNav::account(request(), $user) : null;
        $psPersonal = ! $team || $team->personal_team || request()->routeIs('me.*');
        // The switcher asks InfoDot; when even the package cannot build its answer it still prints the honest degraded state.
        $switcher = null;
        if ($user && ! request()->routeIs('jobs.*', 'people.*')) {
            try {
                $switcher = Dot\Ui\PlatformSwitcher::fromConfig('hr')->for($psPersonal ? null : $team->infodot_team_uuid);
            } catch (Throwable) {
                $switcher = Dot\Ui\PlatformSwitcher::plain('hr');
            }
        }
    @endphp

    <x-dot.header class="dh--swpanel" :brand-href="$user ? route('dashboard') : route('jobs.index')" brand-label="Dot.HR home" :nav="$nav" :account="$account"
                  :guest="$user ? null : ['login' => ['label' => 'Sign in', 'href' => route('login')]]" skip="#main"
                  :context="$user && ! $personal && $team && ! $team->personal_team ? $team->name : null">
        <x-slot:brand>
            <img class="dh-logo-day" src="{{ asset('images/logo-trim.png') }}" alt="" width="52" height="46">
            <img class="dh-logo-night" src="{{ asset('images/logo-light-trim.png') }}" alt="" width="52" height="46">
        </x-slot:brand>
        <x-slot:search>
            @auth
                <livewire:header-search :personal="$personal" />
            @else
                {{-- Signed out (the public job board): a plain GET over the open roles, the board's own search. --}}
                <x-dot.search name="q" :action="route('jobs.index')" :value="request('q')" label="Search open roles" placeholder="Find an open role" hint="Ctrl K" />
            @endauth
        </x-slot:search>
        <x-slot:tools>
            @if ($switcher)
                <x-dot.switcher platform="hr" :model="$switcher" :company="$psPersonal ? null : $team->infodot_team_uuid" :company-name="$psPersonal ? null : $team->name" logos="/dot-ui/img/platforms" />
            @endif
            @auth
                @unless (app(\App\Http\Middleware\EnsureCompanyEntitled::class)->refusal(request(), cachedOnly: true))
                    <livewire:notification-bell />
                @endunless
            @endauth
        </x-slot:tools>
    </x-dot.header>

    <div class="content-wrap">
        <x-banner />
        <main id="main" tabindex="-1">{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
    <script src="{{ asset('dot-ui/js/dot-search.js') }}" defer nonce="{{ Vite::cspNonce() }}"></script>
    <script src="{{ asset('dot-ui/js/dot-search-results.js') }}" defer nonce="{{ Vite::cspNonce() }}"></script>
    <script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer nonce="{{ Vite::cspNonce() }}"></script>
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer nonce="{{ Vite::cspNonce() }}"></script>
</body>
</html>
