<?php

namespace App\Services\Hr;

use App\Models\HrAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the append-only HR audit log.
 *
 * What goes in: who (actor id), what (action), on what (subject type and id), which
 * field NAMES changed, and before/after values only for fields the subject class lists
 * in AUDIT_FIELDS (status, dates, ids: things that are not personal). What never goes in:
 * a leave reason, an email address, a phone number, a name. Names of such fields appear
 * in `fields` ("phone changed"); their values never do.
 *
 * The actor is the signed-in user; the IP is the web request's (CaptureAuditContext). Console
 * work (the purge, seeders) has neither, and `asSystem()` forces that even when a user
 * happens to be signed in.
 */
final class Audit
{
    private static bool $system = false;

    private static ?string $requestIp = null;

    /**
     * @param  list<string>  $fields  names of the fields involved
     * @param  array<string, mixed>  $changes  non-sensitive detail, e.g. ['status' => ['from' => 'pending', 'to' => 'approved']]
     */
    public static function record(string $action, ?Model $subject = null, array $fields = [], array $changes = [], ?int $teamId = null): HrAuditLog
    {
        $teamId ??= $subject?->team_id;

        return HrAuditLog::withoutTeamScope(fn () => HrAuditLog::create([
            'team_id' => $teamId,
            'actor_user_id' => self::$system ? null : Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'fields' => $fields === [] ? null : array_values($fields),
            'changes' => $changes === [] ? null : $changes,
            'ip' => self::$system ? null : self::$requestIp,
            'created_at' => now(),
        ]));
    }

    /**
     * Logs a model's update: changed field names, plus before/after for the allowed ones.
     * Returns the changed field names (empty when nothing worth logging changed).
     *
     * @return list<string>
     */
    public static function changedFields(Model $model): array
    {
        return array_diff(
            array_keys($model->getChanges()),
            ['updated_at', 'deleted_at', 'email_hash'],
        );
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $allowed
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(Model $model, array $fields, array $allowed): array
    {
        $diff = [];

        foreach (array_intersect($fields, $allowed) as $field) {
            $diff[$field] = [
                'from' => self::scalar($field, $model->getRawOriginal($field)),
                'to' => self::scalar($field, $model->getAttributes()[$field] ?? null),
            ];
        }

        return $diff;
    }

    /** Set by CaptureAuditContext for the duration of a web request; null everywhere else. */
    public static function useRequestIp(?string $ip): void
    {
        self::$requestIp = $ip;
    }

    /** Runs $callback recording no actor and no IP, whoever is signed in. */
    public static function asSystem(callable $callback): mixed
    {
        $previous = self::$system;
        self::$system = true;

        try {
            return $callback();
        } finally {
            self::$system = $previous;
        }
    }

    private static function scalar(string $field, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        if (is_string($value) && str_ends_with($field, '_date')) {
            return substr($value, 0, 10);
        }

        return is_scalar($value) || $value === null ? $value : (string) json_encode($value);
    }
}
