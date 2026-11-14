<?php

namespace App\Enums;

enum RunStatus: string
{
    case Active = 'active';
    case AwaitingInput = 'awaiting_input';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::AwaitingInput;
    }
}
