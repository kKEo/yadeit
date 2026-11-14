<?php

namespace App\Notifications;

use App\Models\InboxItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The email twin of an inbox entry. */
class ItemActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $workspaceSlug,
        public string $itemKey,
        public string $itemTitle,
        public string $event,
        public ?string $actorName,
    ) {}

    public static function for(InboxItem $entry, string $workspaceSlug): self
    {
        return new self($workspaceSlug, $entry->workItem->key, $entry->workItem->title, $entry->event, $entry->actor?->name);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->actorName ?? 'Someone';

        $what = match ($this->event) {
            'assigned' => "{$who} assigned {$this->itemKey} to you",
            'commented' => "{$who} commented on {$this->itemKey}",
            'input_requested' => "{$who} needs your input on {$this->itemKey}",
            'run_failed' => "{$who} could not finish {$this->itemKey}",
            'submitted_for_review' => "{$who} submitted {$this->itemKey} for review",
            'state_changed' => "{$who} changed the state of {$this->itemKey}",
            default => "{$who} updated {$this->itemKey}",
        };

        return (new MailMessage)
            ->subject("[{$this->itemKey}] {$this->itemTitle}")
            ->line($what.': '.$this->itemTitle)
            ->action('Open '.$this->itemKey, $this->url())
            ->line('You can turn these emails off in your profile.');
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/w/{$this->workspaceSlug}/items/{$this->itemKey}";
    }
}
