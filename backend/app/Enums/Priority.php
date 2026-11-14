<?php

namespace App\Enums;

/** Stored as a number so that sorting by priority works in the database. */
enum Priority: int
{
    case None = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Urgent = 4;

    public function label(): string
    {
        return strtolower($this->name);
    }

    public static function fromLabel(string $label): self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        throw new \ValueError("Unknown priority [{$label}].");
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (self $case) => $case->label(), self::cases());
    }
}
