<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Support\Hr\HrAccess;

/**
 * Who may read a person's professional profile (R-32 Phase 3) — decided only from the
 * visibility the person chose, never from who is asking how nicely.
 *
 *   public        anyone, signed in or not (the share link).
 *   employers     a viewer acting in a business workspace where they may recruit,
 *                 provided the person has not blocked that business.
 *   applications  only businesses the person has applied to, and only their reviewers.
 *   private       only the person.
 *
 * Contact details are never part of the profile at all. A person's email reaches a business
 * only through an application, where they agree to share it with that one business.
 */
class ProfessionalProfilePolicy
{
    public function view(?User $viewer, ProfessionalProfile $profile): bool
    {
        if ($viewer && $viewer->id === $profile->user_id) {
            return true;
        }

        return match ($profile->visibility) {
            'public' => true,
            'employers' => $viewer !== null && $this->recruitsInAnUnblockedBusiness($viewer, $profile),
            'applications' => $viewer !== null && $this->appliedToViewersBusiness($viewer, $profile),
            default => false,
        };
    }

    public function update(User $viewer, ProfessionalProfile $profile): bool
    {
        return $viewer->id === $profile->user_id;
    }

    private function recruitsInAnUnblockedBusiness(User $viewer, ProfessionalProfile $profile): bool
    {
        // The business the viewer is acting for right now — not any company they happen to
        // belong to — so a block the person set holds no matter which tab is open.
        $access = HrAccess::current($viewer);

        if (! $access || ! $access->canRecruit()) {
            return false;
        }

        return ! $profile->blockedTeams()->whereKey($access->team()->id)->exists();
    }

    private function appliedToViewersBusiness(User $viewer, ProfessionalProfile $profile): bool
    {
        // "Only businesses I apply to": the viewer must be reviewing candidates for the
        // business they are acting in, and the person must have a real application there.
        $access = HrAccess::current($viewer);

        if (! $access || ! $access->canReviewCandidates()) {
            return false;
        }

        return Application::withoutTeamScope(fn () => Application::query()
            ->where('team_id', $access->team()->id)
            ->where('user_id', $profile->user_id)
            ->exists());
    }
}
