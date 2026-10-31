<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app note about the person's own employment: a checklist started for them, or an
 * answer to an HR request. Never carries the content of a request or any HR-only detail.
 */
class LifecycleNotification extends Notification
{
    public function __construct(public string $title, public string $message, public ?string $url = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'lifecycle',
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url ?? route('my-work.index'),
        ];
    }
}
