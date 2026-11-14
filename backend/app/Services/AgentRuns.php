<?php

namespace App\Services;

use App\Enums\RunEntryType;
use App\Enums\RunStatus;
use App\Events\WorkspaceChanged;
use App\Exceptions\ApiException;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the log of an agent's work on an item. A run opens when the agent
 * takes the item (or first writes to the log) and closes when it submits the
 * work, fails, or gives the item back. People never have runs.
 */
class AgentRuns
{
    public const MAX_ENTRIES_PER_RUN = 500;

    /** The agent's open run on the item, started now if there is none. */
    public function open(WorkItem $item, User $agent): AgentRun
    {
        return $this->current($item, $agent) ?? AgentRun::create([
            'workspace_id' => $item->workspace_id,
            'work_item_id' => $item->id,
            'agent_id' => $agent->id,
            'status' => RunStatus::Active,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    public function current(WorkItem $item, User $agent): ?AgentRun
    {
        return AgentRun::query()
            ->where('work_item_id', $item->id)
            ->where('agent_id', $agent->id)
            ->whereIn('status', [RunStatus::Active, RunStatus::AwaitingInput])
            ->latest('id')
            ->first();
    }

    /**
     * Adds an entry to the agent's run and moves the run to the status the
     * entry implies. Does nothing for a person, who has no runs.
     */
    public function record(WorkItem $item, User $agent, RunEntryType $type, string $body): ?AgentRun
    {
        if (! $agent->isAgent()) {
            return null;
        }

        $run = $this->open($item, $agent);

        if ($run->entries()->count() >= self::MAX_ENTRIES_PER_RUN) {
            throw new ApiException(409, 'run_log_full', 'This run already has '.self::MAX_ENTRIES_PER_RUN.' entries. Log less often: one entry per meaningful step.');
        }

        $run->entries()->create([
            'workspace_id' => $item->workspace_id,
            'type' => $type,
            'body' => $body,
            'created_at' => now(),
        ]);

        $status = match ($type) {
            RunEntryType::Question => RunStatus::AwaitingInput,
            RunEntryType::Response => RunStatus::Completed,
            RunEntryType::Error => RunStatus::Failed,
            default => RunStatus::Active,
        };

        $run->update([
            'status' => $status,
            'last_activity_at' => now(),
            'ended_at' => $status->isOpen() ? null : now(),
        ]);

        $this->signal($item, $agent);

        return $run;
    }

    /** A person answered: every run waiting on this item goes on. */
    public function resume(WorkItem $item): void
    {
        AgentRun::query()
            ->where('work_item_id', $item->id)
            ->where('status', RunStatus::AwaitingInput)
            ->update(['status' => RunStatus::Active, 'last_activity_at' => now()]);
    }

    /** The agent gave the item back or lost it: its open run ends without a result. */
    public function stop(WorkItem $item, int $agentId): void
    {
        AgentRun::query()
            ->where('work_item_id', $item->id)
            ->where('agent_id', $agentId)
            ->whereIn('status', [RunStatus::Active, RunStatus::AwaitingInput])
            ->update(['status' => RunStatus::Stopped, 'ended_at' => now()]);
    }

    /** Lets open browsers refresh the run log; like every signal it carries no content. */
    private function signal(WorkItem $item, User $agent): void
    {
        $key = $item->key;

        DB::afterCommit(fn () => WorkspaceChanged::dispatch($item->workspace_id, $key, 'run_updated', $agent->id));
    }
}
