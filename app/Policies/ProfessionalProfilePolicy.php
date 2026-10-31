<?php

namespace App\Policies;

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
 *   applications  only businesses the person applied to (opens in Phase 4; until then
 *                 nobody but the person).
 *   private       only the person.
 *
 * Contact details are never part of the profile at all: an employer reaches a person
 * through an application or a Dot.HR message (Phase 4), not an address.
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
        // Applications arrive in Phase 4. Until a real application exists, "only when I
        // apply" must mean exactly nobody — never a guess.
        return false;
    }
}
