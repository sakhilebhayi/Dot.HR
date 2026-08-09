# Leave Request Notification Wiring — Design Spec

**Status:** Approved by user, ready for implementation planning.
**Platform:** Dot.HR
**Date:** 2026-08-09

## Context

`Dot.Brain/platforms/dot-hr.md`'s Autonomy Classification audit (§Level 1, §Level 2, 2026-08-08)
confirmed zero automation in this codebase: no `app/Console/Commands`, no `Schedule::` entries, no
`app/Jobs`, no class implementing `ShouldQueue`. It names its own suggested Level 1 candidate as a
weekly `people.certification.expiring_cohort` aggregate-cohort scheduled command — but no
`Certification` model or concept exists anywhere in the codebase (`grep -rln "certification" app`
returns nothing), so that candidate would mean inventing an entirely new data model, not closing
an existing gap.

A second, smaller gap exists, mentioned in passing by the same audit paragraph:
`App\Notifications\LeaveRequestSubmittedNotification` is fully built (in-app database-channel
notification, rendered by the existing `NotificationBell` Livewire component) but its own docblock
states it is "not yet wired to any automatic trigger on `LeaveRequestController`'s `store()` —
dispatch manually ... until a real team-wide 'notify approvers' flow exists." This is the exact
same shape as the gap just closed in Dot.Farms (`HarvestRecordedNotification`/
`HarvestRecordObserver`).

Presented to the user as an explicit choice between the two candidates. **The user chose to wire
the leave-request notification** (the smaller, already-grounded fix, matching the Farms
precedent) over inventing the certification-cohort feature.

Verified directly against the real code before proceeding:

- `app/Console/Commands`, `app/Jobs`, `app/Observers` do not exist as directories.
- `grep -rn "user->notify(" app tests` finds exactly two hits, both inside
  `tests/Feature/NotificationBellTest.php`'s test bodies — the only two places this notification
  is ever constructed today.
- No natural Level 2 pairing exists on `LeaveRequestController::approve()`/`deny()` — the audit
  correctly notes these already *are* the human review step (a team admin reviewing another
  human's submitted request), not a system-prepared proposal awaiting further escalation.

## Goal

Wire `LeaveRequestSubmittedNotification` to fire automatically, for every admin/owner of the leave
request's team, whenever a `LeaveRequest` is created — regardless of entry point (controller,
factory, seeder, a future API), not just the one `LeaveRequestController::store()` call site that
exists today.

## Design

### `LeaveRequestObserver`

New class `App\Observers\LeaveRequestObserver`, registered via `LeaveRequest::observe(...)` in
`App\Providers\AppServiceProvider::boot()` (currently empty — no other observer exists in this
repo yet).

```php
public function created(LeaveRequest $leaveRequest): void
{
    $leaveRequest->loadMissing('team');

    $team = $leaveRequest->team;

    $approvers = $team->allUsers()->filter(
        fn ($user) => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin')
    );

    Notification::send($approvers, new LeaveRequestSubmittedNotification($leaveRequest));
}
```

- **Recipients — team admins/owner only, not every team member.** This is the key difference
  from the Farms design: a harvest is farm-wide news (everyone), but a leave request needs
  *approvers* — the notification's own docblock says so explicitly ("notify approvers"). The
  filter predicate (`$user->ownsTeam($team) || $user->hasTeamRole($team, 'admin')`) is the exact
  same boolean logic already duplicated three times in this codebase's own policies
  (`EmployeePolicy::isTeamAdmin()`, `PositionPolicy::isTeamAdmin()`,
  `LeaveRequestPolicy::isTeamAdmin()`) — reused here rather than extracted into a shared helper,
  matching the existing convention of small per-policy private methods rather than a premature
  shared abstraction.
- **No exclusion for the requester.** `LeaveRequestPolicy::create()` already restricts who can
  submit a request to team admins — meaning the person who just submitted it may themselves be in
  the recipient set and will receive their own notification. This mirrors the Farms design's own
  choice not to special-case the acting user out of the recipient list; inventing a self-exclusion
  rule nobody asked for would be scope creep past what this fix needs.
- **Why an observer, not a controller-level `->notify()` call.** Same reasoning as Farms: an
  observer fires on `LeaveRequest::create()` regardless of entry point, matching the audit's own
  suggested fix shape and avoiding the trigger silently going stale if a second creation path is
  added later without every future author remembering to call `->notify()` themselves.
- **No new tables, no scheduling, no new UI.** `NotificationBell` (`app/Livewire/
  NotificationBell.php`) already reads `auth()->user()->notifications()` and renders whatever is
  there — this change only makes sure something real lands in that table.

### Docblock cleanup

`app/Notifications/LeaveRequestSubmittedNotification.php`'s class docblock currently reads:

> Not yet wired to any automatic trigger on `LeaveRequestController`'s `store()` — dispatch
> manually via `$user->notify(new LeaveRequestSubmittedNotification($leaveRequest))` until a real
> team-wide "notify approvers" flow exists (see wiki.md §9 roadmap).

This becomes stale once the observer ships. Replace it with a note pointing at
`App\Observers\LeaveRequestObserver` as the real trigger and its actual recipient scope
(team admins/owner, not every team member).

## Out of Scope

- The certification-expiry aggregate report the audit itself suggested — a separate, larger
  feature requiring a new `Certification` data model, explicitly not chosen this pass.
- Mail or broadcast notification channels — `LeaveRequestSubmittedNotification::via()` already
  returns only `['database']`; this spec does not change that.
- Excluding the requester from their own notification — considered and explicitly rejected as
  scope creep (see Design section above).

## Testing Notes

- Creating a `LeaveRequest` (via the factory, matching this repo's existing test convention)
  results in one `notifications` row per team admin/owner — not every team member. This repo's
  real role vocabulary (`app/Providers/JetstreamServiceProvider.php::configurePermissions()`) has
  exactly two roles: `admin` and `editor`. A team with one `admin`-role member (attached via
  `$team->users()->attach($user, ['role' => 'admin'])`, the exact convention already used
  elsewhere in this repo's tests) and one `editor`-role member must notify only the admin — the
  editor gets zero notifications.
- A member of a different team is not notified.

### Correction found during spec self-review

Identical to the Farms case: `tests/Feature/NotificationBellTest.php`'s
`test_unread_count_reflects_database_notifications` and `test_mark_all_as_read_clears_unread_count`
both create a `LeaveRequest` (as the team owner, who satisfies `ownsTeam($team)` and is therefore
in the observer's recipient set) and then call `$user->notify(new
LeaveRequestSubmittedNotification($leaveRequest))` again immediately afterward. Once the observer
exists, that manual call becomes a second, redundant notification, breaking both tests'
`assertDatabaseCount('notifications', 1)` / `assertEquals(1, ...unreadNotifications()...)`
assertions (both would see 2, not 1). The implementation task must remove the now-redundant manual
`$user->notify(...)` calls from both tests — the observer firing during `LeaveRequest::create()`
already produces the one notification these tests need. `test_dashboard_renders_notification_bell_
for_authenticated_user` creates no `LeaveRequest` and needs no change.
