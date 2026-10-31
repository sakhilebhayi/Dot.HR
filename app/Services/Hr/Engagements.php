<?php

namespace App\Services\Hr;

use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\EngagementMilestone;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rules of contractor engagements (R-32 Phase 6b): who an engagement may sit on, renewal,
 * ending, milestone delivery and approval, and invoice records. Every state change is audited
 * by field name and status — never the scope text or an amount.
 */
final class Engagements
{
    /**
     * @param  array{kind: string, title: string, scope?: string|null, rate_basis: string, rate_amount?: string|float|null, currency?: string|null, starts_on: string, ends_on?: string|null}  $data
     */
    public function create(Employee $person, array $data, int $actorId): Engagement
    {
        if ($person->employment_type !== 'contractor') {
            throw new RuntimeException($person->fullName().' is recorded as an employee, not a contractor. Change their employment type first if that is right.');
        }

        if ($person->status === 'terminated') {
            throw new RuntimeException($person->fullName().'\'s record is closed.');
        }

        $starts = Carbon::parse($data['starts_on']);
        $ends = isset($data['ends_on']) && $data['ends_on'] ? Carbon::parse($data['ends_on']) : null;
        if ($ends && $ends->lt($starts)) {
            throw new RuntimeException('The end date is before the start date.');
        }

        $engagement = new Engagement;
        $engagement->forceFill([
            'team_id' => $person->team_id,
            'employee_id' => $person->id,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'scope' => $data['scope'] ?? null,
            'rate_basis' => $data['rate_basis'],
            'rate_amount' => $data['rate_amount'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'ZAR'),
            'starts_on' => $starts,
            'ends_on' => $ends,
            'status' => 'active',
            'created_by' => $actorId,
        ])->save();

        Audit::record('engagement.created', $engagement, ['kind', 'title', 'scope', 'rate_amount', 'starts_on', 'ends_on']);

        return $engagement;
    }

    public function renew(Engagement $engagement, string $newEnd): void
    {
        $this->assertActive($engagement);
        $end = Carbon::parse($newEnd);

        if ($engagement->ends_on && $end->lte($engagement->ends_on)) {
            throw new RuntimeException('A renewal moves the end date later than '.$engagement->ends_on->format('j M Y').'.');
        }

        $before = $engagement->ends_on?->toDateString();
        $engagement->forceFill(['ends_on' => $end, 'renewals' => $engagement->renewals + 1, 'reminded_at' => null])->save();

        Audit::record('engagement.renewed', $engagement, ['ends_on', 'renewals'], [
            'ends_on' => ['from' => $before, 'to' => $end->toDateString()],
        ]);
    }

    public function end(Engagement $engagement, string $reason, ?string $endedOn): void
    {
        $this->assertActive($engagement);
        $on = $endedOn ? Carbon::parse($endedOn) : LocalTime::today();

        if ($on->lt($engagement->starts_on)) {
            throw new RuntimeException('An engagement cannot end before it started.');
        }

        $engagement->forceFill(['status' => 'ended', 'end_reason' => $reason, 'ended_on' => $on])->save();

        Audit::record('engagement.ended', $engagement, ['status', 'end_reason', 'ended_on'], [
            'status' => ['from' => 'active', 'to' => 'ended'],
            'end_reason' => ['from' => null, 'to' => $reason],
        ]);
    }

    public function deliver(EngagementMilestone $milestone): void
    {
        $this->assertActive($milestone->engagement);

        if (! in_array($milestone->status, ['open', 'rejected'], true)) {
            throw new RuntimeException('"'.$milestone->title.'" is already '.strtolower(EngagementMilestone::STATUSES[$milestone->status]).'.');
        }

        $milestone->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        Audit::record('milestone.delivered', $milestone, ['status'], ['status' => ['from' => 'open', 'to' => 'submitted']]);
    }

    public function decide(EngagementMilestone $milestone, bool $approve, ?string $note, int $actorId): void
    {
        if ($milestone->status !== 'submitted') {
            throw new RuntimeException('Only a delivered milestone can be approved or sent back.');
        }

        $to = $approve ? 'approved' : 'rejected';
        $milestone->forceFill(['status' => $to, 'decided_by' => $actorId, 'decided_at' => now(), 'decision_note' => $note])->save();
        Audit::record('milestone.'.$to, $milestone, ['status', 'decision_note'], ['status' => ['from' => 'submitted', 'to' => $to]]);
    }

    public function move(ContractorInvoice $invoice, string $to, ?string $note, int $actorId): void
    {
        if (! in_array($to, ContractorInvoice::MOVES[$invoice->status], true)) {
            throw new RuntimeException('An invoice that is '.strtolower(ContractorInvoice::STATUSES[$invoice->status]).' cannot be marked '.strtolower(ContractorInvoice::STATUSES[$to] ?? $to).'.');
        }

        DB::transaction(function () use ($invoice, $to, $note, $actorId): void {
            $from = $invoice->status;
            $invoice->forceFill(['status' => $to, 'note' => $note ?? $invoice->note, 'decided_by' => $actorId, 'decided_at' => now()])->save();
            Audit::record('invoice.'.$to, $invoice, ['status', 'note'], ['status' => ['from' => $from, 'to' => $to]]);
        });
    }

    private function assertActive(Engagement $engagement): void
    {
        if ($engagement->status !== 'active') {
            throw new RuntimeException('This engagement has ended.');
        }
    }
}
