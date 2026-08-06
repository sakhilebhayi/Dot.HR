<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.HR — Positions, employee records, and leave, without ranking anyone</title>
        <meta name="description" content="Team-scoped Laravel software for positions, employment records, and leave requests. Publishes knowledge about roles and structure — never a score attached to a person.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Karla:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #faf7f0;
                --paper-deep: #f1ead9;
                --ink: #21255a;
                --ink-soft: #4c5085;
                --gold: #f0b91c;
                --gold-deep: #c98a09;
                --line: rgba(33, 37, 90, 0.14);
                --font-display: 'Fraunces', ui-serif, Georgia, serif;
                --font-body: 'Karla', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(33, 37, 90, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[var(--paper)]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.HR" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#entities" class="link-underline hover:text-[var(--ink)] pb-0.5">What it manages</a>
                    <a href="#ecosystem" class="link-underline hover:text-[var(--ink)] pb-0.5">Where it sits</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Create your team
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#entities" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">What it manages</a>
                    <a href="#ecosystem" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Where it sits</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden">
            <!-- Photo: colleagues collaborating around a laptop at a shared table, by Christina @ wocintechchat.com, unsplash.com/photos/faEfWCdOKIg -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1573164574572-cb89e39749b4?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(175deg, rgba(250,247,240,0.58) 0%, rgba(250,247,240,0.82) 40%, var(--paper) 74%, var(--paper) 100%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, var(--paper) 0%, rgba(250,247,240,0.88) 32%, rgba(250,247,240,0.4) 58%, rgba(250,247,240,0) 84%);"></div>

            <!-- Org-chart line art — echoes the pyramid-of-people icon in the real Dot.HR mark, redrawn as a reporting-structure tree -->
            <svg class="hidden lg:block absolute right-[4%] top-[14%] h-[72%] w-auto opacity-[0.10] pointer-events-none" viewBox="0 0 240 320" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="120" cy="40" r="18" stroke="#21255a" stroke-width="3"/>
                <circle cx="66" cy="150" r="15" stroke="#21255a" stroke-width="3"/>
                <circle cx="174" cy="150" r="15" stroke="#21255a" stroke-width="3"/>
                <circle cx="34" cy="264" r="11" stroke="#21255a" stroke-width="2.5"/>
                <circle cx="98" cy="264" r="11" stroke="#21255a" stroke-width="2.5"/>
                <circle cx="142" cy="264" r="11" stroke="#21255a" stroke-width="2.5"/>
                <circle cx="206" cy="264" r="11" stroke="#21255a" stroke-width="2.5"/>
                <path d="M108 54L72 136M132 54L168 136" stroke="#21255a" stroke-width="2"/>
                <path d="M58 165L38 253M76 163L94 253" stroke="#21255a" stroke-width="1.5"/>
                <path d="M164 164L146 253M184 163L202 253" stroke="#21255a" stroke-width="1.5"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-deep)] mb-6">
                        Workforce management — team-scoped
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--ink)] mb-6">
                        Work, not workers.
                    </h1>

                    <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                        Positions, employment records, and leave requests, run one team at a time with role-gated authorization from the first commit. Dot.HR is built so what leaves this platform is knowledge about roles and structure — never a score attached to a person.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                                Create your team
                            </a>
                            <a href="#entities" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-soft)] transition-colors">
                                See what's built
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Inventory strip — what's actually implemented, not a fabricated metric -->
            <div class="relative z-10 w-full border-t border-[var(--line)] bg-[var(--paper)]/70 backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ink-soft)]">
                    <span>Positions</span>
                    <span class="text-[var(--gold-deep)]">·</span>
                    <span>Employee records</span>
                    <span class="text-[var(--gold-deep)]">·</span>
                    <span>Leave workflow</span>
                    <span class="text-[var(--gold-deep)]">·</span>
                    <span>Team-scoped authorization</span>
                </div>
            </div>
        </section>

        <!-- Entities -->
        <section id="entities" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-deep)] mb-4">What it manages</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Three entities, built and authorized
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $entities = [
                            ['tag' => 'Roles', 'title' => 'Positions', 'body' => 'Job and role definitions, scoped to your team\'s own org structure — describing the work itself, not any individual doing it.'],
                            ['tag' => 'Records', 'title' => 'Employee records', 'body' => 'Minimal PII by design: name, work email or phone, position, employment type, status, and start/end date. No ID numbers, no salary, no medical data.'],
                            ['tag' => 'Workflow', 'title' => 'Leave requests', 'body' => 'A pending, approved, or denied workflow tied to one employee at a time. The free-text reason field is treated as sensitive by default.'],
                            ['tag' => 'Access', 'title' => 'Role-gated mutations', 'body' => 'Creating, editing, or deleting a Position, Employee, or Leave Request requires the team\'s admin role or ownership. Viewing stays open to any team member.'],
                            ['tag' => 'Isolation', 'title' => 'Tenant scoping at the model', 'body' => 'A global Eloquent scope constrains every query on Employee, LeaveRequest, and Position to the current team — closing the class of bug where a where(team_id) call gets forgotten.'],
                            ['tag' => 'Stack', 'title' => 'Jetstream Teams shell', 'body' => 'Laravel 12, Jetstream Teams, Fortify, and Sanctum — the same foundation the rest of the Dot Ecosystem runs on.'],
                        ];
                    @endphp
                    @foreach ($entities as $i => $e)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold-deep)] mb-3">{{ $e['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $e['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{{ $e['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Ecosystem boundaries -->
        <section id="ecosystem" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-deep)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-deep)] mb-4">Where it sits</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            Clear boundaries with the platforms around it
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-sm">
                            Dot.HR owns the people domain and stops there, on purpose. Money, task assignment, and the shared knowledge graph all live somewhere else.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $boundaries = [
                                ['title' => 'HR owns the roster', 'body' => 'Positions, employees, and leave live here. Payroll execution is out of scope — Dot.Billing owns money movement, and no billing integration exists in this codebase yet.'],
                                ['title' => 'Role definitions, not task assignment', 'body' => 'HR owns what a role is. Who does what today belongs to Dot.Tasks and Dot.Projects — no integration exists between the two yet.'],
                                ['title' => 'PII excluded at the type level', 'body' => 'Employment records are structurally absent from the ecosystem\'s shared knowledge graph — not filtered at review time, never represented in the outbound model at all.'],
                                ['title' => 'No individual scoring, ever', 'body' => 'No productivity scores, attendance streaks, or peer comparison of any kind. A future recognition feature, if it ships, targets team-level coverage — never a person.'],
                                ['title' => 'MVP scaffolded, honestly labeled', 'body' => 'Built and, as of the latest change log entry, actually executed against Postgres: 43 tests, 91 assertions passing. Roadmap items stay marked as roadmap, not shipped.'],
                                ['title' => 'Aggregation layer — still roadmap', 'body' => 'Publishing workforce-structure Knowledge Packs to Dot.Brain is design intent, not built. There is no outbound integration path from this codebase today.'],
                            ];
                        @endphp
                        @foreach ($boundaries as $b)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--ink)] mb-1.5">{{ $b['title'] }}</h3>
                                <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $b['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <div class="absolute inset-0" style="background: linear-gradient(180deg, var(--paper) 0%, var(--paper-deep) 100%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    A structural boundary, not a policy promise
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Dot.HR publishes knowledge about roles, skills, and workforce structure — never knowledge that models, ranks, or predicts an identified individual.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                            Create your team
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.HR" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.HR. Workforce management for team-scoped organizations.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
