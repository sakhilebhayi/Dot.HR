<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app note to a candidate that something about their own application changed: an
 * interview, an offer, or a decision. The words are the candidate-facing ones; nothing of
 * the business's internal notes or feedback is ever included.
 */
class CandidateUpdateNotification extends Notification
{
    public function __construct(
        public int $applicationId,
        public string $title,
        public string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'application_update',
            'title' => $this->title,
            'message' => $this->message,
            'application_id' => $this->applicationId,
            'url' => route('me.applications.show', $this->applicationId),
        ];
    }
}
