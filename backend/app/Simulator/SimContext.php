<?php

namespace App\Simulator;

/** A request made by a simulated person: which run, and which of its virtual clients. */
final readonly class SimContext
{
    public function __construct(public int $runId, public string $client) {}
}
