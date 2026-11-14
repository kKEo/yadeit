<?php

return [
    // Seconds to wait before each retry of an event that could not be delivered.
    'retry_after' => [10, 60, 300, 1800],

    // Seconds a receiver has to answer.
    'timeout' => 10,

    // A webhook switches itself off after this many events in a row failed for good.
    'disable_after_failures' => 10,

    // Receivers on private or loopback addresses are refused, so a webhook cannot be
    // pointed at the server's own network. Allow them only for local development.
    'allow_private_targets' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
];
