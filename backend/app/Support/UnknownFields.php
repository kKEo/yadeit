<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Refuses JSON fields an endpoint does not know. A misspelt or guessed field
 * (`state_id` for `state`) would otherwise be dropped without a word and the
 * caller, often an agent, would believe the change was made.
 */
class UnknownFields
{
    /** @param  array<string, mixed>  $rules */
    public static function reject(Request $request, array $rules): void
    {
        if (! $request->is('api/*') || $request->isMethodSafe() || ! $request->isJson()) {
            return;
        }

        $accepted = [];

        foreach ($rules as $field => $rule) {
            $name = explode('.', (string) $field)[0];
            $accepted[$name] = true;

            // "confirmed" reads a second field, e.g. password_confirmation.
            if (in_array('confirmed', is_string($rule) ? explode('|', $rule) : (array) $rule, true)) {
                $accepted[$name.'_confirmation'] = true;
            }
        }

        $unknown = array_diff(array_map('strval', array_keys($request->json()->all())), array_keys($accepted));

        if ($unknown === []) {
            return;
        }

        $hint = $accepted === []
            ? 'This endpoint takes no fields.'
            : 'This endpoint accepts: '.implode(', ', array_keys($accepted)).'.';

        throw ValidationException::withMessages(
            array_fill_keys($unknown, ["Unknown field. {$hint}"]),
        );
    }
}
