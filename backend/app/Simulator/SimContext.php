<?php

namespace App\Simulator;

/** A request made by a simulated person: which run, and which of its virtual clients. */
final readonly class SimContext
{
    /** @param  int  $speed  How many times faster than organic the run plays. */
    public function __construct(public int $runId, public string $client, public int $speed = 1) {}
}
