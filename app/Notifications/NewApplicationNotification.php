<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Notifications\Notification;

/**
 * In-app note to the business's recruiters (admins and recruiter grants) that someone
 * applied. Carries the role and the reference, never the applicant's contact details.
 */
class NewApplicationNotification extends Notification
{
    public function __construct(public Application $application, public string $roleTitle) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'application_received',
            'title' => 'New application',
            'message' => 'Someone applied for '.$this->roleTitle.' ('.$this->application->reference().').',
            'application_id' => $this->application->id,
            'url' => route('hiring.applications.show', $this->application->id),
        ];
    }
}
