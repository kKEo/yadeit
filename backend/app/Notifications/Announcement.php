<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/** An announcement from the operator. Sent from the outbox, one row per recipient. */
class Announcement extends Notification
{
    /** Marks mail that the outbox already tracks, so the mail log does not record it twice. */
    public const HEADER = 'X-Yadeit-Outbox';

    public function __construct(public string $subject, public string $body, public int $outboxId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line(self::safeMarkdown($this->body))
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader(self::HEADER, (string) $this->outboxId));
    }

    /** The text keeps its Markdown and its line breaks, and loses any HTML. */
    public static function safeMarkdown(string $body): HtmlString
    {
        return new HtmlString(str_replace(['&', '<'], ['&amp;', '&lt;'], $body));
    }
}
