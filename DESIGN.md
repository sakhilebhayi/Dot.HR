# Dot.HR — The Register (design system of record)

Chosen by the owner 2026-10-31 from three drawn directions (R-32 Phase 1). The staff
register that greets you by name. System lives in `resources/css/register.css`; this file
is the contract every new screen follows.

## The idea
A register is personal before it is administrative: it knows your name and what concerns
you today. Every home opens with an **Instrument Serif greeting as the h1** that leads with
the one thing that matters ("Good morning, Naledi — **4 requests** are waiting on you."),
with the key figure set in the accent inside an `<em>`. Everything below the greeting is
calm: figures at **regular weight (400)** on **tonal panels** (`--rg-panel` on a white
ground), sharing one 12-column grid. Nothing shouts; the serif line already said what
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
- **Instrument Sans** 400/500/600 — everything. Body 14px/1.55. Semibold only on labels,
  buttons and the active nav; figures never bold.
- **Instrument Serif** 400 — the greeting and page titles only (`.rg-greet`,
  `.page-pad h1`). Never body text, never buttons.
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

## Public landing page (`/`)
Persuade surface in the same world: the Instrument Serif promise ("The register for
*everyone* who works here.") beside the real photograph, a labelled example of the app's
morning greeting as the signature, the five-stage ledger (Hire, Welcome, Work, Grow, Leave
well) listing only what is built, a path for people looking for work (`/jobs`), what Dot.HR
will not do, and a plain "not built yet" line. No eyebrows, no gradients, no invented numbers.
