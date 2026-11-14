<?php

namespace App\Exceptions;

use RuntimeException;

/** An error with a stable machine-readable code and optional details for the client. */
class ApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
