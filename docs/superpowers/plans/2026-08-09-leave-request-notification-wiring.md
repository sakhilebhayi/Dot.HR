# Leave Request Notification Wiring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `App\Notifications\LeaveRequestSubmittedNotification` fire automatically for every
admin/owner of a leave request's team whenever a `LeaveRequest` is created, via an Eloquent model
observer.

**Architecture:** A new `App\Observers\LeaveRequestObserver`, registered in
`App\Providers\AppServiceProvider::boot()`, listens for `LeaveRequest::created`. It filters the
team's `allUsers()` down to admins/owner and sends the existing, unmodified notification to each.

**Tech Stack:** Laravel (per repo's existing conventions), PHPUnit, Jetstream Teams. Real role
vocabulary (confirmed in `app/Providers/JetstreamServiceProvider.php`): exactly two roles,
`admin` and `editor`.

## Global Constraints

- Recipients are team admins/owner only — `$user->ownsTeam($team) || $user->hasTeamRole($team,
  'admin')` — not every team member. Spec §Design; this is the key difference from the earlier
  Dot.Farms observer, which notified everyone.
- No exclusion for the requester, even if they are themselves an admin — spec §Design, explicitly
  rejected as scope creep.
- The observer must fire regardless of entry point (factory, seeder, controller).
- `LeaveRequestSubmittedNotification` itself is not modified — only its class docblock.
- No new tables, no scheduling, no new UI, no mail/broadcast channels — `via()` stays
  `['database']` only.
- `tests/Feature/NotificationBellTest.php`'s two `LeaveRequest`-creating tests must have their
  now-redundant manual `$user->notify(...)` calls removed, per the spec's documented self-review
  finding — otherwise they will double-count notifications once the observer exists.

---

## Task 1: `LeaveRequestObserver` + registration + docblock + fix pre-existing test collision

**Files:**
- Create: `app/Observers/LeaveRequestObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Notifications/LeaveRequestSubmittedNotification.php` (docblock only)
- Modify: `tests/Feature/NotificationBellTest.php`
- Test: `tests/Feature/LeaveRequestObserverTest.php`

**Interfaces:**
- Consumes: `App\Models\LeaveRequest` (existing, `team()` relation), `App\Models\Team`
  (existing, Jetstream's `allUsers()`), `App\Models\User` (existing, `ownsTeam(Team $team): bool`
  and `hasTeamRole(Team $team, string $role): bool`, both Jetstream-provided),
  `App\Notifications\LeaveRequestSubmittedNotification` (existing, unmodified constructor
  `__construct(public LeaveRequest $leaveRequest)`).
- Produces: `App\Observers\LeaveRequestObserver::created(LeaveRequest $leaveRequest): void`,
  registered against `LeaveRequest` in `AppServiceProvider::boot()`. Task 2 relies on this firing
  automatically through the real controller request path.

- [ ] **Step 1: Write the failing observer test**

Create `tests/Feature/LeaveRequestObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_leave_request_notifies_admins_but_not_editors(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $admin = User::factory()->create();
        $team->users()->attach($admin, ['role' => 'admin']);

        $editor = User::factory()->create();
        $team->users()->attach($editor, ['role' => 'editor']);

        $this->createLeaveRequest($team->id, $owner->id);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $editor->fresh()->unreadNotifications()->count());
    }

    public function test_a_personal_team_with_one_admin_gets_exactly_one_notification(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_a_member_of_a_different_team_is_not_notified(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherTeamUser = User::factory()->withPersonalTeam()->create();

        $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $this->assertSame(0, $otherTeamUser->fresh()->unreadNotifications()->count());
    }

    public function test_the_notification_payload_references_the_created_leave_request(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $leaveRequest = $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $notification = $owner->fresh()->notifications()->first();

        $this->assertSame('leave_request_submitted', $notification->data['type']);
        $this->assertSame($leaveRequest->id, $notification->data['leave_request_id']);
    }

    private function createLeaveRequest(int $teamId, int $requestedBy): LeaveRequest
    {
        $employee = Employee::factory()->create(['team_id' => $teamId]);

        return LeaveRequest::factory()->create([
            'team_id' => $teamId,
            'employee_id' => $employee->id,
            'requested_by' => $requestedBy,
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LeaveRequestObserverTest.php`
Expected: FAIL — `assertDatabaseCount('notifications', 2)` sees `0` (nothing dispatches yet).

- [ ] **Step 3: Write the observer**

Create `app/Observers/LeaveRequestObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\LeaveRequest;
use App\Notifications\LeaveRequestSubmittedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Fires LeaveRequestSubmittedNotification for every admin/owner of the
 * leave request's team, on every LeaveRequest creation regardless of
 * entry point (controller, factory, seeder, or a future API) — see
 * docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md.
 *
 * Recipients are admins/owner only, not every team member — this
 * notification exists to alert approvers, matching
 * LeaveRequestPolicy::isTeamAdmin()'s exact same predicate.
 */
class LeaveRequestObserver
{
    public function created(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing('team');

        $team = $leaveRequest->team;

        $approvers = $team->allUsers()->filter(
            fn ($user) => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin')
        );

        Notification::send($approvers, new LeaveRequestSubmittedNotification($leaveRequest));
    }
}
```

- [ ] **Step 4: Register the observer**

Replace the full contents of `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Observers\LeaveRequestObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LeaveRequest::observe(LeaveRequestObserver::class);
    }
}
```

(If `app/Providers/AppServiceProvider.php` already contains other registrations by the time this
task runs, add the import and the `LeaveRequest::observe(...)` line alongside them rather than
replacing the whole file — check the file's current contents first.)

- [ ] **Step 5: Update the notification's docblock**

In `app/Notifications/LeaveRequestSubmittedNotification.php`, replace the class docblock:

```php
/**
 * In-app (database channel only) notification that a leave request needs
 * review. Fired automatically for every admin/owner of the leave
 * request's team by App\Observers\LeaveRequestObserver, registered
 * against LeaveRequest in AppServiceProvider::boot() — recipients are
 * approvers only (LeaveRequestPolicy::isTeamAdmin()'s same predicate),
 * not every team member.
 */
```

- [ ] **Step 6: Run the observer test — expect it to pass**

Run: `php artisan test --compact tests/Feature/LeaveRequestObserverTest.php`
Expected: 4 passed.

- [ ] **Step 7: Fix the now-redundant manual `->notify()` calls in `NotificationBellTest`**

`app/Providers/AppServiceProvider.php`'s new `boot()` means creating a `LeaveRequest` inside
`tests/Feature/NotificationBellTest.php` now already notifies the team owner (who satisfies
`ownsTeam($team)`). Both `test_unread_count_reflects_database_notifications` and
`test_mark_all_as_read_clears_unread_count` then call `$user->notify(new
LeaveRequestSubmittedNotification($leaveRequest))` a second time, which will now double-count.
Read the current file, then remove the now-redundant manual notify call from each:

In `test_unread_count_reflects_database_notifications`, remove this line:

```php
        $user->notify(new LeaveRequestSubmittedNotification($leaveRequest));
```

so the test body reads:

```php
    public function test_unread_count_reflects_database_notifications(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $employee = Employee::factory()->create(['team_id' => $team->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $team->id,
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
        ]);

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('Leave request pending');

        $this->assertEquals(1, $user->fresh()->unreadNotifications()->count());
    }
```

In `test_mark_all_as_read_clears_unread_count`, remove the same line so the test body reads:

```php
    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $employee = Employee::factory()->create(['team_id' => $team->id]);
        LeaveRequest::factory()->create([
            'team_id' => $team->id,
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }
```

The variable `$leaveRequest` is unused in this second test once the manual `->notify()` call is
gone, and `LeaveRequest::factory()->create([...])`'s return value is no longer assigned — this is
intentional (see the code above, which drops the assignment). The
`use App\Notifications\LeaveRequestSubmittedNotification;` import at the top of the file is now
unused after both edits — remove that import line too.

- [ ] **Step 8: Run both test files together to confirm the fix**

Run: `php artisan test --compact tests/Feature/LeaveRequestObserverTest.php tests/Feature/NotificationBellTest.php`
Expected: 7 passed (4 from `LeaveRequestObserverTest`, 3 from `NotificationBellTest`).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Observers/LeaveRequestObserver.php app/Providers/AppServiceProvider.php app/Notifications/LeaveRequestSubmittedNotification.php tests/Feature/LeaveRequestObserverTest.php tests/Feature/NotificationBellTest.php
git commit -m "feat(hr): auto-fire LeaveRequestSubmittedNotification for team admins via a model observer"
```

---

## Task 2: Integration coverage through the real controller flow + full regression

**Files:**
- Test: `tests/Feature/LeaveRequestControllerNotificationTest.php`

**Interfaces:**
- Consumes: `App\Http\Controllers\LeaveRequestController::store()` (existing, unmodified),
  `App\Observers\LeaveRequestObserver` (Task 1), named route `leave-requests.store` (from
  `Route::resource('leave-requests', LeaveRequestController::class)` in `routes/web.php`).

- [ ] **Step 1: Write the failing integration test**

Create `tests/Feature/LeaveRequestControllerNotificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The observer in App\Observers\LeaveRequestObserver must fire through
 * the real HTTP request path, not just via direct model/factory creation
 * — see docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md.
 *
 * Submitting a leave request is itself restricted to team admins
 * (LeaveRequestPolicy::create()), so the acting user in this test is an
 * admin — and per the spec's explicit design decision, that admin is
 * still notified alongside every other admin, with no self-exclusion.
 */
class LeaveRequestControllerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_leave_request_through_the_controller_notifies_admins_only(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $secondAdmin = User::factory()->create();
        $team->users()->attach($secondAdmin, ['role' => 'admin']);

        $editor = User::factory()->create();
        $team->users()->attach($editor, ['role' => 'editor']);

        $employee = Employee::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner)->post(route('leave-requests.store'), [
            'employee_id' => $employee->id,
            'type' => 'annual',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $secondAdmin->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $editor->fresh()->unreadNotifications()->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/LeaveRequestControllerNotificationTest.php`
Expected: 1 passed. (The observer from Task 1 already makes this pass — this test exists purely
to lock in that the real HTTP path is covered and that the admin-only recipient scope holds
through it, per the spec's Testing Notes, not to drive new implementation.)

If it fails, re-check that `App\Providers\AppServiceProvider::boot()` from Task 1 is registered
and that `LeaveRequestPolicy::create()` (already existing, unmodified) still permits the acting
user — `$owner` satisfies `isTeamAdmin()` via `ownsTeam()`.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/LeaveRequestControllerNotificationTest.php
git commit -m "test(hr): cover leave-request notification fan-out through the real controller path"
```

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test --compact`
Expected: every pre-existing test still passes, plus the 5 new tests from this plan (4 in
`LeaveRequestObserverTest`, 1 in `LeaveRequestControllerNotificationTest`), plus
`NotificationBellTest`'s 3 tests still passing with their Task-1 edits.

- [ ] **Step 5: Confirm no leftover references to the old manual-dispatch pattern**

Run: `grep -rn "user->notify(new LeaveRequestSubmittedNotification" app tests`
Expected: no matches — Task 1 removed the only two call sites, and the notification is now only
ever constructed by the observer.

- [ ] **Step 6: Report**

Summarize the final test count and confirm this plan's scope (Task 1-2) is fully implemented
against `docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md`. No
further commits in this step — Step 4's regression run and Step 5's grep are verification only.
