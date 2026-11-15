# Dot.HR — The Register (design system of record)

Chosen by the owner 2026-10-31 from three drawn directions (R-32 Phase 1). The staff
register that greets you by name. System lives in `resources/css/register.css`; this file
is the contract every new screen follows.

## The idea
A register is personal before it is administrative: it knows your name and what concerns
you today. Every home opens with a **Roboto greeting as the h1** that leads with
the one thing that matters ("Good morning, Naledi — **4 requests** are waiting on you."),
with the key figure set in the accent inside an `<em>`. Everything below the greeting is
calm: figures at **regular weight (400)** on **tonal panels** (`--rg-panel` on a white
ground), sharing one 12-column grid. Nothing shouts; the greeting already said what
matters.

## Tokens (`:root`, mapped for `html.dark`)
- Ground `--rg-ground` #ffffff · panel `--rg-panel` #f7f7f5 · raised `--rg-raised` #f0f0ed
- Border `--rg-border` #e7e7e3 · rule `--rg-rule` #ecece8
- Ink `--rg-ink` #1f201d · muted `--rg-muted` #6d6f69
- Accent `--rg-accent` #1b6e66 (teal-green), soft #e9f2f0, accent-ink #114b45
- Verdicts: ok #247b50 · warn #92400e · bad #b3362a, each with its pale bg
- Dark (`html.dark`, user toggle or system preference): ink ground #191a17, panels one
  step up, accent lightened to #6fb3ab. No pure black, no grey-on-grey below 4.5:1.

## Type
- **Roboto** (R-52), self-hosted from `public/dot-ui/fonts/roboto`, everything: `--rg-sans` and
  `--rg-serif` both resolve to `--dot-font-sans`. Body 14px/1.55. Weight 500 on labels, buttons,
  the active nav and (with -0.01em tracking) the greeting, page titles and big numerals, where
  Instrument Serif used to sit; figures never bold. No third-party font host, no other text face.
- Icons are a 5 kB self-hosted subset of Material Symbols Rounded (`resources/fonts`, only the
  glyphs the app prints, `resources/css/icons.css`); if it never loads the ligature names are hidden.
- Tabular numerals (`.dot-figure`) on anything that counts. Sentence case everywhere;
  no tracked capitals, no eyebrows.

## Chrome
- **Top bar** (60px, fixed): the **original Dot.HR logo** (`images/logo-trim.png`, night variant
  `logo-light-trim.png` under `html.dark`; the artwork cropped of empty canvas only, never
  redrawn or retyped), pill nav links
  (active = accent-soft fill with accent-ink text), workspace chip right, bell, theme
  toggle, profile. Below 860px the nav moves into the off-canvas **drawer**
  (`aside#app-sidebar.sidebar` — the markup contract the shell tests pin), which also
  carries the account section (team switch, settings, logout) at every width.
- Pages: `.page-pad` (max 1100px, centred). Panels: `.dot-card` (tonal, radius 10,
  **no borders, no shadows, no cards inside cards**). Grid: `.rg-grid` + `.s3/.s4/.s6/.s8`.
  `.r-stack` on any inline two-column grid makes it one column below 720px.

## Vocabulary (kept names, re-set meanings)
`dot-card` tonal panel · `dot-label` 12.5px muted · `dot-note` 13px muted ·
`dot-figure` tabular 400 · `dot-btn` (+`-primary` ink-filled, `-ghost` accent text,
`-danger` red text) · `dot-input` bordered field · `dot-th/td` quiet table ·
`dot-badge` (+ok/warn/bad/muted/accent pills) · `dot-flash` notices.

## Motion
Almost none: the drawer slides (180ms), buttons press 1px. Every transition sits behind
`prefers-reduced-motion` (app.css already zeroes animations globally for those users).

## Voice
Plain words, person first: "Nothing is waiting on you", "Nobody is on leave today",
"Leave", not "Leave Request Management". Figures carry units in prose, not in the number.

## Never
The banned list from the brief §18 is binding: no gradients, no glassmorphism, no
repeated icon-tile cards, no decorative stats, no violet/indigo, no dark-dashboard-by-
default, no invented numbers anywhere.

## Signed-in landing (`/dashboard`)
Where a person arrives from InfoDot. One block (`.rg-land`, styles sent with the page): the
greeting h1, one sentence saying what Dot.HR is for in this business by name, and **exactly
one primary action** (`data-primary-action`, the only ink-filled button on the page) chosen by
role: an HR administrator decides the leave that is waiting, or adds an employee when nothing
is; a manager opens the team's leave; an employee requests leave; a member with no record
opens their personal workspace. Beside it sit the original logo, larger than in the bar, and
the way back ("InfoDot", "Platforms"). Everything below is the calm register as before. An
empty business is told so in words, with no figures. One entrance, on the greeting block only.

## Public landing page (`/`)
Persuade surface in the same world: the Roboto promise ("The register for
*everyone* who works here.") beside the real photograph, a labelled example of the app's
morning greeting as the signature, the five-stage ledger (Hire, Welcome, Work, Grow, Leave
well) listing only what is built, a path for people looking for work (`/jobs`), what Dot.HR
will not do, and a plain "not built yet" line. No eyebrows, no gradients, no invented numbers.

## Cases (employee relations)
`.rg-steps` is the stage line: the process in words (Opened, Investigating, Hearing, Decided,
Closed), current step in accent ink, no progress bar. `.rg-timeline` is a case's record: a dated
gutter, the entry kind, who wrote it, and a badge saying **Internal** or **Shared with {name}**
on every entry, so nobody guesses who read what; the person's own words sit on accent-soft.
`.rg-facts` is the quiet label/value list beside it; `.rg-tabs` are pill filters.

## Header and navigation (R-52)
One shared dot-ui 0.6.0 header (`<x-dot.header>`): logo, the daily places (Home, My work, Hiring,
People or Employees, Leave) and four named groups (Workforce, Growth, Records, Setup, built in
`App\Support\Hr\WorkspaceNav`, each entry only for people who may open it), the search centred by
the grid, the platform switcher, the bell and one account menu (workspace switch, profile, team,
theme, sign out). On a phone the same list is the drawer. The search is role-aware
(`App\Services\Hr\HeaderSearch`): an administrator finds people, leave, positions and candidates;
a manager finds names, positions and only the NAMES of people who are away; an employee only their own
record and leave; a candidate their own applications and the open job board. Never pay, leave reasons
or contact details.

### dot-ui 0.6.1 (R-52 re-sync)

The signed-in layout links one stylesheet, `dot-ui/css/dot-ui.min.css` (first in the head, Roboto not preloaded), `dot-theme-boot.min.js` and one deferred `dot-ui-core.min.js` (the search results controller loads on first focus; the Livewire `HeaderSearch` only prints results-nav markup). Guest, welcome, certificate and error pages link `dot-roboto.min.css` + `dot-type.min.css`.
