<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class Invitation extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('activate', now()->addMinutes(60), ['user' => $notifiable->id, 'token' => $this->token]);

        return (new MailMessage)->subject('Activate your campus account')->greeting('Hello '.$notifiable->name)
            ->line('Your administrator created an LMS account. Confirm this email address and choose your password to activate it.')
            ->action('Activate account', $url)->line('This invitation expires in 60 minutes. Ask your administrator to resend it if needed.');
    }
}
