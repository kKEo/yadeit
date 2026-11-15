<?php

namespace App\Simulator;

enum SimRunStatus: string
{
    case Draft = 'draft';
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Paused = 'paused';
    case Stopping = 'stopping';
    case Finished = 'finished';
    case Failed = 'failed';
    case Aborted = 'aborted';

    /** While a run is going on its signature is accepted and its runners are served. */
    public function isActive(): bool
    {
        return in_array($this, [self::Provisioning, self::Running, self::Paused, self::Stopping], true);
    }
}
