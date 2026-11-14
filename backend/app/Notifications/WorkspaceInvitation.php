<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace->name;
        $inviter = $this->invitation->inviter->name ?? 'A teammate';

        return (new MailMessage)
            ->subject("You are invited to {$workspace} on ".config('app.name'))
            ->line("{$inviter} invited you to join the workspace {$workspace}.")
            ->action('Accept invitation', $this->url())
            ->line('The invitation is valid for '.Invitation::VALID_DAYS.' days. If you did not expect it, you can ignore this email.');
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$this->token;
    }
}
