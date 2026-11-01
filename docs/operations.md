# Running Dot.HR — operations runbook

What keeps Dot.HR healthy in production, how to check it, and what to do when something is
wrong. Deployment itself is in `deployment.md`.

## 1. Monitoring

| Endpoint | Answers | Use it for |
|---|---|---|
| `GET /up` | 200 when PHP and Laravel boot | "is the site there at all" |
| `GET /ready` | 200 when Dot.HR can work, 503 when it cannot | uptime monitor and Dot.Guardian |

`/ready` returns JSON with four checks and nothing else (no host, no credentials, no counts of
anyone's data):

- `database` — answers a query and has no pending migrations (`pending_migrations`).
- `cache` — reads back what it wrote.
- `storage` — `storage/framework/cache` and `storage/logs` are writable.
- `scheduler` — `hr:heartbeat` ran in the last 15 minutes (`last_run_seconds_ago`). Right after
  a deploy or a cache clear it reports `null` (unknown, still 200) until the first beat.

**Owner step:** point the uptime monitor at `https://hr.infodot.co.za/ready` and alert on any
non-200. A 503 with only `scheduler.ok = false` means the cron line is missing — see
`shared-crontab-race` in the programme notes: one cPanel crontab serves every Dot app.

## 2. Scheduled jobs (all run from the single `schedule:run` cron line)

| Command | When | What it does |
|---|---|---|
| `hr:heartbeat` | every 5 minutes | proves the scheduler runs (read by `/ready`) |
| `hr:purge-expired` | daily | removes soft-deleted employees and leave after `HR_RETENTION_DAYS` |
| `hr:retention` | 03:20 | removes ended applications, old time records, old read notifications |
| `hr:publish-metrics` | 02:10 UTC | daily aggregates to Dot.Analytics (only when configured) |
| `hr:certification-reminders` | 06:30 | one warning per certificate lapsing within 30 days |
| `hr:engagement-reminders` | 06:35 | one warning per contractor engagement ending within 30 days |

## 3. Retention (POPIA s14)

Starting defaults, not legal advice — set each to what the business's obligations require.

| Setting | Default | Removes |
|---|---|---|
| `HR_RETENTION_DAYS` | 1095 | soft-deleted employee records and leave |
| `HR_CANDIDATE_RETENTION_DAYS` | 365 | applications that ended without a hire, with notes, history, interviews, offers |
| `HR_TIME_RECORD_RETENTION_DAYS` | 1095 | attendance, timesheets, shifts (BCEA s31 asks for three years) |
| `HR_NOTIFICATION_RETENTION_DAYS` | 180 | in-app notifications already read |

Both commands accept `--dry-run`. Each run writes one audit entry with counts only. The audit
log itself is never purged. `hr:retention` refuses to run with any period under 30 days.

## 4. Access requests (POPIA s23)

- A person downloads what the business holds about them from **My work → Download my data**,
  and what they keep themselves from **My career → Profile → Your data**.
- An HR administrator answers a formal request from the employee page: **Personal data**. The
  HR copy also holds unshared review feedback and employer-only hiring notes; decide what to
  release before sending it on.
- Every export is audited (`privacy.exported`, by self or by HR) — never its contents.

## 5. Restore drill

Backups of the Dot.HR database are taken by the fleet backup tool (InfoDot `tools/`, nightly,
pulled off-host). A backup is only proven by restoring it. Once a quarter:

1. Restore the latest Dot.HR dump into a scratch database in cPanel (never over production).
2. On the host, add a `restore` connection pointing at the scratch database (a temporary entry
   in `config/database.php` or `DB_RESTORE_*` variables — remove it afterwards).
3. Run `php artisan hr:verify-restore --connection=restore`.
4. It must end with **Restore check passed**: every migration applied, row counts that look like
   production, and every sampled encrypted value decrypting with the live `APP_KEY`.
5. Drop the scratch database. Note the date and result in the programme notes.

If the decrypt check fails, the backup and the key do not belong together. Encrypted columns
(employee contact details, leave reasons, HR requests, reviews) are unreadable without the
`APP_KEY` that was live when they were written — **the key must be backed up with the data**,
offline, in the owner's password manager.

## 6. Limits and guards

- Every signed-in request counts against a ceiling of 240 a minute per person (`throttle:hr`);
  routes that write or send keep their own tighter limits. Sign-in is 5 a minute per email and IP.
- Outside production, reading a relation inside a loop without eager loading throws
  (`Model::preventLazyLoading`), so an N+1 query fails the test suite instead of slowing a page.
  Production logs nothing extra and never throws for it.

## 7. Email notifications (Dot.Notify)

Every in-app notice can also go out as an email through Dot.Notify. Dot.HR never sends mail
itself for notices: it tells Notify "this happened at this business, tell this person", queued
after the request and never able to fail it.

| Event | Who | What the email carries |
|---|---|---|
| `leave.requested` | approvers | name, leave type, dates, days, link (never the reason) |
| `leave.decided` | the employee | decision, who decided, type, dates, link |
| `application.received` | owner and admins | role, application reference, link (never the cover note) |
| `application.updated` | the candidate | business, role, the update's headline, link |
| `hr.update` | the person told | business, the notice's title, link (never its text) |

To switch it on for the live site: set `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` in the host
`.env` (the secret must equal `NOTIFY_INGEST_SECRET_HR` in Dot.Notify), make sure the business is
linked to InfoDot (`teams.infodot_team_uuid`), and enable the events for that business on
Notify's **Connected apps** page. Nothing is sent while any of these is missing; in-app notices
work regardless.

## 8. Employment documents and policies

Contracts, offer letters, qualifications, identity copies and company policies are stored by
Dot.HR itself on the private disk (`storage/app/private/hr-documents/{team}/…`), each file
encrypted with `APP_KEY` and checked against its SHA-256 on every download. They are **not** filed
in Dot.Files: there, every member of a company can open every company file, and these documents
carry pay and identity details that the brief keeps from managers and ordinary staff. They can
move to Dot.Files once it supports documents restricted to named people.

- **Who sees what:** HR administrators (owner, admins) see every document; an employee sees what
  was shared with them, what they handed in, and live policies; managers see none of it. Every
  read of someone else's document is audited as `document.opened`.
- **Acceptance:** an employee accepts a document by typing their name as it is on their record;
  the name and time are kept. This is an electronic acceptance, not an advanced electronic
  signature.
- **Backups (owner step):** the fleet backup takes the database only, so these files are not
  backed up yet. Add `storage/app/private/hr-documents` to the nightly backup, and keep
  `APP_KEY` with it — without the key the files cannot be read.
- **Personal data exports** list a person's documents and acceptances, never the files
  themselves; send the files separately when a request asks for them.

## 9. Browser security policy

The Content-Security-Policy is **enforced** and nonce-based, the same shape InfoDot runs live:
inline scripts need the per-request nonce (`<script nonce="{{ Vite::cspNonce() }}">`), and inline
event handlers do not run at all (`script-src-attr 'none'`). In views, ask before submitting with
`data-confirm="…"` on the form or button, submit a select on change with `data-autosubmit`, and
flip the theme with `data-theme-toggle`; `resources/js/app.js` handles them. A test fails the build
if a view gains an `onclick=`-style attribute or an outside image host the policy does not allow.

- **Violations** are logged as `csp.violation` (directive and blocked host only, once a day per
  pair) through `POST /csp-report`.
- **Kill-switch:** `SECURITY_CSP_REPORT_ONLY=true` in the host `.env` (then `php artisan
  config:cache`) falls back to report-only without a deploy.
- **HSTS:** sent on HTTPS responses, 180 days, without `includeSubDomains`;
  `SECURITY_HSTS_SECONDS=0` turns it off.
- `'unsafe-eval'` stays: Livewire 3's bundled Alpine needs it. `style-src` keeps `'unsafe-inline'`
  for The Register's style attributes.

## 10. Employee relations cases

Grievances, disciplinary cases and workplace incidents live under **Cases** (`/cases`), apart
from ordinary employee records, as brief §15 asks.

- **Who sees what:** HR administrators see every case except restricted ones; a member granted
  *employee relations* (Access page) sees only the cases assigned to them; managers see none.
  A **restricted** case (for example a grievance about someone in HR; employees can choose
  "send it only to the business owner") is open only to its handler and the business owner.
  The person a case is about sees their own grievances, and other cases only once HR shares an
  entry, and then only the shared entries. The person a grievance is about is never shown it.
- **The record** is append-only and encrypted at rest (`APP_KEY`), as are the account and the
  outcome reasons. Every view and step is audited by action and field name, never the text.
- **Process prompts, not legal advice:** a hearing notice lists the allegations and the right to
  bring a fellow employee or a shop steward (LRA Schedule 8, item 4); a hearing less than two
  days away gets a caution; a dismissal outcome tells the person about the 30-day CCMA or
  bargaining council referral (LRA s191); a poor-performance case is flagged as incapacity
  (Schedule 8, items 8 and 9); a work injury shows the 7-day Compensation Fund reporting date
  (COIDA s39) until it is marked as reported.
- **Warnings** count for `HR_VERBAL_WARNING_MONTHS` (3), `HR_WRITTEN_WARNING_MONTHS` (6) and
  `HR_FINAL_WARNING_MONTHS` (12) unless the decider sets another period; an appeal that is
  upheld sets the warning aside. These defaults are common practice, not law.
- **Retention:** `hr:retention` removes closed or withdrawn cases, with their records,
  `HR_CASE_RETENTION_DAYS` (default 5 years) after closing.
- **Access requests:** the person's export holds their visible cases and shared entries; the HR
  export also holds internal entries, marked, for the business to decide what to release.
  Restricted cases are counted, not exported: the owner answers for those. A grievance that
  names someone is not in that person's export.
- Notifications name only the case reference; the email title (through Dot.Notify) never names
  a person or what the case is about.

## 11. Performance improvement plans

**Improvement plans** (`/plans`) follow the steps LRA Schedule 8 (items 8 and 9) sets for poor
work performance: say what standard is not met, give guidance, training or coaching, allow a
reasonable time, check in along the way, then decide.

- **Who sees what:** HR administrators draft and see every plan; the member named as the
  supporting manager runs it with HR; the person sees it from My work once it is shared. Other
  managers and colleagues see nothing. A draft reaches nobody but HR and the named manager.
- **The plan** holds the concern (with examples), the standard, the support (required), dates,
  a check-in cadence, and up to eight objectives, each with how it is measured. Objectives are
  marked met or not met; nobody is scored or rated, as with Reviews. A training line can be
  booked as planned training when the plan is drafted.
- **The person** confirms reading it ("this does not mean I agree"), may add a response, and
  may comment on every check-in. Every check-in and extension is shared with them.
- **Prompts:** a plan shorter than 30 days asks HR to check the period is reasonable; the next
  check-in date shows, and turns overdue; a plan that ends with the standard not met says it is
  not a sanction and points to an incapacity enquiry (an HR case, §10), linked if one exists.
- Encrypted at rest; every view and step audited; in personal-data exports (drafts only in the
  HR copy); removed by `hr:retention` with closed cases (`HR_CASE_RETENTION_DAYS`).

## 12. Leaving: resignations, exit interviews, certificates of service

**Leaving** (`/leaving`, HR administrators) adds what the offboarding checklist could not.
The checklist itself still carries handover, equipment, access removal and final pay.

- **Resignations** are given in writing from My work (BCEA s37(4)). Dot.HR works out the
  minimum notice for the person's length of service (s37(1): one week up to six months, two
  weeks up to a year, four weeks after that) and flags a last day that falls short; a shorter
  period works only by agreement. HR **acknowledges** a resignation (it is not approved or
  refused), may record an agreed last day, and acknowledging starts the offboarding checklist
  with the reason "resignation". A resignation is withdrawn only when HR agrees, which cancels
  that checklist.
- **Exit interviews** are offered (when acknowledging, or from the person's record) and are
  optional. The person answers from My work or declines; HR can instead record a conversation.
  Answers are encrypted and read by HR administrators only. Anonymous patterns (reason counts
  and "would come back") appear only from people who agreed, and only once at least five have.
- **Certificates of service** (BCEA s42): issued from the person's record with the employer's
  name and address, dates, job and the remuneration at the end (typed from payroll; Dot.HR
  holds no pay). The reason for leaving is stated only when the person asked for it. The person
  opens it from My work and prints or saves it as a PDF.
- All of it is in personal-data exports; answered or declined exit interviews leave by
  `hr:retention` after `HR_CASE_RETENTION_DAYS`.

## 13. Shift swaps

People offer their own upcoming shifts from **My work**: to a named colleague, to anyone who is
free, or as an exchange for a colleague's shift. The colleague accepts (or, for an open offer,
whoever takes it first); a supervisor or HR administrator approves under **Schedule → Shift
swaps**. Nothing on the schedule changes until approval, and approval runs the same rules as
assigning by hand (no overlapping shifts, nobody on approved leave), re-checked at that moment
inside one transaction; if anything fails, the schedule is left exactly as it was. Offers and
agreed swaps whose shift has started expire. Everyone involved is told at each step, and every
step is audited.

## 14. Each business's time zone

Rosters and attendance run on the business's own clock. HR administrators set it at the bottom
of the **Schedule** page; a business that has not set one uses `HR_LOCAL_TIMEZONE` (default
Africa/Johannesburg). The Schedule page always says which clock it shows and the time there.

- What follows the clock: clock-in and clock-out times, "today", week boundaries for
  timesheets, which shifts count as started (swaps expire), expiry and due dates.
- What does not change: shift times are stored exactly as typed (wall-clock), so setting or
  changing the zone converts nothing. Set it before the first roster, or check the roster after.
- Work with nobody signed in (scheduled commands) uses the default unless wrapped in
  `LocalTime::forTeam($team, ...)`. The daily certificate and engagement reminders look 30 days
  ahead, so a one-day difference between zones does not change who is reminded.
- Every change is audited (`business.timezone_changed`, from and to).
