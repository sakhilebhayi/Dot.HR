<?php

namespace App\Services\Intelligence;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\ProfessionalProfile;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Services\Recruitment\SkillMatch;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What a business can honestly say a person knows (R-32 Phase 7, brief §10 and §13), and where
 * each claim comes from:
 *
 *   their profile         skills the person listed themselves — only when the person's chosen
 *                         profile visibility lets this viewer read the profile;
 *   certificate on file   a certification the business holds that has not expired;
 *   training completed    training the business recorded as completed.
 *
 * Nothing is inferred: no reading of CVs, no guessing from job titles, nothing about who the
 * person is. Every skill carries its source so a reader can check it.
 */
final class SkillsEvidence
{
    public const SOURCES = [
        'profile' => 'their profile',
        'certificate' => 'certificate on file',
        'training' => 'training completed',
    ];

    /**
     * Evidence for many people at once, keyed by employee id, then by normalised skill name.
     *
     * @param  Collection<int, Employee>  $people
     * @return array<int, array<string, array{name: string, source: string}>>
     */
    public function forMany(Collection $people, User $viewer): array
    {
        $ids = $people->pluck('id')->all();
        $userIds = $people->pluck('user_id')->filter()->all();
        $today = LocalTime::today();

        $certs = EmployeeCertification::query()->whereIn('employee_id', $ids)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today))
            ->get(['employee_id', 'name'])->groupBy('employee_id');

        $training = TrainingRecord::query()->whereIn('employee_id', $ids)->where('status', 'completed')
            ->get(['employee_id', 'title', 'certification_name'])->groupBy('employee_id');

        $profiles = ProfessionalProfile::query()->whereIn('user_id', $userIds)->with('skills')->get()->keyBy('user_id');

        $evidence = [];
        foreach ($people as $person) {
            $found = [];

            $profile = $person->user_id ? $profiles->get($person->user_id) : null;
            if ($profile && Gate::forUser($viewer)->allows('view', $profile)) {
                foreach ($profile->skills as $skill) {
                    $found[SkillMatch::normalise($skill->name)] ??= ['name' => $skill->name, 'source' => 'profile'];
                }
            }

            foreach ($certs->get($person->id, collect()) as $cert) {
                $found[SkillMatch::normalise($cert->name)] ??= ['name' => $cert->name, 'source' => 'certificate'];
            }

            foreach ($training->get($person->id, collect()) as $record) {
                foreach (array_filter([$record->title, $record->certification_name]) as $name) {
                    $found[SkillMatch::normalise($name)] ??= ['name' => $name, 'source' => 'training'];
                }
            }

            $evidence[$person->id] = $found;
        }

        return $evidence;
    }
}
