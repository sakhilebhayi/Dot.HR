<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\Resignation;
use App\Models\ServiceCertificate;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Leaving well (R-32 brief §15): resignations, exit interviews and the certificate of service.
 * The offboarding checklist stays the one place a departure changes the employee record; a
 * received resignation starts it, a withdrawal by agreement cancels it.
 *
 * The law quoted is South Africa's BCEA: written notice (s37(4)), minimum notice by length of
 * service (s37(1)), and the certificate of service on termination (s42). Prompts, not advice.
 */
final class Leaving
{
    public function __construct(private Lifecycle $lifecycle) {}

    /** The person gives notice. Refused when they already have notice open or are leaving already. */
    public function resign(Employee $employee, User $by, Carbon $lastDay, ?string $reason, ?string $message): Resignation
    {
        if ($employee->status === 'terminated') {
            throw ValidationException::withMessages(['last_day' => 'Your record shows you have already left.']);
        }
        if (Resignation::query()->where('employee_id', $employee->id)->whereIn('status', ['given', 'acknowledged'])->exists()) {
            throw ValidationException::withMessages(['last_day' => 'You have already given notice. Ask HR if something has changed.']);
        }
        if ($lastDay->lt(today())) {
            throw ValidationException::withMessages(['last_day' => 'Your last day cannot be in the past.']);
        }

        $resignation = new Resignation(['last_day' => $lastDay->toDateString(), 'reason' => $reason, 'message' => $message !== null && trim($message) !== '' ? trim($message) : null]);
        $resignation->forceFill([
            'team_id' => $employee->team_id,
            'employee_id' => $employee->id,
            'user_id' => $by->id,
            'given_at' => now(),
            'minimum_notice_days' => Resignation::minimumNoticeDays($employee->start_date, today()),
            'status' => 'given',
        ])->save();

        Audit::record('resignation.given', $resignation, ['last_day', 'reason'], ['last_day' => $lastDay->toDateString()], $employee->team_id);
        $this->tellHr($employee->team_id, 'A resignation was given', $resignation->reference().' is waiting for HR to acknowledge it.');

        return $resignation;
    }

    /**
     * HR acknowledges receipt, may agree a different last day with the person, and the
     * offboarding checklist starts. Optionally offers an exit interview at the same time.
     */
    public function acknowledge(Resignation $resignation, ?Carbon $agreedLastDay, bool $offerInterview, User $by): void
    {
        if ($resignation->status !== 'given') {
            throw ValidationException::withMessages(['resignation' => 'This resignation was already handled.']);
        }

        DB::transaction(function () use ($resignation, $agreedLastDay, $by) {
            $resignation->forceFill([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => $by->id,
                'agreed_last_day' => $agreedLastDay && ! $agreedLastDay->isSameDay($resignation->last_day) ? $agreedLastDay->toDateString() : null,
            ])->save();

            $checklist = $this->lifecycle->start($resignation->employee, 'offboarding', $by->id, [
                'last_day' => $resignation->leavingOn()->toDateString(),
                'reason' => 'resignation',
            ]);
            $resignation->forceFill(['lifecycle_checklist_id' => $checklist->id])->save();

            Audit::record('resignation.acknowledged', $resignation, ['status', 'agreed_last_day'], ['last_day' => $resignation->leavingOn()->toDateString()], $resignation->team_id);
        });

        $this->tellPerson($resignation->employee, 'HR received your resignation', 'Your last day is '.$resignation->leavingOn()->format('j F Y').'.', route('my-work.index').'#leaving');

        if ($offerInterview) {
            $this->offerInterview($resignation->employee, $by);
        }
    }

    /** The person asks to withdraw their notice. Only HR can agree to it. */
    public function requestWithdrawal(Resignation $resignation, string $note): void
    {
        if ($resignation->status === 'withdrawn' || ($resignation->withdrawal_requested_at && ! $resignation->withdrawal_decided_at)) {
            throw ValidationException::withMessages(['note' => 'You have already asked; HR will answer.']);
        }

        $resignation->forceFill(['withdrawal_requested_at' => now(), 'withdrawal_note' => trim($note), 'withdrawal_decided_at' => null])->save();
        Audit::record('resignation.withdrawal_requested', $resignation, ['withdrawal_note'], [], $resignation->team_id);
        $this->tellHr($resignation->team_id, 'A request to withdraw a resignation', $resignation->reference().' asks to stay on.');
    }

    public function decideWithdrawal(Resignation $resignation, bool $agree, User $by): void
    {
        if (! $resignation->withdrawal_requested_at || $resignation->withdrawal_decided_at) {
            throw ValidationException::withMessages(['resignation' => 'There is no request to withdraw.']);
        }

        DB::transaction(function () use ($resignation, $agree) {
            $resignation->forceFill(['withdrawal_decided_at' => now()] + ($agree ? ['status' => 'withdrawn'] : []))->save();

            if ($agree && $resignation->checklist?->status === 'open') {
                $this->lifecycle->cancel($resignation->checklist);
            }
            Audit::record($agree ? 'resignation.withdrawn' : 'resignation.withdrawal_declined', $resignation, ['status'], [], $resignation->team_id);
        });

        $this->tellPerson($resignation->employee,
            $agree ? 'You are staying on' : 'About withdrawing your resignation',
            $agree ? 'HR agreed: your resignation is withdrawn.' : 'HR did not agree to withdraw it. Your last day stays '.$resignation->leavingOn()->format('j F Y').'.',
            route('my-work.index').'#leaving');
    }

    /** Offer an exit interview. One open offer per person. */
    public function offerInterview(Employee $employee, User $by): ExitInterview
    {
        $open = ExitInterview::query()->where('employee_id', $employee->id)->whereNull('completed_at')->whereNull('declined_at')->first();
        if ($open) {
            return $open;
        }

        $interview = new ExitInterview;
        $interview->forceFill(['team_id' => $employee->team_id, 'employee_id' => $employee->id, 'offered_at' => now(), 'offered_by' => $by->id])->save();
        Audit::record('exit_interview.offered', $interview, [], [], $employee->team_id);
        $this->tellPerson($employee, 'Would you tell us about your time here?', 'An exit interview is waiting if you want it. It is optional.', route('my-work.exit-interview', $interview));

        return $interview;
    }

    /**
     * The person answers (mode form), or HR records a conversation (mode meeting).
     *
     * @param  list<string>  $reasons
     * @param  array<string, string|null>  $answers
     */
    public function completeInterview(ExitInterview $interview, string $mode, array $reasons, array $answers, ?string $wouldReturn, bool $shareAnonymously): void
    {
        if (! $interview->isOpen()) {
            throw ValidationException::withMessages(['interview' => 'This exit interview is closed.']);
        }

        $interview->forceFill([
            'mode' => $mode,
            'reasons' => array_values(array_intersect($reasons, array_keys(ExitInterview::REASONS))),
            'answers' => json_encode(array_filter(array_intersect_key($answers, ExitInterview::QUESTIONS + ['notes' => '']), fn ($a) => is_string($a) && trim($a) !== ''), JSON_UNESCAPED_UNICODE),
            'would_return' => array_key_exists((string) $wouldReturn, ExitInterview::RETURN) ? $wouldReturn : null,
            'share_anonymously' => $shareAnonymously,
            'completed_at' => now(),
        ])->save();

        Audit::record('exit_interview.completed', $interview, ['answers'], ['mode' => $mode, 'share_anonymously' => $shareAnonymously], $interview->team_id);
        if ($mode === 'form') {
            $this->tellHr($interview->team_id, 'An exit interview was answered', 'One person who is leaving answered the exit interview.');
        }
    }

    public function declineInterview(ExitInterview $interview): void
    {
        if ($interview->isOpen()) {
            $interview->forceFill(['declined_at' => now()])->save();
            Audit::record('exit_interview.declined', $interview, [], [], $interview->team_id);
        }
    }

    /**
     * Anonymous patterns across exit interviews the people agreed to share, or null below the
     * anonymity floor. Counts only: no answer text, no names, no dates.
     *
     * @return array{count: int, reasons: array<string, int>, would_return: array<string, int>}|null
     */
    public function patterns(int $teamId): ?array
    {
        $shared = ExitInterview::query()->where('team_id', $teamId)->whereNotNull('completed_at')->where('share_anonymously', true)->get(['reasons', 'would_return']);
        if ($shared->count() < ExitInterview::ANONYMITY_FLOOR) {
            return null;
        }

        $reasons = array_fill_keys(array_keys(ExitInterview::REASONS), 0);
        foreach ($shared as $row) {
            foreach ($row->reasons ?? [] as $key) {
                if (isset($reasons[$key])) {
                    $reasons[$key]++;
                }
            }
        }
        arsort($reasons);

        return [
            'count' => $shared->count(),
            'reasons' => array_filter($reasons),
            'would_return' => array_merge(array_fill_keys(array_keys(ExitInterview::RETURN), 0), $shared->whereNotNull('would_return')->countBy('would_return')->all()),
        ];
    }

    /** Issue a certificate of service (BCEA s42) and let the person know it is ready. */
    public function issueCertificate(Employee $employee, array $data, User $by): ServiceCertificate
    {
        $certificate = new ServiceCertificate($data);
        $certificate->forceFill(['team_id' => $employee->team_id, 'employee_id' => $employee->id, 'issued_at' => now(), 'issued_by' => $by->id])->save();
        Audit::record('certificate.issued', $certificate, ['employer_name', 'job_title', 'start_date', 'end_date', 'remuneration', 'reason'], [], $employee->team_id);
        $this->tellPerson($employee, 'Your certificate of service is ready', 'Open it from My work to print or save it.', route('my-work.certificate', $certificate));

        return $certificate;
    }

    private function tellHr(int $teamId, string $title, string $message): void
    {
        $team = Team::query()->findOrFail($teamId);
        Notification::send($team->administrators(), new LifecycleNotification($title, $message, route('leaving.index'), teamId: $teamId));
    }

    private function tellPerson(Employee $employee, string $title, string $message, string $url): void
    {
        if ($employee->user_id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification($title, $message, $url, teamId: $employee->team_id));
        }
    }
}
