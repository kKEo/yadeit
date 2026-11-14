<?php

namespace App\Enums;

/** What an agent writes into its run log. */
enum RunEntryType: string
{
    /** Reasoning or a plan. */
    case Thought = 'thought';
    /** Something it did: a command, an edit, a pull request. */
    case Action = 'action';
    /** A question to a person; the run waits for the answer. */
    case Question = 'question';
    /** The result handed over for review; ends the run. */
    case Response = 'response';
    /** Why it could not go on; ends the run as failed. */
    case Error = 'error';

    /**
     * The types an agent writes directly; questions and responses come from ask and submit-for-review.
     *
     * @return list<string>
     */
    public static function loggable(): array
    {
        return [self::Thought->value, self::Action->value, self::Error->value];
    }
}
