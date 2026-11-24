<?php

namespace App\Services\InfoDot;

use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds Dot.HR (R-50), for a person who is already signed in.
 *
 * One read per company, remembered for sixty seconds, so a switch made at InfoDot (a platform put in maintenance, R-61)
 * takes effect within about a minute. The answer is one of three: the company holds the platform, it does not (with
 * InfoDot's status word), or nobody knows; it also says whether InfoDot has the platform switched on (`available`,
 * `message`). Every clear answer is also remembered for six hours: when InfoDot gives no clear answer (no secret, a
 * timeout, a refusal, a body that does not name this platform), the last clear answer under six hours old is used, so
 * a platform last told "unavailable" stays unavailable. With none, the answer is "nobody knows", which is never a
 * refusal, so an outage cannot lock a company out that was last told it could work. Only the company's InfoDot uuid
 * leaves this app; no person, no record.
 */
class CompanyEntitlement
{
    public const HELD = 'held';

    public const NOT_HELD = 'not_held';

    public const UNKNOWN = 'unknown';

    private const REMEMBER_ANSWER = 60;

    private const REMEMBER_FAILURE = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    private const LAST_KNOWN_SECONDS = 21600;

    public function enabled(): bool
    {
        return (bool) config('hr.entitlement_check', true);
    }

    /**
     * The answer already remembered for this company, or null. Never calls InfoDot.
     *
     * @return array{state: string, status: string|null, available: bool, message: string|null}|null
     */
    public function remembered(Team $team): ?array
    {
        $uuid = (string) $team->infodot_team_uuid;
        $known = $uuid === '' || $team->personal_team ? null : Cache::get('entitlement:'.$this->platform().':'.$uuid);

        return is_array($known) && isset($known['state']) ? $known : null;
    }

    /**
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    public function for(Team $team): array
    {
        $uuid = (string) $team->infodot_team_uuid;

        // A personal workspace or a company InfoDot does not know: there is nothing to ask about.
        if ($uuid === '' || $team->personal_team) {
            return ['state' => self::UNKNOWN, 'status' => null, 'available' => true, 'message' => null];
        }

        $key = 'entitlement:'.$this->platform().':'.$uuid;
        $known = Cache::get($key);

        if (is_array($known) && isset($known['state'])) {
            return $known;
        }

        $answer = $this->ask($uuid);

        try {
            if ($answer['state'] !== self::UNKNOWN) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_SECONDS);
            } else {
                $last = Cache::get($key.':last');

                if (is_array($last) && is_array($last['answer'] ?? null) && isset($last['answer']['state']) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_SECONDS) {
                    $answer = $last['answer'];
                }
            }

            Cache::put($key, $answer, $answer['state'] === self::UNKNOWN ? self::REMEMBER_FAILURE : self::REMEMBER_ANSWER);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    /**
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    private function ask(string $uuid): array
    {
        $bearer = (string) config('ecosystem.infodot.secret');
        $url = $this->url($uuid);

        if ($bearer === '' || $url === null) {
            return $this->unknown('not configured');
        }

        try {
            $response = Http::withToken($bearer)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->get($url, ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown('unreachable ('.class_basename($e).')');
        }

        if (! $response->successful()) {
            return $this->unknown('status '.$response->status());
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows)) {
            return $this->unknown('malformed answer');
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return $this->unknown('malformed answer');
                }

                return [
                    'state' => $row['entitled'] ? self::HELD : self::NOT_HELD,
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                ];
            }
        }

        return $this->unknown('this platform is not in the answer');
    }

    /**
     * Logged here, where the failure is about to be remembered: once per cache window, not once per request.
     *
     * @return array{state: string, status: string|null, available: bool, message: string|null}
     */
    private function unknown(string $why): array
    {
        Log::warning('InfoDot entitlement check could not be completed; access carries on unchanged.', ['reason' => $why]);

        return ['state' => self::UNKNOWN, 'status' => null, 'available' => true, 'message' => null];
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'hr');
    }

    /** InfoDot's read endpoint sits beside its redeem endpoint unless INFODOT_COMPANIES_URL says otherwise. */
    private function url(string $uuid): ?string
    {
        $base = (string) config('ecosystem.infodot.companies_url');

        if ($base === '') {
            $redeem = (string) config('ecosystem.infodot.redeem_url');

            if ($redeem === '' || ! preg_match('#/redeem/?$#', $redeem)) {
                return null;
            }

            $base = preg_replace('#/redeem/?$#', '/companies', $redeem);
        }

        return rtrim((string) $base, '/').'/'.rawurlencode($uuid).'/platforms';
    }
}
