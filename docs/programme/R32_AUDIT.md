# R-32 Phase 1 — Dot.HR discovery, audit and implementation plan

Date: 2026-10-31. Brief: `requirements/dot-hr-transformation.md` (verbatim, R-32).
Working baseline measured on this checkout and the live host before any change.

## 1. What Dot.HR is today (verified)

**Live** at hr.infodot.co.za (/up 200, home 200; manual deploys green, last 2026-10-28).
**Stack**: Laravel 12 / Jetstream (teams) / Tailwind **v3** / Livewire 3 installed but used for
exactly one component (NotificationBell). Classic controllers + Blade everywhere else.
**Tests: 360 (359 pass, 1 skipped)** — a real baseline, including authorization, team-scope,
SSO, notify and analytics suites.

What genuinely works:
- **Identity**: legacy ecosystem SSO (`POST /auth/ecosystem`, token redeemed against InfoDot
  via `Services/InfoDot/IdentityClient`; GET variant retired safely). Public registration
  closed — accounts are created by InfoDot at first sign-in. Teams carry `infodot_team_uuid`
  and sync from InfoDot (InfoDotTeamSyncTest).
- **HR core**: Employees CRUD (soft deletes + restore, link/unlink to a user account),
  Positions, Leave types, Leave requests (submit → approve/deny/cancel) with observers,
  policies on every model, a leave-balance service with snapshots, a leave calendar service.
- **Protection already in place**: sensitive employee fields encrypted at rest with a blind
  index for uniqueness (2026-10-18 migrations); `HrAuditLog` + `CaptureAuditContext`
  middleware; per-team scoping tested.
- **Ecosystem**: `Services/Notify/NotifyClient` (Dot.Notify), `Services/Analytics` metrics
  collector, notifications table + bell.
- CI (ci.yml), manual deploy (deploy.yml), drift check (drift.yml); PHPStan config present.

## 2. Honest gaps (measured, not guessed)

- **No security headers at all** — no middleware in the repo and the live host serves none
  (fleet gap already on the R-22 register: HR had no header PR).
- **composer.lock is stale** against composer.json in the committed tree (install warns).
- **Design**: stock Jetstream/Tailwind UI — exactly the generic dashboard the brief bans.
  No DESIGN.md, no design system, no distinct platform identity. 100 Blade views, most of
  them Jetstream scaffolding.
- **The entire recruitment half does not exist**: no vacancies, applications, candidate
  profiles, pipelines, interviews, offers.
- **No individual workspace**: a user is only ever a team member looking at the same admin UI.
  No professional profile, CV, job discovery, application tracking.
- **Leave schema is behind its own UI**: `leave_requests.type` is still the old enum
  (annual/sick/unpaid/other) while a `leave_types` table exists — reconcile before building on it.
- **Employment types**: enum of 3 (full_time/part_time/contractor) versus the brief's 25
  arrangements; no compensation fields; no onboarding, scheduling, performance, documents,
  contractors, workflows.
- Welcome page is a Laravel-ish placeholder; employer dashboard is a thin counters page.

## 3. Design direction — three systems to choose from (boards built)

Served from `.taste-skill/boards/` (http://127.0.0.1:8790/ in this session). Each shows the
same three screens: employer home, one vacancy pipeline, individual home. The Achromatic
Ledger was deliberately excluded — it is Dot.Files' identity.

- **A — The Register** (`a-register.html`): the staff register that greets you by name.
  Instrument Serif greeting as the h1, figures at regular weight on tonal panels, one
  12-column grid, teal-green #1b6e66. Warmest and most human; the individual side shines.
- **B — The Ladder** (`b-ladder.html`): one deep green (#15724c) at full strength only on
  small marks, pale tints carrying every area (active nav, plan card, badges, progress).
  DM Sans sidebar workspace; the most conventional-professional of the three, closest to the
  Deel/BambooHR class while staying disciplined.
- **C — The Personnel File** (`c-personnel-file.html`): a tinted folder holds the chrome, every
  screen is a white sheet inset in it; IBM Plex + mono reference numbers (EMP-014, VAC-003,
  LVE-031) making every record citable. The most distinctive and most "system of record".

## 4. Prioritised plan (brief §24, grounded in what exists)

Per-PR owner approval as usual; every phase leaves main green and deployable.

- **Phase 2 — The design system + both shells** (first build PR after the direction is
  chosen): DESIGN.md + the system CSS, new navigation, employer home rebuilt around
  "waiting on you", the individual workspace shell (new), welcome page, all existing CRUD
  screens re-set in the system. Fold in: SecurityHeaders middleware (fleet baseline + CSP
  report-only), composer.lock refresh. Jetstream profile/team pages re-skinned minimally.
- **Phase 3 — Identity & workspaces**: workspace switcher (personal ↔ authorised business
  workspaces), business-level recruitment roles (owner/HR admin/recruiter/manager/viewer),
  professional profile foundation (headline, about, skills, history, visibility controls),
  InfoDot business profile read where the API serves it. Tenant isolation tests extended.
- **Phase 4 — Recruitment marketplace**: vacancies (draft/publish/pause/close, the brief's
  employment arrangements as a registry not an enum), public opportunity discovery +
  search, applications (dedupe like Forms' client token), pipeline stages (configurable,
  default set), candidate records per employer (isolated), interviews (schedule, panel,
  scorecard), offers (draft → send → accept/decline) → hand-off into an employee record.
- **Phase 5 — Employee lifecycle**: onboarding checklists (offer acceptance → tasks →
  documents), employee self-service portal (own record, leave, documents), leave v2
  (leave_types everywhere, balances/accrual rules per jurisdictional policy config), HR
  requests, offboarding checklist. Dot.Files/Dot.Docs references where their platform APIs
  support it (Files' platform HMAC API exists — R-28).
- **Phase 6 — Workforce management**: scheduling/shifts/attendance/timesheets (approval
  flows), contractor engagements, performance reviews + goals, training/certification
  tracking with expiry reminders (the mining/operational configurables from §8 as options).
- **Phase 7 — Intelligence**: explainable matching (skills overlap, never protected
  attributes; human decision preserved), funnel + workforce analytics, AI assist surfaces
  (job-description improvement, CV extraction) gated on the existing key pattern.
- **Phase 8 — Enterprise readiness**: restore drill hooks, rate limits, retention jobs,
  POPIA tooling parity with InfoDot, performance passes, monitoring.

Out of honest scope until their dependencies exist (stated now, not discovered later):
payroll statutory calculations (architecture only, per brief §11), video interviews and
calendar sync (no integration exists), e-signatures (no authorised provider), Dot.Notify
channels beyond what NotifyClient already does, Dot.HR ↔ Dot.Projects/Agents/Brain
integrations until those APIs are verified in their repos.

## 5. Phase-1 register of defects to fix regardless of direction

1. No security headers (repo + live). → Phase 2 PR.
2. composer.lock stale. → Phase 2 PR.
3. leave_requests.type enum vs leave_types table split-brain. → Phase 5 (schema reconcile).
4. Welcome page placeholder. → Phase 2.
5. Employment-type enum too narrow and locked in the schema. → Phase 4 registry.

## Phase 3 delivered (identity and workspaces)
- **Recruitment grants** (`hr_grants`): InfoDot rewrites a member's team role at every
  sign-in, so a recruiting permission cannot live there. HR administrators grant
  `recruiter` / `hiring_manager` per business on the Access page (audited); a grant only
  counts while the holder is still a member, so InfoDot removing someone closes it.
- **Professional profile** (user-owned, one per person): headline, about, location,
  availability, arrangements, the 22-entry work-type registry (reused by vacancies in
  Phase 4), skills (normalised for matching), work history / education / certifications
  with expiry, a random share handle, and blocks by exact business name (no roster of
  tenants is ever listed).
- **Visibility** decided in one policy: public (anyone), employers (a viewer acting in a
  business where they may recruit, unless blocked), applications (nobody until Phase 4
  creates real applications), private. Contact details are never part of a profile; a
  hidden profile answers 404 like a missing one.
- **Workspaces**: personal (`/me`) and every team the person belongs to, one switcher.
- **InfoDot business details**: InfoDot exposes no business-profile API today (only the
  public page `/business/{uuid}`), so Dot.HR links there instead of copying data. A read
  API on InfoDot is the prerequisite for showing logo/verification inside Dot.HR.
