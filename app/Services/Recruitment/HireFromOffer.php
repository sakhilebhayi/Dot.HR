<?php

namespace App\Services\Recruitment;

use App\Models\Application;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Position;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Support\Hr\EmailIndex;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The candidate accepts an offer: the offer, the application and a new employee record move
 * together in one transaction, and only once (brief §14: a retried action must never create
 * a duplicate employment record).
 *
 * Idempotency: the offer row is locked, and an offer that already carries an employee_id
 * returns that employee untouched. A second click, a refresh or a race all land on the
 * same record.
 */
final class HireFromOffer
{
    public function accept(Offer $offer, User $candidate): Employee
    {
        return Offer::withoutTeamScope(fn () => DB::transaction(function () use ($offer, $candidate) {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            /** @var Application $application */
            $application = Application::query()->whereKey($locked->application_id)->lockForUpdate()->firstOrFail();

            if ($application->user_id !== $candidate->id) {
                throw new RuntimeException('Only the candidate can accept their own offer.');
            }

            if ($locked->employee_id !== null) {
                /** @var Employee */
                return Employee::query()->withTrashed()->findOrFail($locked->employee_id);
            }

            if ($locked->status !== 'sent') {
                throw new RuntimeException('Only a sent offer can be accepted.');
            }

            $employee = $this->employeeFor($locked, $application, $candidate);

            $locked->forceFill([
                'status' => 'accepted',
                'responded_at' => now(),
                'employee_id' => $employee->id,
            ])->save();

            $application->forceFill(['stage' => 'hired', 'stage_changed_at' => now()])->save();
            $application->record('offer.accepted', $candidate->id, ['offer_id' => $locked->id]);
            $application->record('hired', $candidate->id, ['employee_id' => $employee->id]);

            Audit::record('recruitment.hired', $employee, [], ['offer_id' => $locked->id, 'application_id' => $application->id], $locked->team_id);

            return $employee;
        }));
    }

    private function employeeFor(Offer $offer, Application $application, User $candidate): Employee
    {
        // Someone who already works here (re-hire, second role) keeps their one record.
        $existing = Employee::query()->withTrashed()
            ->where('team_id', $offer->team_id)
            ->where('user_id', $candidate->id)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill(['status' => 'active', 'start_date' => $offer->starts_on, 'end_date' => null])->save();

            return $existing;
        }

        // HR may already hold a record for this person under their email (entered by hand,
        // not yet linked). One active employee per email per business is a hard rule, so
        // link that record instead of creating a clashing second one.
        $byEmail = Employee::query()
            ->where('team_id', $offer->team_id)
            ->where('email_hash', EmailIndex::hash($candidate->email))
            ->first();

        if ($byEmail && $byEmail->user_id === null) {
            $byEmail->forceFill([
                'user_id' => $candidate->id,
                'status' => 'active',
                'start_date' => $offer->starts_on,
                'end_date' => null,
            ])->save();

            return $byEmail;
        }

        $position = Position::query()
            ->where('team_id', $offer->team_id)
            ->whereRaw('LOWER(title) = ?', [mb_strtolower($offer->job_title)])
            ->first();

        if (! $position) {
            $position = new Position;
            $position->forceFill(['team_id' => $offer->team_id, 'title' => $offer->job_title])->save();
        }

        [$first, $last] = $this->splitName($candidate->name);

        $employee = new Employee;
        $employee->forceFill([
            'team_id' => $offer->team_id,
            'position_id' => $position->id,
            'user_id' => $candidate->id,
            'first_name' => $first,
            'last_name' => $last,
            // An email already held by a different linked person stays with them; this record
            // is then created without one rather than breaking the uniqueness rule.
            'email' => $byEmail ? null : $candidate->email,
            'employment_type' => $this->employmentType($application->vacancy()->withoutGlobalScopes()->value('work_type')),
            'status' => 'active',
            'start_date' => $offer->starts_on,
        ])->save();

        return $employee;
    }

    /** The employee record keeps its three broad types; the role's detailed type stays on the vacancy. */
    private function employmentType(?string $workType): string
    {
        return match ($workType) {
            'part_time', 'casual', 'shift', 'hourly' => 'part_time',
            'freelance', 'contractor', 'consulting', 'project', 'commission' => 'contractor',
            default => 'full_time',
        };
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return [$parts[0] ?? $name, '—'];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
