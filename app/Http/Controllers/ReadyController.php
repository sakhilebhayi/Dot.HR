<?php

namespace App\Http\Controllers;

use App\Console\Commands\SchedulerHeartbeat;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness for uptime monitors and Dot.Guardian (R-32 Phase 8). /up only says PHP answers;
 * /ready says Dot.HR can do its job: the database answers and has no pending migrations, the
 * cache reads back what it wrote, storage is writable, and the scheduler ran recently.
 * 200 when every check passes, 503 otherwise. It names checks and ages — never a host,
 * a credential, an error message or a count of anyone's data.
 */
class ReadyController extends Controller
{
    /** The scheduler beats every five minutes; three missed beats is a failure. */
    public const STALE_AFTER_SECONDS = 900;

    public function __invoke(Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->attempt(function () use ($migrator) {
                DB::select('select 1');
                $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
                $pending = array_diff($files, $migrator->getRepository()->getRan());

                return ['ok' => $pending === [], 'pending_migrations' => count($pending)];
            }),
            'cache' => $this->attempt(function () {
                $key = 'hr:ready:'.Str::random(8);
                Cache::put($key, 'yes', 30);
                $ok = Cache::pull($key) === 'yes';

                return ['ok' => $ok];
            }),
            'storage' => ['ok' => is_writable(storage_path('framework/cache')) && is_writable(storage_path('logs'))],
            'scheduler' => $this->scheduler(),
        ];

        $ok = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks, 'checked_at' => now()->toIso8601String()], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{ok: bool, last_run_seconds_ago: int|null} */
    private function scheduler(): array
    {
        $last = Cache::get(SchedulerHeartbeat::KEY);

        if (! is_int($last)) {
            // Nothing recorded yet (a fresh deploy or a cleared cache): unknown, not failed.
            return ['ok' => true, 'last_run_seconds_ago' => null];
        }

        $age = max(0, now()->getTimestamp() - $last);

        return ['ok' => $age <= self::STALE_AFTER_SECONDS, 'last_run_seconds_ago' => $age];
    }

    /**
     * @param  callable(): array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function attempt(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
