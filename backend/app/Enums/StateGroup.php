<?php

namespace App\Enums;

/** The fixed meaning behind every custom state name. All logic keys off the group. */
enum StateGroup: string
{
    case Triage = 'triage';
    case Backlog = 'backlog';
    case Unstarted = 'unstarted';
    case Started = 'started';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }
}
