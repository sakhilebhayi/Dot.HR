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
- ~~Leave schema split-brain~~ — **corrected in Phase 5:** this was wrong. The 2026-10-18
  migrations already made `leave_type_id` authoritative and turned `type` into a deliberate
  string snapshot of the type's slug (so renaming a type never rewrites history). Nothing to fix.
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
3. ~~leave_requests.type split-brain~~ — not a defect (see the correction above).
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

## Phase 4 delivered (recruitment marketplace)
- **Roles** (`vacancies`): draft → publish → pause / close → reopen, duplicate; the work-type
  and arrangement registries shared with profiles; honest pay (a range, a rate, or "Not
  disclosed" said plainly); up to 5 screening questions; closing dates enforced.
- **Public job board** (`/jobs`): open roles of every business, searchable by role, skill
  or place, filterable by kind of work and arrangement; draft, paused, closed and expired
  roles are 404 to everyone outside the business.
- **Applications**: one per person per role (unique index + race-safe catch); applying
  requires explicit consent to share the profile and email with that one business, and
  opens an "applications-only" profile to that business's reviewers and nobody else.
- **Pipeline**: New → In review → Shortlisted → Interview → Offer → Hired / Not progressing /
  Withdrawn (a fixed default set; per-business custom stages are not built yet). Private
  notes and a full history, both invisible to the candidate and to other businesses.
- **Matching** is explainable skill overlap only ("matches 2 of 4 skills: Pastel Partner,
  payroll") — never a hidden score, never an attribute of the person, never an automatic
  rejection.
- **Interviews**: schedule (time, length, mode, place, a note for the candidate), cancel,
  employer-only feedback with a recommendation; hiring managers may give feedback.
- **Offers**: draft or send, withdraw; the candidate accepts or declines in Dot.HR.
  Acceptance creates the employee record **exactly once** (row locks + the offer's
  employee_id), links an existing unlinked record with the same email instead of clashing,
  maps the role's detailed work type onto the employee record's three broad types, and is
  audited.
- **Candidate workspace**: Find work, Applications (plain-language stages, interviews, the
  offer), withdraw. In-app notifications both ways (new application → recruiters; interview,
  offer, decision → candidate).

Not built in Phase 4 (stated, not implied): per-business custom stages and bulk actions,
calendar/video integrations, e-signature of offers, email delivery of notifications (they
are in-app; Dot.Notify fan-out is a later step), candidate search across profiles (the
"employers" visibility is honoured for direct links only), the public landing page rewrite.

## Phase 5 delivered (employee lifecycle)
- **Onboarding and offboarding checklists**: tasks owned by the person or by HR, policy
  acknowledgements recorded with who and when (audited), one open checklist per person per
  process (row lock), a business's own default checklists edited as plain lists (Dot.HR's
  neutral defaults otherwise — working steps, not legal advice).
- **Recruitment → onboarding**: an accepted offer starts the business's onboarding checklist
  in the same transaction as the hire (brief §25.6).
- **Offboarding** records a last day and a reason CATEGORY only; the employee record changes
  (terminated, end date) only when HR finishes it; cancelling changes nothing; open steps
  stay recorded as not done.
- **Employee self-service (My work)**: the person's own record, their steps, their phone and
  an emergency contact (encrypted at rest, change audited by field name only), and HR
  requests. No employee id in any self-service route.
- **HR requests**: raised by the employee, read by HR administrators only (never managers),
  body and answer encrypted at rest, viewing audited, answers notified.
- **Dashboard**: joining / leaving / requests waiting for HR; a "steps for you" line for the
  employee.

Not built in Phase 5 (stated): employment documents and contracts (no Dot.Files or Dot.Docs
client exists in Dot.HR yet — it needs platform credentials and the Files platform API, a
phase of its own); accrual changes to leave (the existing entitlement / carry-over /
enforce-balance model stands); disciplinary and grievance case management (brief §15 —
deliberately not mixed into ordinary records; it needs its own confidential model).

## Phase 6a delivered (time and competency)
- **Schedule**: shifts by week (a night shift crosses midnight), people assigned per shift.
  Assigning refuses — with the reason shown — someone already on an overlapping shift, someone
  on approved leave then, or a terminated person; the employee row is locked while it checks.
- **Supervisor capability**: a third `hr_grants` capability (`supervisor`) builds the
  schedule, records attendance and approves timesheets without being a team admin. Managers
  can read the schedule.
- **Attendance**: the person clocks in and out from My work. Only times are stored — no
  location, no device fingerprint, no biometrics. A clock-in links to a shift within two
  hours either side; the shift's break applies when the stretch is long enough. Supervisors
  record a missed stretch or correct one; both are marked "Supervisor", carry a reason and
  are written to the audit log.
- **Timesheets**: the person submits a week (refused while clocked in, for a future week, or
  twice); a supervisor approves or sends it back with a note, never their own; the person is
  notified. An approved week is locked — no clocking, recording or correcting inside it.
  Overtime is flagged only above the business's own ordinary weekly hours (Timesheets →
  Ordinary weekly hours); nothing is flagged until it is set.
- **Certifications and readiness**: a position lists what it requires; certificates are
  recorded per person (issuer, issued, expiry, "I've seen the original" verification).
  Readiness lists, worst first, who is missing, expired or lapsing within 30 days, matching
  requirement and certificate names case-insensitively. `hr:certification-reminders` (daily
  06:30) tells the business admins and the person once per certificate.
- **Local time**: the app runs on UTC, but rosters are typed as wall-clock times, so clock-ins,
  "today" and week boundaries read `HR_LOCAL_TIMEZONE` (default Africa/Johannesburg). Real
  instants (submitted, approved, verified) stay UTC.

Not built in Phase 6a (stated): shift swaps and open-shift claiming; location, geofence or
biometric attendance (deliberately not collected); overtime or pay calculation (Dot.HR flags
time, it does not run payroll); a per-business timezone (one zone per deployment for now);
contractors and performance/training reviews (Phase 6b).

## Phase 6b delivered (contractors and development)
- **Contractors** (`/contractors`, HR administrators only): an engagement sits on a person
  record whose employment type is "Contractor" (a new contractor can be added from the same
  form; employees cannot be engaged). Each engagement has a kind, scope, rate (shown to HR
  administrators and the contractor only — never on the employee record), dates, milestones
  the contractor marks delivered from My work and HR approves or sends back with a note, and
  invoice records (received → approved → paid, or disputed with a reason; Dot.HR moves no
  money). Renew moves the end date later; ending records a reason category.
  `hr:engagement-reminders` (daily 06:35) tells HR administrators once, 30 days ahead.
- **Classification, honestly**: every engagement shows a notice that the label does not decide
  employment status (default text describes section 200A of the South African Labour
  Relations Act; `HR_CLASSIFICATION_NOTICE` replaces it for another jurisdiction) and lists
  facts worth a second look — renewed twice or more, running a year or more, scheduled on the
  staff roster in the last 90 days. Signals, never a determination.
- **Written reviews** (`/reviews`): HR administrators run cycles and choose a reviewer per
  person. The person writes a reflection, the reviewer writes feedback and shares it, the
  person reads it and may answer. No scores, ratings or ranking anywhere, in keeping with
  "without ranking anyone". The three texts are encrypted at rest; only the person, the
  reviewer and HR administrators can open a review, and an administrator reading someone
  else's review is audited. Nobody reviews themselves; someone without an account goes
  straight to the reviewer.
- **Goals**: performance or development goals with a plain status (not started, on track,
  needs help, done, dropped), kept by the person, their reviewer or HR.
- **Training** (`/training`, administrators and supervisors): people ask from My work;
  someone other than the person approves or declines with a reason; booked training is
  completed with hours, and when it carries a certificate that certificate goes on file —
  closing the readiness gap from Phase 6a. Readiness gaps link straight to a pre-filled
  booking.

Not built in Phase 6b (stated): performance improvement plans and disciplinary process (they
need their own fair-process and confidentiality design, like grievances); 360 or peer
feedback; competency matrices and learning paths; training costs and budgets; contract
documents (no Dot.Files client yet); contractor self-registration or invoice upload; payment
of invoices.

## Phase 7 delivered (explainable intelligence)
Phase 7 is deliberately rule-based: every suggestion is a comparison anyone can check, built
from records Dot.HR already holds. No language model is involved and nothing is inferred.

- **Skills evidence**: what the business can honestly say a person knows, each item with its
  source — the person's own profile skills (only where their chosen profile visibility lets
  the viewer read the profile), certificates on file that have not expired, and training
  recorded as completed. No CV reading, no guessing from titles, nothing about who the person is.
- **Already working here** (on every vacancy with skills): current employees (not
  contractors, not people who already applied) who hold some of what the role lists, in
  name order — never ranked — with each matched skill, its evidence and the missing ones. A
  recruiter can tell the person about the role once (in-app notification, audited) or plan
  training for a missing skill. The person decides whether to apply.
- **Training suggestions** (Training page): people who hold at least half of what an open
  role asks for, with what they are missing, linked to a pre-filled booking.
- **Insights** (`/insights`, HR administrators): headcount by type, joiners, leavers and
  turnover (leavers ÷ average of headcount now and a year ago, with a small-team caveat);
  skills the business needs (open-role skills and position requirements) with who holds each
  and how we know, fewest holders first, flagging "nobody" and "one person"; open roles,
  applications, hires, median days to hire, where last year's applications are now, and a
  per-role table; approved leave in working days per month and by type; training, certificate
  and contractor figures. Each figure states how it is calculated; "—" where there is no data.

Not built in Phase 7 (stated): AI writing assistance (job-description improvement, interview
questions) and CV extraction — Dot.HR has no model client yet, the key is not set on the host,
and sending business or candidate text to a third-party model needs an owner decision (POPIA);
cost-per-hire (no reliable cost data); workforce planning forecasts (nothing honest to
forecast from yet); exports of the insights.

## Phase 8 delivered (enterprise readiness)
- **Readiness**: `GET /ready` for uptime monitors and Dot.Guardian — database (with pending
  migrations), cache, storage and a scheduler heartbeat (`hr:heartbeat` every five minutes;
  stale after 15). 200 or 503, checks and ages only.
- **Retention** (POPIA s14): `hr:retention` daily — ended applications after a year, time
  records after three years (BCEA s31), read notifications after 180 days; configurable,
  `--dry-run`, one audit entry with counts, refuses periods under 30 days.
- **Access requests** (POPIA s23): one-file JSON exports — the person's own (My work), the
  person's career space (profile and applications), and the HR copy for a formal request
  (includes unshared review feedback and employer notes, for HR to decide on release). Audited.
- **Restore drill hook**: `hr:verify-restore [--connection=]` proves a restored copy works with
  this code and this APP_KEY — migrations, row totals, and sampled encrypted values decrypting.
- **Request ceiling**: 240 a minute per signed-in person on every authenticated route.
- **Performance**: `Model::preventLazyLoading` outside production; the suite found exactly one
  N+1 pattern (looping members to find admins, in five places) — replaced by
  `Team::administrators()` (one query). Every main page loaded clean on the dev server.
- **Runbook**: `docs/operations.md` — monitoring, schedule, retention, access requests,
  restore drill, limits.

Owner steps (cannot be done from code): point the uptime monitor at `/ready`; run the restore
drill once against a scratch database; keep `APP_KEY` with the backups offline; confirm the
retention periods with an adviser.

## R-32 status after Phase 8
All eight phases are built, tested and documented. Still stated as not built, each for a
reason recorded above: employment documents and contracts (no Dot.Files client), disciplinary
and grievance cases, performance improvement plans, 360 feedback, competency matrices and
learning paths, shift swaps, payroll and pay calculation, a per-business timezone, AI writing
assistance and CV extraction (owner decision on sending text to a model), cost-per-hire,
forecasts and exports of insights, video interviews and calendar sync, e-signatures.

## Follow-up: employment documents and policies (2026-11-02)
Builds the first item stated as not built after Phase 8. Documents on a person's record
(contract, amendment, offer letter, qualification, identity copy, letter, other), shared with the
employee on purpose; acceptance by typed name (an electronic acceptance, stated as not an
advanced electronic signature); company policies everyone can open, with "confirm you have read
it" and a count for HR; employees hand documents in from My work; everything encrypted at rest,
type-checked by content, integrity-checked on read, audited; listed in personal-data exports.
**Decision:** kept in Dot.HR's private storage, not Dot.Files — Files lets every company member
open every company file, which would expose pay and identity documents. Still not built:
e-signatures (no provider), document templates and generation, document expiry reminders for
permits (certifications already remind), moving documents to Dot.Files once it can restrict them.

## Follow-up: enforced browser security policy (2026-11-01)
Closes the Phase 2 note "CSP report-only, enforce later". The policy is now enforced with a
per-request nonce, `script-src-attr 'none'`, `form-action`, violation reporting to `/csp-report`
and a report-only kill-switch; HSTS is on by default (180 days, no subdomains). Seventeen inline
`onsubmit`/`onclick`/`onchange` handlers became data attributes handled in `app.js`. Still a
stack requirement: `'unsafe-eval'` (Livewire's Alpine) and `'unsafe-inline'` styles.

## Follow-up: employee relations cases (2026-11-01)
Builds brief §15's grievances, disciplinary case management, investigations, confidential case
notes and workplace incident records: `HrCase` + append-only `HrCaseEntry`, encrypted at rest;
a new *employee relations* grant for handlers who are not HR administrators; restricted cases
for matters involving HR; hearing notices, outcomes with warning expiry, appeals, withdrawals;
the person's side under My work (lodge a grievance, confirm a notice, reply, appeal); cases and
live warnings on the employee record; personal-data exports; retention. Still not built:
attaching documents to a case (file evidence as an employment document for now), performance
improvement plans as their own flow, exit interviews, case templates per business, statutory
forms (the Compensation Fund's own report forms are filed outside Dot.HR).

## Follow-up: performance improvement plans (2026-11-01)
Builds the PIP item stated as not built since Phase 6b, in the shape LRA Schedule 8 (items 8
and 9) gives poor-performance processes: concern, standard, required support, a reasonable
period, measurable objectives (met or not met, no scores), regular check-ins the person can
comment on, acknowledgement that is not agreement, extensions on the record, and an outcome
that points to an incapacity enquiry rather than acting as a sanction. HR administrators and a
named supporting manager only; optional link to an incapacity case and to planned training.

## Follow-up: leaving well (2026-11-01)
Completes brief §15's resignation workflows, exit interviews and final documentation alongside
the Phase 5 offboarding checklist (asset returns, access removal, final pay): written notice
with the BCEA s37 minimum worked out, acknowledgement (not approval) that starts offboarding,
withdrawal only by agreement, voluntary encrypted exit interviews with anonymous patterns only
above five consenting answers, and the BCEA s42 certificate of service with the reason only on
request. Still not built: final pay calculation (no payroll), UIF forms (UI-19), and employment
history letters beyond the certificate.

## Follow-up: shift swaps (2026-11-01)
Builds the shift-swap item stated as not built since Phase 6a: hand a shift to a named
colleague or to anyone, or exchange it for a colleague's shift; colleague accepts, supervisor
approves; the schedule changes only on approval, through Workforce::assign in one transaction;
unsettled swaps expire when their shift starts. Still not built: shift bidding on unstaffed
shifts, availability preferences.

## Follow-up: each business's time zone (2026-11-01)
Builds the per-business time zone stated as not built since Phase 6a: `teams.timezone`, set by
HR administrators on the Schedule page, read by `LocalTime` for the signed-in person's business
(default `HR_LOCAL_TIMEZONE`), with `LocalTime::forTeam()` for work that runs with nobody signed
in. Stored wall-clock times are not converted.

## Follow-up: insights exports (2026-11-01)
Builds the "exports of insights" item stated as not built after Phase 7: one tidy CSV
(Section, Item, Measure, Value, Period) for the whole page or one section, HR administrators
only, audited, formula-safe. Still not built: forecasts, scheduled exports.

## Follow-up: candidate search and bulk moves (2026-11-01)
Builds two hiring items stated as not built since Phase 4: search across the business's own
applicants (name, email, headline, place, skill; by role and open/decided) and bulk stage moves
on a role's pipeline under the single-move rules, with an optional rejection message. Still not
built: per-business pipeline stages, saved searches, cost-per-hire.

## Follow-up: competencies and 360 feedback (2026-11-01)
Builds competency assessments, competency matrices, skills gaps and continuous feedback (brief
§10), stated as not built since Phase 6b, without scores: three written levels per competency,
a position matrix, self-placement with evidence and an agreed level, gaps shown as room to grow
with training linked; 360 rounds of 3 to 8 colleagues with words shown to the person without
names and only above an anonymity floor of three. Still not built: learning paths, competency
history over time (only the current agreed level is kept; changes are on the audit log).

## Follow-up: training costs and budgets (2026-11-01)
Builds "training costs and budgets", stated as not built since Phase 6b: cost, currency and
payer (business, person, sponsor) on training records; budgets per period for the business or a
department with booked and spent apart, business-paid only, uncosted training shown not zeroed;
a completed-training CSV for any period. Still not built: SETA submission forms themselves.
