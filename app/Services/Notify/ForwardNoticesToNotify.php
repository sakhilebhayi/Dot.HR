<?php

namespace App\Services\Notify;

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use App\Notifications\CandidateUpdateNotification;
use App\Notifications\LifecycleNotification;
use App\Notifications\NewApplicationNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Every in-app notice Dot.HR writes is also offered to Dot.Notify as an email (R-32 follow-up,
 * 2026-11-01). Listening to "a database notice was written" covers every place that tells
 * someone something, without touching each one; leave notices are left out because the leave
 * observer already sends its own, richer events.
 *
 * What goes out is the minimum: who the business is, what kind of thing happened, and a link back
 * into Dot.HR. The notice's text stays in Dot.HR. Delivery is queued, never throws and never
 * slows the request (NotifyClient), and with Notify not configured nothing is sent at all.
 * Whether an email is actually sent is decided in Notify: the business turns each event on there.
 */
final class ForwardNoticesToNotify
{
    public function __construct(private NotifyClient $notify) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $this->notify->enabled()) {
            return;
        }

        try {
            $this->forward($event->notification, $event->notifiable, (string) ($event->notification->id ?? ''));
        } catch (Throwable) {
            // An email that cannot be prepared must never affect the notice itself.
        }
    }

    private function forward(object $notice, User $recipient, string $id): void
    {
        if ($id === '') {
            return;
        }

        if ($notice instanceof NewApplicationNotification) {
            $application = $notice->application;
            if ($team = Team::query()->find($application->team_id)) {
                $this->notify->notice('application.received', $team, $recipient, [
                    'role_title' => $notice->roleTitle,
                    'reference' => $application->reference(),
                    'url' => NotifyClient::https(route('hiring.applications.show', $application->id)),
                ], $id);
            }

            return;
        }

        if ($notice instanceof CandidateUpdateNotification) {
            $application = Application::withoutTeamScope(fn () => Application::query()->with('vacancy')->find($notice->applicationId));
            if ($application && ($team = Team::query()->find($application->team_id))) {
                $this->notify->notice('application.updated', $team, $recipient, [
                    'business_name' => (string) $team->name,
                    'role_title' => (string) ($application->vacancy->title ?? ''),
                    'update' => $notice->title,
                    'url' => NotifyClient::https(route('me.applications.show', $application->id)),
                ], $id);
            }

            return;
        }

        if ($notice instanceof LifecycleNotification && $notice->teamId !== null && ($team = Team::query()->find($notice->teamId))) {
            $this->notify->notice('hr.update', $team, $recipient, [
                'business_name' => (string) $team->name,
                'title' => $notice->title,
                'url' => NotifyClient::https($notice->url ?? route('my-work.index')),
            ], $id);
        }
    }
}
