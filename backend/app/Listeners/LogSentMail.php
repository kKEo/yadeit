<?php

namespace App\Listeners;

use App\Models\OutgoingEmail;
use App\Notifications\Announcement;
use Illuminate\Mail\Events\MessageSent;

/**
 * Records every email that went out: who, what kind, which subject, when.
 * The text is not kept: sign-in and reset emails carry links that open an
 * account, and those do not belong in a log.
 */
class LogSentMail
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        if ($message->getHeaders()->has(Announcement::HEADER)) {
            return;
        }

        $kind = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;

        foreach ($message->getTo() as $address) {
            OutgoingEmail::create([
                'kind' => is_string($kind) ? class_basename($kind) : 'Mail',
                'to_email' => mb_strtolower($address->getAddress()),
                'to_name' => $address->getName() !== '' ? $address->getName() : null,
                'subject' => mb_substr((string) $message->getSubject(), 0, 255),
                'status' => OutgoingEmail::SENT,
                'attempts' => 1,
                'sent_at' => now(),
            ]);
        }
    }
}
