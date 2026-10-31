<?php

namespace App\Console\Commands;

use App\Models\Engagement;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Support\Hr\LocalTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Tells a business's HR administrators, once, that a contractor engagement ends within 30
 * days (R-32 Phase 6b, brief §12 "contract expiry reminders"), so it is renewed or ended on
 * purpose rather than left to run on. Renewing clears reminded_at.
 */
class RemindEndingEngagements extends Command
{
    protected $signature = 'hr:engagement-reminders';

    protected $description = 'Notify HR about contractor engagements ending within 30 days, once each.';

    public function handle(): int
    {
        $sent = 0;

        Engagement::withoutTeamScope(function () use (&$sent) {
            Engagement::query()
                ->where('status', 'active')
                ->whereNull('reminded_at')
                ->whereNotNull('ends_on')
                ->whereDate('ends_on', '>=', LocalTime::today())
                ->whereDate('ends_on', '<=', LocalTime::today()->addDays(Engagement::SOON_DAYS))
                ->with('employee')
                ->chunkById(200, function ($engagements) use (&$sent) {
                    foreach ($engagements as $engagement) {
                        $team = Team::query()->find($engagement->team_id);
                        if (! $team) {
                            continue;
                        }

                        $admins = $team->allUsers()->filter(fn (User $u) => $team->user_id === $u->id || $u->hasTeamRole($team, 'admin'));
                        Notification::send($admins, new LifecycleNotification(
                            'A contractor engagement ends soon',
                            $engagement->employee->fullName().' — '.$engagement->title.' ends on '.$engagement->ends_on?->format('j M Y').'. Renew it or end it.',
                            route('contractors.show', $engagement),
                        ));

                        $engagement->forceFill(['reminded_at' => now()])->save();
                        $sent++;
                    }
                });
        });

        $this->info("Reminded about {$sent} engagement(s).");

        return self::SUCCESS;
    }
}
