<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\HrGrant;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Shift swaps (brief §8). A person offers their shift to a named colleague or to anyone, or
 * offers an exchange for a colleague's shift; the colleague accepts; a supervisor approves.
 * The schedule changes only on approval, inside one transaction and through Workforce::assign,
 * so a swap can never put someone on two shifts at once or on a shift during approved leave.
 * Anything not settled before its shift starts expires.
 */
final class ShiftSwaps
{
    public function __construct(private Workforce $workforce) {}

    public function offer(Employee $from, Shift $shift, ?Employee $to, ?Shift $otherShift, ?string $note): ShiftSwap
    {
        $this->expireStale($from->team_id);

        if (! $shift->employees()->whereKey($from->id)->exists()) {
            $this->fail('shift_id', 'You can only offer a shift you are on.');
        }
        if ($shift->starts_at->lte(LocalTime::now())) {
            $this->fail('shift_id', 'That shift has already started.');
        }
        if (ShiftSwap::query()->where('shift_id', $shift->id)->where('from_employee_id', $from->id)->whereIn('status', ['offered', 'accepted'])->exists()) {
            $this->fail('shift_id', 'You have already offered this shift.');
        }
        if ($to !== null) {
            if ($to->id === $from->id || $to->team_id !== $from->team_id || $to->status === 'terminated') {
                $this->fail('to_employee_id', 'Choose a colleague who works here.');
            }
            if ($shift->employees()->whereKey($to->id)->exists()) {
                $this->fail('to_employee_id', $to->first_name.' is already on that shift.');
            }
        }
        if ($otherShift !== null) {
            if ($to === null || ! $otherShift->employees()->whereKey($to->id)->exists()) {
                $this->fail('other_shift_id', 'An exchange is for a shift your colleague is on.');
            }
            if ($otherShift->starts_at->lte(LocalTime::now()) || $otherShift->id === $shift->id) {
                $this->fail('other_shift_id', 'Choose one of their shifts that is still ahead.');
            }
        }

        $swap = new ShiftSwap;
        $swap->forceFill([
            'team_id' => $from->team_id,
            'shift_id' => $shift->id,
            'from_employee_id' => $from->id,
            'to_employee_id' => $to?->id,
            'other_shift_id' => $otherShift?->id,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            'status' => 'offered',
        ])->save();
        Audit::record('shift_swap.offered', $swap, [], ['shift_id' => $shift->id, 'exchange' => $otherShift !== null], $from->team_id);

        if ($to) {
            $this->tell($to, $otherShift ? 'A colleague asks to swap shifts' : 'A colleague offers you a shift',
                $from->first_name.' offers their shift on '.$shift->starts_at->format('D j M').', '.$shift->window().($otherShift ? ' for yours on '.$otherShift->starts_at->format('D j M').'.' : '.'));
        }

        return $swap;
    }

    /** The colleague (or, for an open offer, whoever takes it) accepts. It then waits for a supervisor. */
    public function accept(ShiftSwap $swap, Employee $taker): void
    {
        $this->expireStale($swap->team_id);
        $swap->refresh();
        $this->expect($swap, 'offered');

        if ($taker->id === $swap->from_employee_id || ($swap->to_employee_id !== null && $swap->to_employee_id !== $taker->id) || $taker->team_id !== $swap->team_id) {
            $this->fail('swap', 'This offer is not yours to accept.');
        }

        // Fail early with a plain reason; the same rules are enforced again at approval.
        $this->checkFree($taker, $swap->shift, $swap->otherShift);
        if ($swap->otherShift) {
            $this->checkFree($swap->from, $swap->otherShift, $swap->shift);
        }

        $swap->forceFill(['status' => 'accepted', 'to_employee_id' => $taker->id, 'accepted_at' => now()])->save();
        Audit::record('shift_swap.accepted', $swap, ['status'], [], $swap->team_id);

        $this->tell($swap->from, 'Your shift offer was accepted', $taker->first_name.' accepted. A supervisor approves it next.');
        Notification::send($this->supervisors($swap->team_id), new LifecycleNotification(
            'A shift swap needs approval', $swap->from->first_name.' and '.$taker->first_name.' agreed a swap.', route('schedule.swaps'), teamId: $swap->team_id,
        ));
    }

    /** The named colleague says no. */
    public function decline(ShiftSwap $swap, Employee $by): void
    {
        $this->expect($swap, 'offered');
        if ($swap->to_employee_id !== $by->id) {
            $this->fail('swap', 'This offer is not yours to decline.');
        }

        $swap->forceFill(['status' => 'declined', 'decided_at' => now()])->save();
        Audit::record('shift_swap.declined', $swap, ['status'], ['by' => 'colleague'], $swap->team_id);
        $this->tell($swap->from, 'Your shift offer was declined', $by->first_name.' cannot take your shift on '.$swap->shift->starts_at->format('D j M').'.');
    }

    /** The person who offered withdraws it, any time before a supervisor decides. */
    public function cancel(ShiftSwap $swap, Employee $by): void
    {
        if ($swap->from_employee_id !== $by->id || ! $swap->isOpen()) {
            $this->fail('swap', 'Only an offer still waiting can be withdrawn.');
        }

        $swap->forceFill(['status' => 'cancelled'])->save();
        Audit::record('shift_swap.cancelled', $swap, ['status'], [], $swap->team_id);
        if ($swap->to && $swap->accepted_at) {
            $this->tell($swap->to, 'A shift swap was withdrawn', $swap->from->first_name.' withdrew the swap you accepted.');
        }
    }

    /** A supervisor approves (the schedule changes now) or declines. */
    public function decide(ShiftSwap $swap, bool $approve, ?string $note, User $by): void
    {
        $this->expireStale($swap->team_id);
        $swap->refresh();
        $this->expect($swap, 'accepted');

        if ($approve) {
            try {
                DB::transaction(function () use ($swap, $by) {
                    $shift = $swap->shift;
                    $other = $swap->otherShift;
                    $shift->employees()->detach($swap->from_employee_id);
                    if ($other) {
                        $other->employees()->detach($swap->to_employee_id);
                    }
                    $this->workforce->assign($shift, $swap->to, $by->id);
                    if ($other) {
                        $this->workforce->assign($other, $swap->from, $by->id);
                    }
                });
            } catch (RuntimeException $e) {
                $this->fail('swap', $e->getMessage().' The schedule was left as it was.');
            }
        }

        $swap->forceFill(['status' => $approve ? 'approved' : 'declined', 'decided_at' => now(), 'decided_by' => $by->id, 'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null])->save();
        Audit::record($approve ? 'shift_swap.approved' : 'shift_swap.declined', $swap, ['status'], ['shift_id' => $swap->shift_id, 'other_shift_id' => $swap->other_shift_id], $swap->team_id);

        $message = $approve ? 'The schedule now shows the change.' : 'The schedule stays as it was.'.($swap->decision_note ? ' '.$swap->decision_note : '');
        foreach (array_filter([$swap->from, $swap->to]) as $person) {
            $this->tell($person, $approve ? 'Your shift swap was approved' : 'Your shift swap was not approved', $message);
        }
    }

    /** Offers and accepted swaps whose shifts have started can no longer happen. */
    public function expireStale(int $teamId): void
    {
        $started = Shift::query()->where('team_id', $teamId)->where('starts_at', '<=', LocalTime::now())->select('id');

        ShiftSwap::query()->where('team_id', $teamId)->whereIn('status', ['offered', 'accepted'])
            ->where(fn ($q) => $q->whereIn('shift_id', $started)->orWhereIn('other_shift_id', $started))
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /** Admins and supervisors of this business. @return Collection<int, User> */
    public function supervisors(int $teamId): Collection
    {
        $team = Team::query()->findOrFail($teamId);
        $members = DB::table('team_user')->where('team_id', $teamId);
        $ids = (clone $members)->where('role', 'admin')->pluck('user_id')
            ->merge(HrGrant::query()->where('team_id', $teamId)->where('capability', HrGrant::SUPERVISOR)->whereIn('user_id', (clone $members)->pluck('user_id'))->pluck('user_id'))
            ->push($team->user_id)->unique();

        return User::query()->whereIn('id', $ids->all())->get();
    }

    private function checkFree(Employee $employee, Shift $shift, ?Shift $givingUp): void
    {
        $clash = Shift::query()->whereKeyNot($shift->id)->when($givingUp, fn ($q) => $q->whereKeyNot($givingUp->id))
            ->whereHas('employees', fn ($q) => $q->whereKey($employee->id))
            ->where('starts_at', '<', $shift->ends_at)->where('ends_at', '>', $shift->starts_at)->exists();
        if ($clash) {
            $this->fail('swap', $employee->first_name.' is already on a shift at that time.');
        }

        $onLeave = LeaveRequest::query()->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $shift->ends_at->toDateString())->whereDate('end_date', '>=', $shift->starts_at->toDateString())->exists();
        if ($onLeave) {
            $this->fail('swap', $employee->first_name.' is on approved leave then.');
        }
    }

    private function expect(ShiftSwap $swap, string $status): void
    {
        if ($swap->status !== $status) {
            $this->fail('swap', 'This swap is '.strtolower($swap->statusLabel()).'.');
        }
    }

    private function tell(Employee $employee, string $title, string $message): void
    {
        if ($employee->user_id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification($title, $message, route('my-work.index').'#swaps', teamId: $employee->team_id));
        }
    }

    /** @return never */
    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
