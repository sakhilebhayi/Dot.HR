<div align="center">

<img src="public/images/logo.png" alt="Dot.HR" width="140" />

<br /><br />

**Employment records, roles, and leave — the Dot Ecosystem's workforce platform.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the Dot Ecosystem** &nbsp;·&nbsp; `hr.infodot.app`

</div>

---

## Status

Runs and is tested: `php artisan test` (SQLite in memory) covers the policies, the leave rules, the audit
trail, retention and the data migration, and the same suite and migrations were run against PostgreSQL.
It has had no independent security review and no legal review. **Nothing here is a claim of POPIA or GDPR
compliance**: those depend on how your organisation uses it. What this repository does and does not do
with personal data is stated plainly in [Personal data](#personal-data) below.

## What is Dot.HR?

Dot.HR is the Dot Ecosystem's workforce management platform: employment records, roles, and leave
requests, team-scoped the same way every other Jetstream Teams platform in the ecosystem is. Per the
platform's own design principle ("work, not workers" - see `wiki.md` section 2), Dot.HR keeps
individual-level employment data platform-internal; nothing here leaves the platform at the individual
level.

## Domain

| Model | Purpose |
|---|---|
| **Position** | A job/role definition: describes work, not a worker. Managed at `/positions`, where admins also assign people. |
| **Employee** | An employment record: name, work email, work phone, position, employment type, status, start/end date, and an optional link to the person's sign-in account. Soft-deleted. |
| **LeaveType** | A kind of leave a team offers: days a year, how much carries over, paid or not, and whether a request beyond the balance is refused. Managed at `/leave-types`. |
| **LeaveRequest** | A leave request: type, dates, optional half day, optional reason, `pending` then `approved` / `denied`, or `cancelled` by the employee. Soft-deleted. |
| **HrAuditLog** | Append-only record of who looked at, changed or removed HR records. Admin-only page at `/audit-log`. |

Out of scope (see `wiki.md` roadmap): payroll, benefits, performance reviews, and individual productivity
scoring (deliberately never planned, `wiki.md` section 8).

## Who can see and do what

Defined once, in `app/Support/Hr/HrAccess.php`, used by every policy; the UI hides what you cannot use and the
server refuses it anyway (403, or 404 across teams).

| | HR admin (team owner or Jetstream `admin`) | Manager (`editor`) | Member linked to an employee record ("self") | Member, not linked |
|---|---|---|---|---|
| Employee list | everyone | everyone, basics only | their own row | none |
| Contact details, dates, leave history | yes | **no** | their own | none |
| Leave requests | all, with reasons | dates and status only, **no reason, no type** | their own | none |
| Request leave | for anyone (logged) | only if also linked | for themselves | no |
| Cancel a pending request | no | no | their own | no |
| Approve / deny | yes, **never their own** | no | no | no |
| Create, edit, remove, restore employees; link accounts; positions; leave types | yes | no | no | no |
| Audit log | yes | no | no | no |

- A person is "linked" when an admin links them on the employee page ("Link to account"), or automatically when
  they sign in (any way, including InfoDot) with a **verified** email that matches the employee's email, and
  they belong to that team. An unverified address never links.
- Jetstream roles: `admin` = HR administrator, `editor` = manager, `viewer` = member. Anyone InfoDot lists
  without an admin or editor role lands on `viewer`, so people are not silently made managers.
- Nobody decides their own request. That includes an admin deciding leave filed by or for themselves. A team with
  one admin therefore needs a second admin for that admin's own leave. This is deliberate and not overridable.
- Looking at someone else's employee record or leave request is itself written to the audit log (the access
  event, and whether the reason was in view, never the content).

## Leave rules

- **Working days** exclude weekends and public holidays (`config/hr.php`: `HR_WEEKEND_DAYS`, default `6,7`;
  `HR_PUBLIC_HOLIDAYS`, a comma-separated list of `Y-m-d`, default none: no holiday calendar is bundled).
  A half day (morning or afternoon) is allowed on a single day and counts 0.5. Nothing about the count is
  stored, so changing the config changes how past requests count.
- **Dates** are calendar dates in the app's timezone (`APP_TIMEZONE`, UTC unless set), so "today" and "on leave" roll over at midnight in that zone.
- **Overlap**: a request may not overlap the employee's own pending or approved leave (the morning and afternoon
  of one day may be requested separately).
- **Balance**: for a leave type with days a year set, balance = entitlement (days a year + carry-over) minus
  approved working days in the leave year (`HR_LEAVE_YEAR_START_MONTH`, default 1). Carry-over is the smaller of
  the type's cap and last year's unused days. A new request is checked against what is left after pending
  requests too, and refused if the type enforces its balance. Unpaid and untracked types are not limited.
- **Decision**: only a pending request can be decided, inside a transaction with a row lock, so a decision
  cannot be flipped or raced. Approval re-checks the balance. "On leave" is not a stored status: an employee
  shows as on leave only while approved leave covers today.

## Personal data

What is stored, and where:

| Data | Where | Protection |
|---|---|---|
| Employee first and last name | `employees` | plaintext column; visible to admins, managers, and the person |
| Employee work email | `employees.email` | encrypted at rest (AES-256-CBC with a MAC, key = `APP_KEY`); a keyed hash (`email_hash`) allows uniqueness per team and exact-match lookup; admin and the person only |
| Employee work phone | `employees.phone` | encrypted at rest; admin and the person only |
| Position, employment type, status, start/end date | `employees` | plaintext; dates admin and person only |
| Leave type, dates, half day, status, who requested/decided | `leave_requests` | plaintext; managers see dates and status only |
| Leave **reason** (free text, can incidentally carry health information) | `leave_requests.reason` | encrypted at rest; admin and the person only; every view by someone else is audited |
| Who did what, when, from which IP | `hr_audit_log` | field **names** and non-sensitive values only (status, dates, ids); never a reason, email, phone or name; append-only |
| Notification text for approvers | `notifications` | plaintext: employee name, leave type, dates. No reason. |
| Sign-in accounts (name, email, password hash) | `users` | Laravel/Fortify defaults |

Not stored: national ID or passport numbers, salary, medical or disability data, disciplinary history. If any
are ever added they need their own review first.

What "encrypted at rest" does and does not do: it protects the database, its dumps and backups. It does not
protect against someone with the application running and `APP_KEY`, and rotating `APP_KEY` makes existing
values unreadable unless re-encrypted first (then run `php artisan hr:protect-data --reindex`). Existing
plaintext rows are encrypted by the migration, and `php artisan hr:protect-data` is safe to re-run.

**Retention.** Removing an employee or a leave request is a soft delete: it disappears from the lists, an admin
can restore an employee (Employees, Removed), and the leave history stays with it. `php artisan hr:purge-expired`
(scheduled daily, so the server needs the Laravel scheduler cron entry) permanently deletes rows soft-deleted
more than `HR_RETENTION_DAYS` days ago (**default 1095, three years**) and writes an audit entry for each.
The default is a starting point, not legal advice: set it to what your own obligations require. Deleting a whole
team deletes its HR rows immediately. The audit log is never purged by this command, holds no names or contact
details, and has no foreign keys, so it outlives the records it describes: decide your own policy for it.

**Known limits.** The audit log's append-only guarantee is enforced by the model; someone with database write
access can still change it (restrict `UPDATE`/`DELETE` on `hr_audit_log` for the app's database role if you need
more). Client IPs are as Laravel resolves them, so behind a proxy configure trusted proxies. Backups hold the
same encrypted data for as long as you keep them. There is no data-subject export or erasure workflow yet.

## Scheduler

`hr:purge-expired` runs from Laravel's scheduler. Make sure the host runs `php artisan schedule:run` every
minute (cron), or run `php artisan hr:purge-expired` yourself (`--dry-run` shows what would go).

## Authorization

Every sensitive model ships with a policy: `EmployeePolicy`, `LeaveRequestPolicy`, `PositionPolicy`,
`LeaveTypePolicy`, `HrAuditLogPolicy`, all reading roles from `HrAccess`. `HasTeamScope` scopes every query to
the signed-in user's current team and **fails closed**: no team, a team they do not belong to, or nobody signed in
returns no rows. Console and queue code that really must cross teams (the purge, the email link on sign-in) opts
out explicitly with `Model::withoutTeamScope(fn () => ...)`. Regression tests: `tests/Feature/HrAuthorizationTest.php`,
`tests/Feature/Hr/*`.

## Email through Dot.Notify

Dot.HR tells [Dot.Notify](https://github.com/sakhilebhayi/Dot.Notify) about two leave events so the company can email them. Notify decides whether anything is sent; Dot.HR only reports what happened (`App\Services\Notify\NotifyClient`, `POST /api/ingest/events`).

| Event | Sent to | When | Data sent |
|---|---|---|---|
| `leave.requested` | each approver (owner and admins of the team), the same people who get the in-app notice | a leave request is created (`LeaveRequestObserver`) | `employee_name`, `leave_type`, `start_date`, `end_date`, `days`, `url` |
| `leave.decided` | the employee, only when their record is linked to a user account that has an email | a request is approved or denied (`DecideLeaveRequest`; a refused or repeated decision sends nothing) | `leave_type`, `start_date`, `end_date`, `decision`, `decided_by`, `url` |

`url` is the absolute `https` address of the request in this app. **Leave is person-sensitive, so the payload is deliberately minimal: never the reason (it can hold health information), contact details or balances.** An email address travels only in `recipient.email`, and log lines contain no addresses or names.

- **Nothing is sent until `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` are both set**, and even then Notify sends nothing until the company has enabled the event (an active rule for it) in Dot.Notify. `NOTIFY_INGEST_SECRET` must equal `NOTIFY_INGEST_SECRET_<PLATFORM KEY>` in Notify's `.env`; `NOTIFY_PLATFORM_KEY` defaults to `hr`.
- **A queue worker is required** (`php artisan queue:work`). Events are queued after the database transaction commits, never sent during the user's request; with `QUEUE_CONNECTION=sync` they would run inline, so use a real queue in production.
- A team is only addressed once it is linked to InfoDot (`teams.infodot_team_uuid`), and a person only if they have an email address; otherwise the event is skipped.
- Notify can never break or slow a leave action: 5 second timeout, 3 tries with backoff (60 s, then 5 min), every failure is caught and logged, and after 5 failures in a row calls pause for 60 seconds (a cache-based circuit breaker; the in-app notification is written by its own code and is unaffected).
- Requests are signed with HMAC-SHA256 over `"{timestamp}.{raw body}"` (`X-InfoDot-Timestamp`, `X-InfoDot-Signature`). The idempotency key is `{event}:{leave request id}:{sha1 of the recipient email}:{bucket}` (bucket: the start date for a request, the decision for a decision), so retries and double dispatches collapse in Notify.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.3+ |
| Frontend | Livewire 3 - Alpine.js 3 - Tailwind CSS |
| Database | PostgreSQL (shared ecosystem instance) |
| Auth | Laravel Sanctum (ecosystem SSO) + Jetstream/Fortify (teams, 2FA) |
| Queue | Database queue driver |

## Quick Start

```bash
git clone <this-repo>
cd Dot.HR
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan db:seed   # optional: fictional demo data
php artisan serve
```

### Running Tests

```bash
php artisan test
```

Feature tests use SQLite in memory per `phpunit.xml`; no Postgres instance is required.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `HR_RETENTION_DAYS` | `1095` | days a soft-deleted employee or leave request is kept before the purge removes it |
| `HR_WEEKEND_DAYS` | `6,7` | ISO weekdays (1 = Mon) that do not count as leave days |
| `HR_PUBLIC_HOLIDAYS` | none | comma-separated `Y-m-d` dates that do not count |
| `HR_LEAVE_YEAR_START_MONTH` | `1` | month the leave year starts |
| `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`, `NOTIFY_PLATFORM_KEY` | none, none, `hr` | Dot.Notify ingest endpoint, this app's signing secret and its platform key; blank URL or secret disables email (see above) |

Leave types (days a year, carry-over, enforcement) are edited in the app at `/leave-types`.

## Ecosystem

Dot.HR is one platform in the Dot Ecosystem, unified by [Dot.Brain](../Dot.Brain), the ecosystem's
shared knowledge repository. See `wiki.md` for this platform's own architecture doc, and
[Dot.Brain's ingested view](../Dot.Brain/platforms/dot-hr.md) for integration mechanics.

## License

See `LICENSE`.
