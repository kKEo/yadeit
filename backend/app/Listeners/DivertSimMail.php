<?php

namespace App\Listeners;

use App\Simulator\Models\SimMail;
use App\Simulator\Simulator;
use Illuminate\Mail\Events\MessageSending;

/**
 * Mail to a simulated person never leaves. The messages a simulated person
 * has to act on (confirm the address, accept an invitation, reset a password)
 * are kept with their links, so the runner can follow them like a person
 * opening the email. Returning false is what stops the message.
 */
class DivertSimMail
{
    private const KEPT = ['VerifyEmail', 'WorkspaceInvitation', 'ResetPassword'];

    public function handle(MessageSending $event): ?bool
    {
        $simulated = array_filter($event->message->getTo(), fn ($address) => Simulator::isSimEmail($address->getAddress()));

        if ($simulated === []) {
            return null;
        }

        $kind = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;
        $kind = is_string($kind) ? class_basename($kind) : 'Mail';

        if (Simulator::enabled() && in_array($kind, self::KEPT, true)) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            preg_match_all('/href="([^"]+)"/', (string) $event->message->getHtmlBody(), $found);
            $links = array_values(array_unique(array_filter(
                array_map(fn (string $href) => html_entity_decode($href), $found[1]),
                fn (string $href) => str_starts_with($href, $frontend.'/'),
            )));

            foreach ($simulated as $address) {
                $email = mb_strtolower($address->getAddress());
                SimMail::create([
                    'run_id' => Simulator::runIdOf($email),
                    'to_email' => $email,
                    'kind' => $kind,
                    'subject' => mb_substr((string) $event->message->getSubject(), 0, 255),
                    'links' => $links,
                    'created_at' => now(),
                ]);
            }
        }

        return false;
    }
}
