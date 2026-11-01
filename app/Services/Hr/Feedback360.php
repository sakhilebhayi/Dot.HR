<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\FeedbackResponse;
use App\Models\FeedbackRound;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 360 feedback (brief §10, continuous feedback). Colleagues write; nobody scores. HR
 * administrators see who wrote what, so misuse can be dealt with; the person reads the words
 * without names, in an order that does not follow who answered first, and only once at least
 * FeedbackRound::ANONYMITY_FLOOR people answered, so no single answer can be traced back.
 */
final class Feedback360
{
    /** @param Collection<int, User> $givers */
    public function start(Employee $employee, Collection $givers, ?string $focus, Carbon $dueOn, User $by): FeedbackRound
    {
        $team = Team::query()->findOrFail($employee->team_id);
        $givers = $givers->unique('id')->reject(fn (User $u) => $u->id === $employee->user_id)->values();

        if ($givers->count() < FeedbackRound::ANONYMITY_FLOOR || $givers->count() > FeedbackRound::MAX_GIVERS) {
            throw ValidationException::withMessages(['givers' => 'Ask between '.FeedbackRound::ANONYMITY_FLOOR.' and '.FeedbackRound::MAX_GIVERS.' colleagues, other than the person. With fewer, nobody could answer without being recognised.']);
        }
        $members = DB::table('team_user')->where('team_id', $team->id)->pluck('user_id')->push($team->user_id)->all();
        if ($givers->contains(fn (User $u) => ! in_array($u->id, $members, true))) {
            throw ValidationException::withMessages(['givers' => 'Ask people in this business only.']);
        }
        if ($dueOn->lt(today())) {
            throw ValidationException::withMessages(['due_on' => 'Choose a date that has not passed.']);
        }

        $round = DB::transaction(function () use ($employee, $givers, $focus, $dueOn, $by) {
            $round = new FeedbackRound;
            $round->forceFill([
                'team_id' => $employee->team_id, 'employee_id' => $employee->id, 'requested_by' => $by->id,
                'focus' => $focus !== null && trim($focus) !== '' ? mb_substr(trim($focus), 0, 500) : null,
                'due_on' => $dueOn->toDateString(), 'status' => 'open',
            ])->save();
            foreach ($givers as $giver) {
                $response = new FeedbackResponse;
                $response->forceFill(['team_id' => $employee->team_id, 'feedback_round_id' => $round->id, 'giver_user_id' => $giver->id])->save();
            }
            Audit::record('feedback.round_started', $round, [], ['givers' => $givers->count()], $employee->team_id);

            return $round;
        });

        foreach ($givers as $giver) {
            $giver->notify(new LifecycleNotification('A colleague would value your feedback', $employee->first_name.' would value your feedback by '.$dueOn->format('j M').'. Your name is not shown to them.', route('feedback.index'), teamId: $employee->team_id));
        }

        return $round;
    }

    public function answer(FeedbackResponse $response, array $answers): void
    {
        if (! $response->isPending() || $response->round->status !== 'open') {
            throw ValidationException::withMessages(['keep_doing' => 'This request is closed.']);
        }
        $clean = collect(FeedbackResponse::QUESTIONS)->keys()->mapWithKeys(fn ($k) => [$k => isset($answers[$k]) && trim((string) $answers[$k]) !== '' ? trim((string) $answers[$k]) : null])->all();
        if (array_filter($clean) === []) {
            throw ValidationException::withMessages(['keep_doing' => 'Write at least one answer, or say no thanks.']);
        }

        $response->forceFill([...$clean, 'answered_at' => now()])->save();
        Audit::record('feedback.answered', $response->round, [], [], $response->team_id);
    }

    public function decline(FeedbackResponse $response): void
    {
        if ($response->isPending()) {
            $response->forceFill(['declined_at' => now()])->save();
            Audit::record('feedback.declined', $response->round, [], [], $response->team_id);
        }
    }

    /** Share the answers with the person, without names. Refused below the anonymity floor. */
    public function share(FeedbackRound $round): void
    {
        $round->loadMissing('responses');
        if ($round->status !== 'open') {
            throw ValidationException::withMessages(['round' => 'This round was already shared or closed.']);
        }
        if ($round->answeredCount() < FeedbackRound::ANONYMITY_FLOOR) {
            throw ValidationException::withMessages(['round' => 'Wait for at least '.FeedbackRound::ANONYMITY_FLOOR.' answers, so no single answer can be traced to its writer.']);
        }

        $round->forceFill(['status' => 'shared', 'shared_at' => now()])->save();
        $round->responses()->whereNull('answered_at')->whereNull('declined_at')->update(['declined_at' => now()]);
        Audit::record('feedback.shared', $round, ['status'], ['answers' => $round->answeredCount()], $round->team_id);

        $employee = $round->employee;
        if ($employee->user_id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification('Your colleagues’ feedback is ready', $round->answeredCount().' people answered. Their names are not shown.', route('feedback.rounds.show', $round), teamId: $round->team_id));
        }
    }

    public function close(FeedbackRound $round): void
    {
        $round->forceFill(['status' => 'closed'])->save();
        Audit::record('feedback.closed', $round, ['status'], [], $round->team_id);
    }

    /**
     * What the person reads: the answers' words only, in an order fixed per round that does not
     * follow who answered first or who was asked first.
     *
     * @return list<array<string, string|null>>
     */
    public function forPerson(FeedbackRound $round): array
    {
        $rows = $round->responses()->whereNotNull('answered_at')->get()
            ->map(fn (FeedbackResponse $r) => ['key' => hash('sha256', $round->id.'|'.$r->id.'|'.config('app.key')), ...collect(FeedbackResponse::QUESTIONS)->keys()->mapWithKeys(fn ($k) => [$k => $r->{$k}])->all()])
            ->sortBy('key')->values()->map(fn ($row) => collect($row)->except('key')->all());

        return $rows->all();
    }
}
