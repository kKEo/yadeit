<?php

namespace App\Simulator\Actions;

use App\Models\Workspace;
use App\Simulator\Models\SimMetricBucket;
use App\Simulator\Models\SimRun;
use App\Simulator\SimReport;
use App\Simulator\Simulator;
use Illuminate\Support\Facades\DB;

/**
 * Removes everything a run created: its workspaces with their content and
 * files, its accounts with their sessions and tokens, their traces in the mail
 * and audit logs, and what the run measured. With $forget the run itself goes
 * too; otherwise its row and summary stay as a record.
 */
class PurgeSimRun
{
    public function handle(SimRun $run, bool $forget = false): void
    {
        // The numbers go with the purge; what they added up to stays on the run.
        if ($run->summary === null || SimMetricBucket::query()->where('run_id', $run->id)->exists()) {
            app(SimReport::class)->save($run);
        }

        $workspaceIds = Workspace::query()->where('sim_run_id', $run->id)->pluck('id');
        $userIds = DB::table('users')->where('sim_run_id', $run->id)
            ->when($workspaceIds->isNotEmpty(), fn ($q) => $q->orWhereIn('workspace_id', $workspaceIds))->pluck('id');
        $addresses = 'r'.$run->id.'-%@'.Simulator::mailDomain();

        // One by one: deleting the model is what removes the uploaded files.
        Workspace::query()->whereIn('id', $workspaceIds)->get()->each->delete();

        DB::transaction(function () use ($run, $workspaceIds, $userIds, $addresses, $forget): void {
            foreach ($userIds->chunk(500) as $ids) {
                DB::table('sessions')->whereIn('user_id', $ids)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $ids)->delete();
                DB::table('audit_events')->whereIn('actor_id', $ids)->delete();
                DB::table('users')->whereIn('id', $ids)->delete();
            }

            foreach ($workspaceIds->chunk(500) as $ids) {
                DB::table('audit_events')->whereIn('workspace_id', $ids)->delete();
            }

            DB::table('outgoing_emails')->where('to_email', 'like', $addresses)->delete();
            DB::table('sim_mailbox')->where('run_id', $run->id)->orWhere('to_email', 'like', $addresses)->delete();

            foreach (['sim_storylines', 'sim_findings', 'sim_metric_buckets', 'sim_members', 'sim_teams', 'sim_runners'] as $table) {
                DB::table($table)->where('run_id', $run->id)->delete();
            }

            if ($forget) {
                $run->delete();
            }
        });
    }
}
