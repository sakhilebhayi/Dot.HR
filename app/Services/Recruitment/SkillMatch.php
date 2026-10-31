<?php

namespace App\Services\Recruitment;

use Illuminate\Support\Str;

/**
 * Explainable matching (brief §5, §13): the ONLY signal is overlap between the skills a role
 * asks for and the skills a person listed themselves. Nothing is inferred, weighted by who
 * the person is, or turned into a hidden score — the result names the skills that matched,
 * so a recruiter can see exactly why, and a person can see what to add. It never ranks out
 * or rejects anyone: people decide.
 */
final class SkillMatch
{
    /**
     * @param  array<int, string>  $wanted  the role's skills
     * @param  iterable<string>  $listed  the person's skills (display names)
     * @return array{matched: list<string>, total: int}
     */
    public static function compare(array $wanted, iterable $listed): array
    {
        $theirs = [];
        foreach ($listed as $name) {
            $theirs[self::normalise($name)] = true;
        }

        $matched = [];
        foreach ($wanted as $skill) {
            if (isset($theirs[self::normalise($skill)])) {
                $matched[] = $skill;
            }
        }

        return ['matched' => $matched, 'total' => count($wanted)];
    }

    /** "matches 3 of 5 skills: Sage, Pastel, payroll" — or null when the role lists none. */
    public static function sentence(array $wanted, iterable $listed): ?string
    {
        $result = self::compare($wanted, $listed);

        if ($result['total'] === 0) {
            return null;
        }

        if ($result['matched'] === []) {
            return 'none of the '.$result['total'].' listed skills';
        }

        return 'matches '.count($result['matched']).' of '.$result['total'].' skills: '.implode(', ', $result['matched']);
    }

    public static function normalise(string $skill): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $skill) ?? ''));
    }
}
