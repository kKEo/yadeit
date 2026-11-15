<?php

namespace App\Simulator;

use App\Ai\AiPrompt;
use App\Ai\AiProvider;
use App\Exceptions\ApiException;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimStoryline;
use App\Simulator\Models\SimTeam;
use Illuminate\Support\Facades\DB;

/**
 * The only way the Traffic lab talks to a model. Runners never hold the key:
 * they ask here. Every call of a run is counted against the run's token
 * budget and refused once it is spent, so a run cannot cost more than it was
 * allowed to. Everything the model reads here was written by the simulation
 * itself, and everything it answers is a choice from a fixed list or text
 * for simulated workspaces.
 */
class SimAi
{
    /** Actions a storyline or an explorer may choose, with what each means. */
    public const ACTIONS = [
        'openItems' => 'look at the list of items',
        'openMine' => 'look at the items assigned to me',
        'filterItems' => 'narrow the list to one project',
        'openItem' => 'open one item and read its thread',
        'createItem' => 'create a new item',
        'moveItem' => 'move an item forward (started, in review, done)',
        'editItem' => 'change an item\'s priority or assignee',
        'comment' => 'comment on an item',
        'attach' => 'attach a file to an item',
        'inbox' => 'read the inbox',
        'search' => 'search for something',
        'docs' => 'look at the list of docs',
        'openDoc' => 'read a doc',
        'editDoc' => 'edit a doc',
        'createDoc' => 'write a new doc',
        'feedbackList' => 'look at the feedback inbox',
        'sendFeedback' => 'send feedback',
        'triage' => 'accept or decline a piece of feedback',
        'members' => 'look at the members page',
        'settings' => 'open the workspace settings',
    ];

    public function __construct(private AiProvider $provider) {}

    /**
     * A new content pack for a theme.
     *
     * @return array<string, mixed>
     */
    public function writePack(string $name, string $theme): array
    {
        $texts = ['type' => 'array', 'items' => ['type' => 'string']];
        $titled = fn (array $extra) => ['type' => 'array', 'items' => self::object(['title' => ['type' => 'string']] + $extra)];

        $data = $this->complete(null, new AiPrompt(
            'sim_pack',
            'You write realistic sample content for a project tracker, used to test it under load. Invent a believable team working on the given theme. Write plainly, the way busy people write in a tracker; vary length and tone. No real people, companies or products. Give: 20 full names of people from different countries; 12 short team or studio names; exactly 2 projects, each with a key of 2 to 4 capital letters, a name, a one-sentence description and 12 to 15 items (title; a Markdown description of one to five sentences; kind; priority); 15 short comments such as teammates leave on items; 3 short Markdown docs (title and body); 6 pieces of feedback as users would send them (title and body).',
            "<theme>\n".str_replace('</', '< /', $theme)."\n</theme>",
            self::object([
                'people' => $texts,
                'teams' => $texts,
                'projects' => ['type' => 'array', 'items' => self::object([
                    'key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'items' => $titled([
                        'description' => ['type' => 'string'],
                        'kind' => ['type' => 'string', 'enum' => ['task', 'bug', 'feature', 'design', 'research']],
                        'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                    ]),
                ])],
                'comments' => $texts,
                'pages' => $titled(['body' => ['type' => 'string']]),
                'feedback' => $titled(['body' => ['type' => 'string']]),
            ]),
            (string) config('ai.model'),
        ));

        // What a run cannot do without. The model's answer follows the schema, but a list may still be empty.
        foreach (['people', 'teams', 'projects', 'comments'] as $key) {
            if (empty($data[$key])) {
                throw new ApiException(502, 'ai_incomplete', "The pack came back without {$key}. Try again.");
            }
        }

        $data['projects'] = array_map(fn (array $p) => ['key' => strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', $p['key']), 0, 4)) ?: 'PRJ'] + $p, $data['projects']);

        return ['name' => $name, 'theme' => $theme] + $data;
    }

    /**
     * What one team does on one simulated day: an ordered list of who does
     * what. Kept, so the day is played the same when asked again, and so a
     * later run can replay it.
     *
     * @return list<array{member: int, action: string, text: string|null}>
     */
    public function storyline(SimRun $run, SimTeam $team, int $day): array
    {
        $kept = SimStoryline::query()->where('team_id', $team->id)->where('sim_day', $day)->first()
            ?? $this->replayed($run, $team, $day);

        if ($kept !== null) {
            return $kept->intents;
        }

        if (! data_get($run->config, 'ai.director')) {
            return [];
        }

        $members = SimMember::query()->where('team_id', $team->id)->where('kind', 'scripted')->orderBy('id')->get()->values();
        $pack = ContentPack::load(data_get($run->config, 'pack_id'));
        $roster = $members->map(fn (SimMember $m, int $i) => ($i + 1).": {$m->name}, {$m->persona}, role {$m->role}")->implode("\n");
        $actions = collect(self::ACTIONS)->map(fn (string $what, string $name) => "{$name}: {$what}")->implode("\n");
        $weekday = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day % 7];

        $data = $this->complete($run, new AiPrompt(
            'sim_director',
            'You direct one working day of a small team in a project tracker, for a simulation. Write the story of the day as 12 to 25 steps in the order they happen: who does what. People react to each other: an item is created, someone picks it up, asks about it, it moves on, feedback comes in and is triaged. Fit each step to the person\'s job and role: a guest can only read, comment and send feedback; only owners and admins triage feedback or open settings. Use only the listed actions. For createItem, comment, createDoc and sendFeedback give the text (an item or doc title, a comment, a feedback title) in the team\'s voice; otherwise leave text empty.',
            "<team>\n{$team->name}, working on: ".($pack->content['theme'] ?? 'a software product')."\n</team>\n<day>\n{$weekday}, day ".($day + 1)." of the simulation\n</day>\n<people>\n{$roster}\n</people>\n<actions>\n{$actions}\n</actions>",
            self::object(['steps' => ['type' => 'array', 'items' => self::object([
                'member' => ['type' => 'integer'],
                'action' => ['type' => 'string', 'enum' => array_keys(self::ACTIONS)],
                'text' => ['type' => 'string'],
            ])]]),
            (string) config('simulator.ai.model'),
        ));

        // Only what can be played: a known person and a known action.
        $intents = collect((array) ($data['steps'] ?? []))
            ->filter(fn ($step) => is_array($step) && isset(self::ACTIONS[$step['action'] ?? '']) && isset($members[((int) ($step['member'] ?? 0)) - 1]))
            ->map(fn (array $step) => ['member' => (int) $step['member'], 'action' => (string) $step['action'], 'text' => trim((string) ($step['text'] ?? '')) ?: null])
            ->take(40)->values()->all();

        SimStoryline::query()->insertOrIgnore([
            'run_id' => $run->id, 'team_id' => $team->id, 'sim_day' => $day, 'intents' => json_encode($intents), 'created_at' => now(),
        ]);

        return $intents;
    }

    /**
     * What an explorer does next, chosen by the model from what the member may do.
     *
     * @param  list<string>  $options
     * @param  list<string>  $history
     * @return array{action: string, reason: string, stuck: string|null}
     */
    public function decide(SimRun $run, SimMember $member, array $options, array $history, string $seen): array
    {
        $options = array_values(array_intersect($options, array_keys(self::ACTIONS)));

        if ($options === [] || $member->kind !== 'ai') {
            throw new ApiException(422, 'validation_failed', 'This member is not an explorer, or has nothing to choose from.');
        }

        $list = implode("\n", array_map(fn (string $name) => "{$name}: ".self::ACTIONS[$name], $options));

        $data = $this->complete($run, new AiPrompt(
            'sim_explorer',
            "You play {$member->name}, a {$member->persona} with the role \"{$member->role}\" in a team's project tracker. Behave like a curious person getting their work done: vary what you do, follow up on what you just saw, and do not repeat the same step over and over. Choose your next step from the list. Give a short reason. If what happened so far suggests something is broken, confusing or impossible to do (a step that keeps failing, nothing to work on, an answer that does not fit what you did), describe it in one or two sentences in \"stuck\"; otherwise leave \"stuck\" empty.",
            "<what_you_see>\n".str_replace('</', '< /', mb_substr($seen, 0, 2000))."\n</what_you_see>\n<your_last_steps>\n".(implode("\n", array_slice($history, -10)) ?: '(none yet)')."\n</your_last_steps>\n<possible_steps>\n{$list}\n</possible_steps>",
            self::object([
                'action' => ['type' => 'string', 'enum' => $options],
                'reason' => ['type' => 'string'],
                'stuck' => ['type' => 'string'],
            ]),
            (string) config('simulator.ai.model'),
        ));

        $action = in_array($data['action'] ?? null, $options, true) ? (string) $data['action'] : $options[0];

        return ['action' => $action, 'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 300), 'stuck' => trim((string) ($data['stuck'] ?? '')) ?: null];
    }

    /** The storyline the same team (by its place in the run) had on that day in the run being replayed, copied to this run. */
    private function replayed(SimRun $run, SimTeam $team, int $day): ?SimStoryline
    {
        $source = data_get($run->config, 'ai.replay_run_id');

        if ($source === null) {
            return null;
        }

        $place = SimTeam::query()->where('run_id', $run->id)->where('id', '<', $team->id)->count();
        $twin = SimTeam::query()->where('run_id', $source)->orderBy('id')->skip($place)->first();
        $old = $twin === null ? null : SimStoryline::query()->where('team_id', $twin->id)->where('sim_day', $day)->first();

        return $old === null ? null : SimStoryline::create([
            'run_id' => $run->id, 'team_id' => $team->id, 'sim_day' => $day, 'intents' => $old->intents, 'created_at' => now(),
        ]);
    }

    /**
     * One call to the model, against the budget of the run when there is one.
     *
     * @return array<string, mixed>
     */
    private function complete(?SimRun $run, AiPrompt $prompt): array
    {
        $key = config('ai.api_key');

        if (! is_string($key) || $key === '') {
            throw new ApiException(503, 'ai_not_configured', 'The installation has no AI provider key (ANTHROPIC_API_KEY), which the AI layers of the Traffic lab need.');
        }

        if ($run !== null) {
            $budget = (int) data_get($run->config, 'ai.token_budget', 0);

            if ($run->refresh()->ai_tokens_used >= $budget) {
                throw new ApiException(429, 'ai_budget_spent', 'The AI token budget of this run is spent.');
            }
        }

        $completion = $this->provider->complete($prompt, $key);

        if ($run !== null) {
            DB::table('sim_runs')->where('id', $run->id)->increment('ai_tokens_used', $completion->inputTokens + $completion->outputTokens);
        }

        return $completion->data;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
