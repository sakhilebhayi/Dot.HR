<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dot.HR — the register for everyone who works here</title>
    <meta name="description" content="Hiring, onboarding, shifts, timesheets, leave, reviews and training for South African businesses, with a public careers page. People are described in words, never ranked.">

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <script>
        // Same rule as the app: a stored choice, else the system preference.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/css/register.css'])
    <style>
        /* The public face of The Register (DESIGN.md): Instrument Serif for what is said,
           Instrument Sans for everything else, tonal panels, one teal accent, the original logo. */
        body { font-size: 16px; }
        .lp-wrap { max-width: 1160px; margin: 0 auto; padding: 0 clamp(18px, 4vw, 44px); }
        .lp-logo { display: block; height: 52px; width: auto; }
        .lp-logo--night { display: none; }
        html.dark .lp-logo--day { display: none; }
        html.dark .lp-logo--night { display: block; }

        .lp-top { border-bottom: 1px solid var(--rg-border); background: var(--rg-ground); }
        .lp-top .lp-wrap { display: flex; align-items: center; gap: 20px; height: 76px; }
        .lp-top nav { margin-left: auto; display: flex; align-items: center; gap: 6px; }
        .lp-link { padding: 8px 12px; border-radius: 8px; color: var(--rg-ink); text-decoration: none; font-weight: 500; font-size: 15px; transition: background-color 140ms ease-out; }
        .lp-link:hover { background: var(--rg-panel); }
        .lp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 20px; border-radius: 9px; font: 600 15px/1 var(--rg-sans); text-decoration: none; border: 1px solid transparent; transition: opacity 140ms ease-out, background-color 140ms ease-out, transform 140ms ease-out; }
        .lp-btn:active { transform: translateY(1px); }
        .lp-btn--ink { background: var(--rg-ink); color: var(--rg-ground); }
        .lp-btn--ink:hover { opacity: 0.9; }
        .lp-btn--line { border-color: var(--rg-border); color: var(--rg-ink); background: var(--rg-ground); }
        .lp-btn--line:hover { background: var(--rg-panel); }
        .lp-btn--sm { min-height: 38px; padding: 0 14px; font-size: 14px; }

        /* Hero: the promise on paper, the real photograph framed beside it, and the product's own voice. */
        .lp-hero { padding: clamp(40px, 7vw, 84px) 0 clamp(48px, 7vw, 88px); }
        .lp-hero .lp-wrap { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: clamp(28px, 5vw, 64px); align-items: center; }
        .lp-h1 { font: 400 clamp(40px, 6.2vw, 78px)/1.02 var(--rg-serif); letter-spacing: -0.015em; margin: 0; overflow-wrap: break-word; min-width: 0; }
        .lp-h1 em { font-style: italic; color: var(--rg-accent); }
        .lp-lede { font-size: clamp(17px, 1.6vw, 19px); line-height: 1.55; color: var(--rg-muted); max-width: 34ch; margin: 22px 0 30px; }
        .lp-ctas { display: flex; flex-wrap: wrap; gap: 10px; }
        .lp-photo { position: relative; margin: 0; min-width: 0; }
        .lp-photo img { display: block; width: 100%; aspect-ratio: 4 / 5; object-fit: cover; border-radius: 14px; background: var(--rg-panel); }
        .lp-morning { position: absolute; left: clamp(-56px, -4vw, -16px); bottom: 28px; width: min(340px, 86%); padding: 16px 18px 14px; border-radius: 12px; background: var(--rg-ground); box-shadow: 0 18px 40px -18px rgb(0 0 0 / 0.35), 0 2px 6px rgb(0 0 0 / 0.06); }
        .lp-morning__greet { font: 400 22px/1.2 var(--rg-serif); margin: 0 0 10px; }
        .lp-morning__greet em { font-style: normal; color: var(--rg-accent); }
        .lp-morning ul { list-style: none; margin: 0; padding: 0; }
        .lp-morning li { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-top: 1px solid var(--rg-rule); font-size: 13.5px; }
        .lp-morning li span:last-child { color: var(--rg-muted); white-space: nowrap; }
        .lp-photo figcaption { margin-top: 10px; font-size: 12.5px; color: var(--rg-muted); text-align: right; }

        /* One authored moment: the morning's list fills in, as it would on the first load of the day. */
        @media (prefers-reduced-motion: no-preference) {
            .lp-morning li { animation: lp-in 520ms cubic-bezier(0.16, 1, 0.3, 1) both; }
            .lp-morning li:nth-child(1) { animation-delay: 380ms; }
            .lp-morning li:nth-child(2) { animation-delay: 500ms; }
            .lp-morning li:nth-child(3) { animation-delay: 620ms; }
        }
        /* Starts faint, never invisible: if animation cannot run, the rows are still there to read. */
        @keyframes lp-in { from { opacity: 0.3; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        .lp-section { padding: clamp(56px, 8vw, 104px) 0; }
        .lp-section--panel { background: var(--rg-panel); }
        .lp-h2 { font: 400 clamp(32px, 4.2vw, 52px)/1.08 var(--rg-serif); letter-spacing: -0.01em; margin: 0 0 14px; max-width: 18ch; overflow-wrap: break-word; }
        .lp-intro { color: var(--rg-muted); font-size: 17px; max-width: 56ch; margin: 0; }

        /* The year of one person, as a ledger: five stages in order, what Dot.HR keeps at each. */
        .lp-ledger { list-style: none; margin: 44px 0 0; padding: 0; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); border-top: 2px solid var(--rg-ink); }
        .lp-ledger li { padding: 18px 18px 6px 0; border-right: 1px solid var(--rg-border); }
        .lp-ledger li + li { padding-left: 18px; }
        .lp-ledger li:last-child { border-right: 0; }
        .lp-ledger h3 { font: 400 28px/1.1 var(--rg-serif); margin: 0 0 10px; }
        .lp-ledger p { margin: 0 0 10px; font-size: 15px; color: var(--rg-ink); }
        .lp-ledger .lp-who { color: var(--rg-muted); font-size: 13.5px; }

        .lp-split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: clamp(28px, 5vw, 72px); align-items: start; }
        .lp-steps { list-style: none; margin: 0; padding: 0; counter-reset: s; }
        .lp-steps li { counter-increment: s; display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 4px 14px; padding: 16px 0; border-top: 1px solid var(--rg-border); }
        .lp-steps li::before { content: counter(s); font: 400 26px/1 var(--rg-serif); color: var(--rg-accent); }
        .lp-steps b { font-weight: 600; }
        .lp-steps p { grid-column: 2; margin: 0; color: var(--rg-muted); font-size: 15px; }

        .lp-wont { list-style: none; margin: 36px 0 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 clamp(24px, 4vw, 56px); }
        .lp-wont li { padding: 18px 0; border-top: 1px solid var(--rg-border); }
        .lp-wont b { display: block; font: 400 24px/1.2 var(--rg-serif); margin-bottom: 6px; }
        .lp-wont span { color: var(--rg-muted); font-size: 15px; }
        .lp-notyet { margin: 28px 0 0; padding: 14px 16px; border-radius: 10px; background: var(--rg-ground); color: var(--rg-muted); font-size: 15px; max-width: 72ch; }
        .lp-notyet b { color: var(--rg-ink); font-weight: 600; }

        .lp-close { text-align: center; }
        .lp-close .lp-h2 { margin-left: auto; margin-right: auto; }
        .lp-close .lp-intro { margin: 0 auto 28px; }
        .lp-close .lp-ctas { justify-content: center; }

        .lp-foot { border-top: 1px solid var(--rg-border); padding: 34px 0 40px; font-size: 14px; color: var(--rg-muted); }
        .lp-foot .lp-wrap { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 28px; }
        .lp-foot nav { display: flex; flex-wrap: wrap; gap: 4px 18px; }
        .lp-foot a { color: var(--rg-ink); text-underline-offset: 3px; }
        .lp-foot small { flex-basis: 100%; font-size: 12.5px; }

        @media (max-width: 960px) {
            .lp-ledger { grid-template-columns: minmax(0, 1fr); border-top: 0; }
            .lp-ledger li, .lp-ledger li + li { padding: 18px 0 8px; border-right: 0; border-top: 1px solid var(--rg-border); }
            .lp-ledger li:first-child { border-top: 2px solid var(--rg-ink); }
            .lp-ledger h3 { font-size: 26px; }
        }
        @media (max-width: 820px) {
            .lp-hero .lp-wrap, .lp-split { grid-template-columns: minmax(0, 1fr); }
            .lp-photo img { aspect-ratio: 4 / 3; }
            .lp-morning { position: static; width: 100%; margin-top: -48px; margin-left: 0; box-shadow: 0 12px 28px -16px rgb(0 0 0 / 0.35); }
            .lp-photo { display: flex; flex-direction: column; align-items: center; }
            .lp-morning { width: calc(100% - 28px); }
            .lp-wont { grid-template-columns: minmax(0, 1fr); }
            .lp-hide-sm { display: none; }
        }
    </style>
</head>
<body>
    <header class="lp-top">
        <div class="lp-wrap">
            <a href="{{ url('/') }}" aria-label="Dot.HR home">
                <img class="lp-logo lp-logo--day" src="{{ asset('images/logo-trim.png') }}" alt="Dot.HR" width="59" height="52">
                <img class="lp-logo lp-logo--night" src="{{ asset('images/logo-light-trim.png') }}" alt="Dot.HR" width="59" height="52">
            </a>
            <nav aria-label="Main">
                <a class="lp-link lp-hide-sm" href="{{ route('jobs.index') }}">Open jobs</a>
                @auth
                    <a class="lp-btn lp-btn--ink lp-btn--sm" href="{{ route('dashboard') }}">Go to Dot.HR</a>
                @else
                    <a class="lp-link" href="{{ route('login') }}">Sign in</a>
                    @if (Route::has('register'))
                        <a class="lp-btn lp-btn--ink lp-btn--sm" href="{{ route('register') }}">Create your team</a>
                    @endif
                @endauth
            </nav>
        </div>
    </header>

    <main>
        <section class="lp-hero" aria-labelledby="hero-h">
            <div class="lp-wrap">
                <div>
                    <h1 class="lp-h1" id="hero-h">The register for <em>everyone</em> who works here.</h1>
                    <p class="lp-lede">Hiring, onboarding, shifts, leave, reviews and training, kept in words, never scores.</p>
                    <div class="lp-ctas">
                        @auth
                            <a class="lp-btn lp-btn--ink" href="{{ route('dashboard') }}">Go to Dot.HR</a>
                        @else
                            <a class="lp-btn lp-btn--ink" href="{{ Route::has('register') ? route('register') : route('login') }}">Create your team</a>
                        @endauth
                        <a class="lp-btn lp-btn--line" href="{{ route('jobs.index') }}">Looking for work? See open jobs</a>
                    </div>
                </div>

                <figure class="lp-photo">
                    {{-- Photo: colleagues at a shared table, by Christina @ wocintechchat.com (Unsplash, faEfWCdOKIg). --}}
                    <img src="https://images.unsplash.com/photo-1573164574572-cb89e39749b4?q=80&w=1400&auto=format&fit=crop" alt="Colleagues talking around a laptop at a shared table" width="1400" height="1750">
                    <div class="lp-morning" role="img" aria-label="An example of the Dot.HR home page: a greeting and three things waiting on a manager">
                        <p class="lp-morning__greet">Good morning, Naledi — <em>3 things</em> wait on you.</p>
                        <ul>
                            <li><span>Thabo asked for leave</span><span>12–14 Feb</span></li>
                            <li><span>Applications for Bookkeeper</span><span>2 new</span></li>
                            <li><span>Sipho’s forklift certificate</span><span>lapses in 9 days</span></li>
                        </ul>
                    </div>
                    <figcaption>An example morning in Dot.HR. The names are illustrative.</figcaption>
                </figure>
            </div>
        </section>

        <section class="lp-section lp-section--panel" aria-labelledby="year-h">
            <div class="lp-wrap">
                <h2 class="lp-h2" id="year-h">One person’s time with you, kept in one place.</h2>
                <p class="lp-intro">From the day someone applies to the day they leave, each step has its own page, and each person sees what concerns them.</p>
                <ol class="lp-ledger">
                    <li>
                        <h3>Hire</h3>
                        <p>Vacancies that state the pay or say plainly it is undisclosed. Applications, interviews and offers in one pipeline.</p>
                        <p class="lp-who">Roles go live on the public careers page.</p>
                    </li>
                    <li>
                        <h3>Welcome</h3>
                        <p>Onboarding checklists that say who does each task and when it should be done, and HR requests people raise themselves.</p>
                        <p class="lp-who">New starters see their own list under My work.</p>
                    </li>
                    <li>
                        <h3>Work</h3>
                        <p>Rosters and shifts, attendance and timesheets, leave with balances, and certificates that remind before they lapse.</p>
                        <p class="lp-who">Contractors are kept apart from employees.</p>
                    </li>
                    <li>
                        <h3>Grow</h3>
                        <p>Written reviews, goals and training bookings. Internal roles are matched to skills and explained.</p>
                        <p class="lp-who">The person decides whether to apply.</p>
                    </li>
                    <li>
                        <h3>Leave well</h3>
                        <p>Offboarding checklists so access and equipment are handed back, and the reason for leaving recorded.</p>
                        <p class="lp-who">Records follow a published retention schedule.</p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="lp-section" aria-labelledby="jobs-h">
            <div class="lp-wrap lp-split">
                <div>
                    <h2 class="lp-h2" id="jobs-h">Looking for work? Start with the roles.</h2>
                    <p class="lp-intro">Every open role on Dot.HR shows who is hiring and what it pays, or says the pay is not disclosed.</p>
                    <div class="lp-ctas" style="margin-top:26px">
                        <a class="lp-btn lp-btn--ink" href="{{ route('jobs.index') }}">See open jobs</a>
                    </div>
                </div>
                <ol class="lp-steps">
                    <li><b>Make your career profile once.</b><p>Your experience, skills and the work you want, in your words.</p></li>
                    <li><b>Apply in a few steps.</b><p>Your profile goes with the application, so you are not retyping it.</p></li>
                    <li><b>Follow every application.</b><p>You see where each one stands and are told when it moves.</p></li>
                    <li><b>Choose who can see you.</b><p>Your profile stays private unless you decide otherwise, and you can download everything held about you.</p></li>
                </ol>
            </div>
        </section>

        <section class="lp-section lp-section--panel" aria-labelledby="wont-h">
            <div class="lp-wrap">
                <h2 class="lp-h2" id="wont-h">What Dot.HR will not do.</h2>
                <p class="lp-intro">Some lines are drawn in the software itself, so nobody has to remember them.</p>
                <ul class="lp-wont">
                    <li><b>Score or rank people.</b><span>No productivity scores, no league tables. Reviews are written, and internal matches are listed by name with their reasons.</span></li>
                    <li><b>Track where people are.</b><span>Attendance is clocked in and out. No location, geofence or biometric data is collected.</span></li>
                    <li><b>Mix one business with another.</b><span>Every business sees only its own people, roles and records.</span></li>
                    <li><b>Keep data forever.</b><span>Old applications and time records are removed on a published schedule, and anyone can ask for a copy of what is held about them.</span></li>
                </ul>
                <p class="lp-notyet"><b>Not built yet:</b> payroll calculations, e-signatures and contract documents. Dot.HR flags the hours; it does not run pay.</p>
            </div>
        </section>

        <section class="lp-section lp-close" aria-labelledby="close-h">
            <div class="lp-wrap">
                <h2 class="lp-h2" id="close-h">Open your register.</h2>
                <p class="lp-intro">Create a team for your business and invite the people who run it with you.</p>
                <div class="lp-ctas">
                    @auth
                        <a class="lp-btn lp-btn--ink" href="{{ route('dashboard') }}">Go to Dot.HR</a>
                    @else
                        <a class="lp-btn lp-btn--ink" href="{{ Route::has('register') ? route('register') : route('login') }}">Create your team</a>
                        <a class="lp-btn lp-btn--line" href="{{ route('login') }}">Sign in</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <footer class="lp-foot">
        <div class="lp-wrap">
            <img class="lp-logo lp-logo--day" src="{{ asset('images/logo-trim.png') }}" alt="Dot.HR" width="45" height="40" style="height:40px">
            <img class="lp-logo lp-logo--night" src="{{ asset('images/logo-light-trim.png') }}" alt="Dot.HR" width="45" height="40" style="height:40px">
            <nav aria-label="Footer">
                <a href="{{ route('jobs.index') }}">Open jobs</a>
                <a href="{{ route('policy.show') }}">Privacy</a>
                <a href="{{ route('cookies') }}">Cookies</a>
                <a href="{{ route('terms.show') }}">Terms</a>
            </nav>
            <small>&copy; {{ date('Y') }} Dot.HR, part of the Dot ecosystem. Photograph by Christina @ wocintechchat.com on Unsplash.</small>
        </div>
    </footer>
</body>
</html>
