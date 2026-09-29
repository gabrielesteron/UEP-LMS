<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PortalNotice extends Notification
{
    public function __construct(public string $title, public string $message, public string $url = '/dashboard') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
