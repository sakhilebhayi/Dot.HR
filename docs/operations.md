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
