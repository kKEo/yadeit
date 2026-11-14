<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Member => 20,
            self::Guest => 10,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
