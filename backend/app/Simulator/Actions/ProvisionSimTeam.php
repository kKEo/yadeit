<?php

namespace App\Simulator\Actions;

use App\Actions\CreateProject;
use App\Actions\ProvisionWorkspace;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityFanout;
use App\Services\WorkItemWriter;
use App\Simulator\ContentPack;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
use App\Simulator\Simulator;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Prepares one team of a run. Every team gets its members planned: who they
 * are, which persona and role, and the address and password they sign in
 * with. A "fast" team is then created directly, with a starting backlog; a
 * "real" team is left for its members to sign up and invite each other
 * through the API, as people do.
 */
class ProvisionSimTeam
{
    private const STATES = ['Backlog', 'Backlog', 'Todo', 'Todo', 'Todo', 'In progress', 'In progress', 'In review', 'Done', 'Done', 'Done', 'Cancelled'];

    public function __construct(
        private ProvisionWorkspace $provisionWorkspace,
        private CreateProject $createProject,
        private WorkItemWriter $writer,
        private CurrentWorkspace $current,
    ) {}

    public function handle(SimRun $run, SimTeam $team, int $number): SimTeam
    {
        $pack = ContentPack::load(data_get($run->config, 'pack_id'));
        $random = new Randomizer(new Mt19937(($run->seed + $number * 7919) & 0x7FFFFFFF));

        return DB::transaction(function () use ($run, $team, $number, $pack, $random): SimTeam {
            $members = $this->planMembers($run, $team, $number, $pack, $random);

            if ($team->onboarding !== 'fast') {
                $team->update(['status' => 'awaiting_signup']);

                return $team;
            }

            // Nobody needs an inbox entry, an email or a realtime signal for each item of a backlog that never happened.
            ActivityFanout::muted(fn () => $this->create($run, $team, $members, $pack, $random));
            $team->update(['status' => 'ready']);

            return $team;
        });
    }

    /** @return list<SimMember> */
    private function planMembers(SimRun $run, SimTeam $team, int $number, ContentPack $pack, Randomizer $random): array
    {
        $template = (array) config('simulator.templates.'.max(1, min(10, $team->size)));
        $names = $random->shuffleArray($pack->list('people'));
        // A few explorers per run at most: they are there to look around, not to produce load.
        $room = (int) config('simulator.ai.max_explorers') - SimMember::query()->where('run_id', $run->id)->where('kind', 'ai')->count();
        $explorers = max(0, min($room, (int) data_get($run->config, 'ai.explorers_per_team', 0)));
        $members = [];

        foreach ($template as $index => [$persona, $role]) {
            $kind = $persona === 'agent' ? 'agent' : 'scripted';

            // The owner stays scripted: the team depends on what the lead does.
            if ($kind === 'scripted' && $index > 0 && $explorers > 0) {
                $kind = 'ai';
                $explorers--;
            }

            $members[] = SimMember::create([
                'run_id' => $run->id,
                'team_id' => $team->id,
                'name' => $persona === 'agent' ? 'Agent '.($index + 1) : (string) ($names[$index % max(1, count($names))] ?? 'Member '.($index + 1)),
                'persona' => $persona,
                'role' => $role,
                'kind' => $kind,
                'email' => Simulator::email($run->id, $number, $index + 1),
                'password' => Str::random(24),
            ]);
        }

        return $members;
    }

    /** @param  list<SimMember>  $members */
    private function create(SimRun $run, SimTeam $team, array $members, ContentPack $pack, Randomizer $random): void
    {
        $users = [];

        foreach ($members as $member) {
            // Agents are created by the lead through the API once the run is going, like any agent.
            if ($member->persona === 'agent') {
                continue;
            }

            $user = new User(['name' => $member->name, 'email' => $member->email, 'password' => $member->password]);
            $user->forceFill(['sim_run_id' => $run->id, 'email_verified_at' => now()])->save();
            $member->update(['user_id' => $user->id]);
            $users[] = [$user, $member->role];
        }

        [$owner] = $users[0];
        $workspace = $this->provisionWorkspace->handle($owner, $team->name);

        foreach (array_slice($users, 1) as [$user, $role]) {
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        }

        $team->update(['workspace_id' => $workspace->id]);

        $writers = array_values(array_map(fn (array $u) => $u[0], array_filter($users, fn (array $u) => $u[1] !== 'guest')));
        $volume = (int) config('simulator.volumes.'.data_get($run->config, 'volume', 'small'), 30);
        $projects = $pack->list('projects');

        $this->current->runIn($workspace, function () use ($workspace, $owner, $writers, $projects, $pack, $volume, $random): void {
            $created = [];

            foreach ($projects as $definition) {
                $project = $this->createProject->handle($workspace, $owner, (string) $definition['key'], (string) $definition['name'], $definition['description'] ?? null);
                // One-week cycles and the shortest staleness, so that a run of a few simulated weeks sees both happen.
                $project->update(['cycle_weeks' => 1, 'stale_after_days' => 7]);
                $created[] = [$project, (array) ($definition['items'] ?? [])];
            }

            $comments = $pack->list('comments');

            for ($i = 0; $i < $volume && $created !== []; $i++) {
                /** @var Project $project */
                [$project, $items] = $created[$i % count($created)];

                if ($items === []) {
                    continue;
                }

                $round = intdiv($i, count($created));
                $source = (array) $items[$round % count($items)];
                $lap = intdiv($round, count($items));
                $author = $writers[$random->getInt(0, count($writers) - 1)];

                $item = $this->writer->create($project, [
                    'title' => $source['title'].($lap > 0 ? ' ('.($lap + 1).')' : ''),
                    'description' => $source['description'] ?? null,
                    'kind' => $source['kind'] ?? 'task',
                    'priority' => $source['priority'] ?? 'none',
                    'state' => self::STATES[$random->getInt(0, count(self::STATES) - 1)],
                    'assignee' => $random->getInt(0, 3) === 0 ? null : $writers[$random->getInt(0, count($writers) - 1)]->id,
                ], $author);

                for ($c = $random->getInt(0, 3); $c > 0 && $comments !== []; $c--) {
                    Comment::create([
                        'workspace_id' => $workspace->id,
                        'work_item_id' => $item->id,
                        'user_id' => $writers[$random->getInt(0, count($writers) - 1)]->id,
                        'body' => (string) $comments[$random->getInt(0, count($comments) - 1)],
                    ]);
                }
            }

            foreach ($volume > 0 ? $pack->list('pages') : [] as $index => $page) {
                Page::create([
                    'workspace_id' => $workspace->id,
                    'project_id' => null,
                    'title' => (string) $page['title'],
                    'body' => (string) ($page['body'] ?? ''),
                    'status' => $index === 0 ? 'draft' : 'published',
                    'version' => 1,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);
            }
        });
    }
}
