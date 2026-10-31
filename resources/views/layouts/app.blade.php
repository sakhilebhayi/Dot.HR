<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.HR</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script>
        // The Register is light by default; a stored choice (or the system preference,
        // when nothing is stored) maps the same tokens onto ink via html.dark.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    {{-- app.css carries the compiled Tailwind utilities the Jetstream pages use (the
         runtime CDN build is gone); register.css is The Register itself and wins after it. --}}
    @vite(['resources/css/app.css', 'resources/css/register.css', 'resources/js/app.js'])
    <style>
        /* The shell itself stays inline: the responsive-shell tests scan the page source
           for these rules, and the chrome renders without a flash before the bundle lands.
           Tokens come from register.css. */
        :root { --topbar-h: 54px; }
        .topbar { height: var(--topbar-h); }
        .content-wrap { padding-top: var(--topbar-h); }
        /* ------------------------------------------------------------- the top bar */
        .topbar {
            position: fixed; top: 0; left: 0; right: 0; height: 54px; z-index: 30;
            display: flex; align-items: center; gap: 16px; padding: 0 clamp(16px, 3vw, 28px);
            background: var(--rg-ground); border-bottom: 1px solid var(--rg-border);
        }
        .topbar .brand { display: flex; align-items: center; gap: 9px; font-weight: 600; font-size: 15px; text-decoration: none; }
        .topbar .brand img { width: 22px; height: 22px; object-fit: contain; }
        .topbar .brand .wm span { color: var(--rg-accent); }
        .topnav { display: flex; gap: 4px; min-width: 0; align-items: center; }
        .topnav a {
            padding: 6px 11px; border-radius: 999px; color: var(--rg-muted);
            text-decoration: none; font-weight: 500; font-size: 13.5px; white-space: nowrap;
        }
        .topnav a:hover { color: var(--rg-ink); background: var(--rg-panel); }
        .topnav a.active { color: var(--rg-accent-ink); background: var(--rg-accent-soft); }
        .topbar-team {
            margin-left: auto; font-size: 12.5px; color: var(--rg-muted);
            background: var(--rg-panel); border: 1px solid var(--rg-border);
            border-radius: 999px; padding: 4px 12px; white-space: nowrap;
            max-width: 220px; overflow: hidden; text-overflow: ellipsis;
        }
        .topbar-btn, .topbar-profile {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border: 0; border-radius: 999px; background: none;
            color: var(--rg-muted); cursor: pointer; text-decoration: none;
        }
        .topbar-btn:hover, .topbar-profile:hover { background: var(--rg-panel); color: var(--rg-ink); }
        .topbar-btn .material-symbols-rounded, .topbar-profile .material-symbols-rounded { font-size: 19px; }

        /* The drawer trigger lives in the top bar and only shows on narrow screens. */
        .shell-menu {
            display: none; align-items: center; gap: 6px; border: 1px solid var(--rg-border);
            background: var(--rg-ground); color: var(--rg-ink); border-radius: 8px;
            padding: 6px 10px; font: 500 13px var(--rg-sans); cursor: pointer;
        }

        /* --------------------------------------------- the drawer (mobile nav) */
        /* Kept as <aside id="app-sidebar" class="sidebar"> — the shell contract —
           but on wide screens it stays off-canvas: the top bar carries navigation. */
        .sidebar {
            position: fixed; left: 0; top: 0; z-index: 40; width: 290px; height: 100dvh;
            display: flex; flex-direction: column; background: var(--rg-ground);
            border-right: 1px solid var(--rg-border);
            transform: translateX(-102%); transition: transform 180ms ease;
        }
        .sidebar.is-open { transform: none; box-shadow: 14px 0 40px rgba(31, 32, 29, 0.14); }
        .shell-backdrop { position: fixed; inset: 0; z-index: 35; background: rgba(31, 32, 29, 0.32); opacity: 0; pointer-events: none; transition: opacity 160ms ease; }
        .shell-backdrop.is-open { opacity: 1; pointer-events: auto; }
        html.shell-locked, html.shell-locked body { overflow: hidden; }

        .sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 16px 18px 12px; }
        .brand-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--rg-accent-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-name { font-weight: 600; font-size: 15px; line-height: 1.2; }
        .brand-subtitle { font-size: 11.5px; color: var(--rg-muted); }
        .shell-close {
            margin-left: auto; display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border: 1px solid var(--rg-border); border-radius: 8px;
            background: var(--rg-ground); color: var(--rg-muted); cursor: pointer;
        }
        .sidebar-divider { height: 1px; background: var(--rg-border); margin: 4px 16px 8px; }
        .sidebar-nav { padding: 0 10px; flex: 1; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 11px; margin-bottom: 1px;
            border-radius: 8px; font-size: 14px; font-weight: 500; color: var(--rg-muted); text-decoration: none;
        }
        .nav-item:hover { background: var(--rg-panel); color: var(--rg-ink); }
        .nav-item.active { background: var(--rg-accent-soft); color: var(--rg-accent-ink); }
        .nav-icon { font-size: 18px; width: 20px; text-align: center; flex-shrink: 0; }

        .sidebar-footer { padding: 10px 14px 14px; border-top: 1px solid var(--rg-border); max-height: 55vh; overflow-y: auto; overscroll-behavior: contain; }
        .user-row { display: flex; align-items: center; gap: 10px; padding: 8px 6px; }
        .user-avatar {
            width: 30px; height: 30px; border-radius: 50%; background: var(--rg-accent-soft);
            color: var(--rg-accent-ink); display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600; flex-shrink: 0;
        }
        .user-name { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-team { font-size: 11.5px; color: var(--rg-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .team-switch summary {
            list-style: none; display: flex; align-items: center; gap: 8px; min-height: 38px;
            padding: 7px; border-radius: 8px; cursor: pointer; color: var(--rg-muted); font-size: 13px; font-weight: 500;
        }
        .team-switch summary::-webkit-details-marker { display: none; }
        .team-switch summary:hover { background: var(--rg-panel); color: var(--rg-ink); }
        .team-switch-list { max-height: 40vh; overflow-y: auto; padding: 2px 0 4px; }
        .team-switch-label { padding: 8px 7px 3px; font-size: 11.5px; color: var(--rg-muted); }
        .team-row, .logout-btn {
            display: flex; align-items: center; gap: 8px; width: 100%; min-height: 38px; padding: 7px;
            border: 0; border-radius: 8px; background: none; color: var(--rg-muted);
            font: 500 13px/1.3 var(--rg-sans); text-align: left; text-decoration: none; cursor: pointer;
        }
        .team-row:hover, .logout-btn:hover { background: var(--rg-panel); color: var(--rg-ink); }
        .team-row[aria-current="true"] { color: var(--rg-accent-ink); font-weight: 600; }
        .team-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .team-current { flex-shrink: 0; padding: 1px 8px; border-radius: 999px; background: var(--rg-accent-soft); color: var(--rg-accent-ink); font-size: 11px; font-weight: 600; }
        .team-row .material-symbols-rounded, .team-switch summary .material-symbols-rounded, .logout-btn .material-symbols-rounded { font-size: 17px; width: 20px; text-align: center; flex-shrink: 0; }


        /* --------------------------------------------------------------- responsive */
        @media (max-width: 859.98px) {
            .shell-menu { display: inline-flex; }
            .topnav { display: none; }
            .topbar-team { display: none; }
        }
        @media (min-width: 860px) {
            .sidebar { visibility: hidden; }
            .sidebar.is-open { visibility: visible; }
        }

        @media (prefers-reduced-motion: reduce) {
            .sidebar, .shell-backdrop { transition: none !important; }
            .dot-btn:active:not(:disabled) { transform: none; }
        }
    </style>
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render ('Alpine.cloneNode is not a function'). --}}
</head>
<body>
    <x-banner />

    {{-- The drawer: navigation and the account section below 860px. On wide screens the
         top bar carries navigation and this stays off-canvas (same markup contract). --}}
    <aside id="app-sidebar" class="sidebar" data-shell-drawer aria-label="Sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <img src="{{ asset('images/mark.png') }}" alt="Dot.HR" style="width:20px;height:20px;object-fit:contain;">
            </div>
            <div>
                <div class="brand-name">Dot.HR</div>
                <div class="brand-subtitle">The register</div>
            </div>
            <button type="button" class="shell-close" data-shell-close aria-label="Close menu">
                <span class="material-symbols-rounded" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="sidebar-divider"></div>

        @auth
        @include('partials.workspace-nav', ['style' => 'drawer'])
        @endauth

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
            <div class="shell-account">
                @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                <details class="team-switch">
                    <summary>
                        <span class="material-symbols-rounded" aria-hidden="true">groups</span>
                        <span class="team-switch-name team-label">Team: {{ Auth::user()->currentTeam->name }}</span>
                        <span class="material-symbols-rounded" aria-hidden="true">unfold_more</span>
                    </summary>
                    <div class="team-switch-list">
                        <a class="team-row" href="{{ route('teams.show', Auth::user()->currentTeam->id) }}">
                            <span class="material-symbols-rounded" aria-hidden="true">settings</span>
                            <span class="team-label">Team settings</span>
                        </a>
                        @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                        <a class="team-row" href="{{ route('teams.create') }}">
                            <span class="material-symbols-rounded" aria-hidden="true">group_add</span>
                            <span class="team-label">Create new team</span>
                        </a>
                        @endcan
                        @if (Auth::user()->allTeams()->count() > 1)
                        <div class="team-switch-label">Switch teams</div>
                        @foreach (Auth::user()->allTeams() as $switchTeam)
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                            <button type="submit" class="team-row" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>
                                <span class="material-symbols-rounded" aria-hidden="true">{{ Auth::user()->isCurrentTeam($switchTeam) ? 'check_circle' : 'swap_horiz' }}</span>
                                <span class="team-label">{{ $switchTeam->name }}</span>
                                @if (Auth::user()->isCurrentTeam($switchTeam))<span class="team-current">current</span>@endif
                            </button>
                        </form>
                        @endforeach
                        @endif
                    </div>
                </details>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-btn">
                        <span class="material-symbols-rounded" aria-hidden="true">logout</span>
                        <span class="team-label">Log out</span>
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </aside>

    <div class="shell-backdrop" data-shell-backdrop aria-hidden="true"></div>

    <header class="topbar">
        @auth
        <button type="button" class="shell-menu" data-shell-menu aria-controls="app-sidebar" aria-expanded="false">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
            <span>Menu</span>
        </button>
        @endauth
        <a href="{{ route('dashboard') }}" class="brand">
            <img src="{{ asset('images/mark.png') }}" alt="">
            <span class="wm">dot<span>.</span>hr</span>
        </a>
        @auth
        @include('partials.workspace-nav', ['style' => 'top'])
        @include('partials.workspace-switcher')
        <livewire:notification-bell />
        @endauth
        @guest
        <span style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <a href="{{ route('jobs.index') }}" class="dot-btn dot-btn-ghost" style="min-height:32px;padding:5px 10px;">Job board</a>
            <a href="{{ route('login') }}" class="dot-btn dot-btn-primary" style="min-height:32px;padding:5px 12px;">Sign in</a>
        </span>
        @endguest
        <button
            type="button"
            onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('dot-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
            class="topbar-btn"
            title="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        @auth
        <a href="{{ route('profile.show') }}" class="topbar-btn topbar-profile" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
        @endauth
    </header>

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    <script>
        // Responsive shell: open/close the off-canvas drawer below 860px. Plain script on purpose:
        // Alpine belongs to Livewire's bundle here, so shell state stays framework-free.
        (function () {
            var btn = document.querySelector('[data-shell-menu]');
            var drawer = document.getElementById('app-sidebar');
            var backdrop = document.querySelector('[data-shell-backdrop]');
            var closeBtn = document.querySelector('[data-shell-close]');
            if (!btn || !drawer || !backdrop || !closeBtn) return;
            var mq = window.matchMedia('(max-width: 859.98px)');
            var root = document.documentElement;
            function isOpen() { return drawer.classList.contains('is-open'); }
            function setOpen(open, returnFocus) {
                if (open && !mq.matches) return;
                drawer.classList.toggle('is-open', open);
                backdrop.classList.toggle('is-open', open);
                root.classList.toggle('shell-locked', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) closeBtn.focus();
                else if (returnFocus) btn.focus();
            }
            btn.addEventListener('click', function () { setOpen(!isOpen(), true); });
            closeBtn.addEventListener('click', function () { setOpen(false, true); });
            backdrop.addEventListener('click', function () { setOpen(false, true); });
            document.addEventListener('keydown', function (e) {
                if (!isOpen()) return;
                if (e.key === 'Escape') { setOpen(false, true); return; }
                if (e.key !== 'Tab') return;
                var f = Array.prototype.filter.call(drawer.querySelectorAll('a[href], button:not([disabled]), summary'), function (el) { return el.offsetParent !== null; });
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            });
            var onChange = function () { if (!mq.matches && isOpen()) setOpen(false, false); };
            if (mq.addEventListener) mq.addEventListener('change', onChange); else mq.addListener(onChange);
            window.addEventListener('pagehide', function () { setOpen(false, false); });
        })();
    </script>
    @livewireScripts
</body>
</html>
