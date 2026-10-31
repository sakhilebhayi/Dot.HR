<?php

namespace App\Console\Commands;

use App\Models\EmployeeCertification;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Support\Hr\LocalTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Warns once, ahead of time, about certifications that are about to lapse (R-32 Phase 6a,
 * brief §8 "renewal reminders"): the business's HR administrators, and the person if they
 * have a Dot.HR account. Each certification is warned about once (reminded_at); renewing it
 * (a new expiry date) makes it eligible again.
 */
class RemindExpiringCertifications extends Command
{
    protected $signature = 'hr:certification-reminders';

    protected $description = 'Notify HR and the holder about certifications expiring within 30 days, once each.';

    public function handle(): int
    {
        $sent = 0;

        EmployeeCertification::withoutTeamScope(function () use (&$sent) {
            EmployeeCertification::query()
                ->whereNull('reminded_at')
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '>=', LocalTime::today())
                ->whereDate('expires_on', '<=', LocalTime::today()->addDays(EmployeeCertification::SOON_DAYS))
                ->with('employee')
                ->chunkById(200, function ($certs) use (&$sent) {
                    foreach ($certs as $cert) {
                        $employee = $cert->employee;
                        $team = Team::query()->find($employee->team_id);

                        if (! $team || $employee->status === 'terminated') {
                            continue;
                        }

                        $when = $cert->expires_on->format('j M Y');
                        $admins = $team->allUsers()->filter(fn (User $u) => $team->user_id === $u->id || $u->hasTeamRole($team, 'admin'));

                        Notification::send($admins, new LifecycleNotification(
                            'A certificate is about to lapse',
                            $employee->fullName().'\'s '.$cert->name.' expires on '.$when.'.',
                            route('certifications.index'),
                        ));

                        if ($employee->user_id && ($person = User::query()->find($employee->user_id))) {
                            $person->notify(new LifecycleNotification(
                                'Your '.$cert->name.' expires soon',
                                'It expires on '.$when.'. Renew it and give HR the new certificate.',
                            ));
                        }

                        $cert->forceFill(['reminded_at' => now()])->save();
                        $sent++;
                    }
                });
        });

        $this->info("Reminded about {$sent} certification(s).");

        return self::SUCCESS;
    }
}
