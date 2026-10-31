<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use RuntimeException;

/**
 * The written-review flow (R-32 Phase 6b): reflection → feedback → shared → acknowledged.
 * Who may read a review is decided here (canRead), in one place. Audit entries carry the
 * status change only — never a word of the review.
 */
final class Reviews
{
    public function canRead(Review $review, User $user, bool $isAdmin): bool
    {
        return $isAdmin
            || $review->reviewer_id === $user->id
            || ($review->employee->user_id !== null && $review->employee->user_id === $user->id);
    }

    public function isSubject(Review $review, User $user): bool
    {
        return $review->employee->user_id !== null && $review->employee->user_id === $user->id;
    }

    /**
     * @param  iterable<Employee>  $people
     * @return array{added: int, refused: list<string>}
     */
    public function addPeople(ReviewCycle $cycle, iterable $people, User $reviewer, Team $team): array
    {
        if ($cycle->status !== 'open') {
            throw new RuntimeException('This cycle is closed.');
        }

        if (! $reviewer->belongsToTeam($team)) {
            throw new RuntimeException('The reviewer must be a member of this business.');
        }

        $added = 0;
        $refused = [];

        foreach ($people as $person) {
            if ($person->user_id === $reviewer->id) {
                $refused[] = $person->fullName().' (nobody reviews themselves)';

                continue;
            }

            if ($person->status === 'terminated') {
                $refused[] = $person->fullName().' (record closed)';

                continue;
            }

            if (Review::query()->where('review_cycle_id', $cycle->id)->where('employee_id', $person->id)->exists()) {
                $refused[] = $person->fullName().' (already in this cycle)';

                continue;
            }

            $review = new Review;
            $review->forceFill([
                'team_id' => $cycle->team_id,
                'review_cycle_id' => $cycle->id,
                'employee_id' => $person->id,
                'reviewer_id' => $reviewer->id,
                // Someone without a Dot.HR account cannot write a reflection: go straight to the reviewer.
                'status' => $person->user_id ? 'self' : 'reviewer',
                'self_skipped' => $person->user_id === null,
            ])->save();
            Audit::record('review.created', $review, ['status', 'reviewer_id']);

            if ($person->user_id) {
                User::query()->find($person->user_id)?->notify(new LifecycleNotification(
                    'Your review has started',
                    'Start with your own reflection: what went well, what was hard, what you want to grow into.',
                    route('reviews.show', $review),
                ));
            }
            $added++;
        }

        return ['added' => $added, 'refused' => $refused];
    }

    public function reflect(Review $review, string $text): void
    {
        $this->expect($review, 'self');
        $review->forceFill(['self_assessment' => $text, 'status' => 'reviewer', 'self_done_at' => now()])->save();
        Audit::record('review.reflected', $review, ['self_assessment', 'status'], ['status' => ['from' => 'self', 'to' => 'reviewer']]);

        $review->reviewer->notify(new LifecycleNotification(
            'A reflection is ready',
            $review->employee->fullName().' has written their reflection. Your feedback is next.',
            route('reviews.show', $review),
        ));
    }

    public function skipReflection(Review $review): void
    {
        $this->expect($review, 'self');
        $review->forceFill(['self_skipped' => true, 'status' => 'reviewer'])->save();
        Audit::record('review.reflection_skipped', $review, ['status'], ['status' => ['from' => 'self', 'to' => 'reviewer']]);
    }

    public function share(Review $review, string $feedback, User $actor): void
    {
        $this->expect($review, 'reviewer');

        if ($review->reviewer_id !== $actor->id) {
            throw new RuntimeException('Only the reviewer shares the feedback.');
        }

        $review->forceFill(['feedback' => $feedback, 'status' => 'shared', 'shared_at' => now()])->save();
        Audit::record('review.shared', $review, ['feedback', 'status'], ['status' => ['from' => 'reviewer', 'to' => 'shared']]);

        if ($review->employee->user_id) {
            User::query()->find($review->employee->user_id)?->notify(new LifecycleNotification(
                'Your review is ready to read',
                $actor->name.' has shared their feedback.',
                route('reviews.show', $review),
            ));
        }
    }

    public function acknowledge(Review $review, ?string $response): void
    {
        $this->expect($review, 'shared');
        $review->forceFill(['response' => $response, 'status' => 'acknowledged', 'acknowledged_at' => now()])->save();
        Audit::record('review.acknowledged', $review, $response ? ['response', 'status'] : ['status'], ['status' => ['from' => 'shared', 'to' => 'acknowledged']]);

        $review->reviewer->notify(new LifecycleNotification(
            'A review is complete',
            $review->employee->fullName().' has read your feedback'.($response ? ' and answered.' : '.'),
            route('reviews.show', $review),
        ));
    }

    public function reassign(Review $review, User $reviewer, Team $team): void
    {
        if ($review->status === 'acknowledged') {
            throw new RuntimeException('A complete review keeps its reviewer.');
        }

        if (! $reviewer->belongsToTeam($team) || $review->employee->user_id === $reviewer->id) {
            throw new RuntimeException('Pick a member of this business other than the person being reviewed.');
        }

        $from = $review->reviewer_id;
        $review->forceFill(['reviewer_id' => $reviewer->id])->save();
        Audit::record('review.reassigned', $review, ['reviewer_id'], ['reviewer_id' => ['from' => $from, 'to' => $reviewer->id]]);
    }

    private function expect(Review $review, string $status): void
    {
        if ($review->status !== $status) {
            throw new RuntimeException('This review has moved on: '.strtolower(Review::STATUSES[$review->status]).'.');
        }

        if ($review->cycle->status !== 'open') {
            throw new RuntimeException('This review cycle is closed.');
        }
    }
}
