<?php

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

// A guard against queries that multiply with the data: every read endpoint is called at two sizes
// and has to cost the same number of queries at both.
it('reads with a number of queries that does not grow with the data', function () {
    [$ws, $owner] = workspaceWith();
    $member = addMember($ws);
    $agent = User::factory()->agent($ws, $owner)->create();
    $b = "/api/v1/workspaces/{$ws->slug}";
    $this->actingAs($owner)->postJson("{$b}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $this->patchJson("{$b}/projects/WEB", ['cycle_weeks' => 2]);
    $this->postJson("{$b}/projects/WEB/labels", ['name' => 'backend']);
    $this->postJson("{$b}/projects/WEB/labels", ['name' => 'ui']);

    $seed = function (int $from, int $to) use ($b, $member, $agent) {
        for ($i = $from; $i <= $to; $i++) {
            $this->postJson("{$b}/items", ['project' => 'WEB', 'title' => "Item {$i} zebra", 'labels' => ['backend', 'ui'], 'assignee' => $member->id, 'delegate' => $agent->id, 'priority' => 'high'] + ($i > 1 ? ['parent' => 'WEB-1'] : []))->assertCreated();
            $this->postJson("{$b}/items/WEB-{$i}/comments", ['body' => 'zebra comment']);
            $this->postJson("{$b}/items/WEB-{$i}/checklist", ['text' => 'check']);
            if ($i > 2) {
                $this->postJson("{$b}/items/WEB-{$i}/relations", ['type' => 'blocks', 'item' => 'WEB-2']);
            }
            $this->postJson("{$b}/feedback", ['project' => 'WEB', 'title' => "Feedback {$i}", 'body' => 'zebra']);
            $this->postJson("{$b}/pages", ['title' => "Page {$i} zebra", 'body' => 'text', 'project' => 'WEB']);
        }
    };

    $urls = [
        'items' => "{$b}/items?limit=100", 'items by state' => "{$b}/items?limit=100&group=state", 'items sub' => "{$b}/items?limit=100&parent=WEB-1",
        'ready' => "{$b}/items/ready", 'detail' => "{$b}/items/WEB-1", 'detail2' => "{$b}/items/WEB-2", 'comments' => "{$b}/items/WEB-1/comments", 'activity' => "{$b}/items/WEB-1/activity",
        'inbox' => "{$b}/inbox", 'projects' => "{$b}/projects", 'project' => "{$b}/projects/WEB", 'cycles' => "{$b}/projects/WEB/cycles",
        'search' => "{$b}/search?q=zebra", 'pages' => "{$b}/pages", 'feedback' => "{$b}/feedback", 'members' => "{$b}/members", 'agents' => "{$b}/agents",
        'views' => "{$b}/views", 'me' => '/api/v1/me', 'export' => "{$b}/export", 'audit' => "{$b}/audit", 'billing' => "{$b}/billing",
    ];
    $measure = function () use ($urls) {
        $out = [];
        foreach ($urls as $name => $url) {
            $n = 0;
            $ms = 0.0;
            DB::listen(function ($q) use (&$n, &$ms) {
                $n++;
                $ms += $q->time;
            });
            $r = $name === 'export' ? $this->get($url) : $this->getJson($url);
            if ($name === 'export') {
                $r->streamedContent();
            }
            $out[$name] = [$r->getStatusCode(), $n];
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $out;
    };

    $seed(1, 4);
    $small = $measure();
    $seed(5, 14);
    $large = $measure();

    foreach ($small as $name => [$status, $queries]) {
        expect($large[$name][0])->toBe(200, "{$name} answered {$large[$name][0]}")
            ->and($large[$name][1])->toBeLessThanOrEqual($queries, "{$name} went from {$queries} to {$large[$name][1]} queries with more data")
            ->and($large[$name][1])->toBeLessThanOrEqual(40, "{$name} needs {$large[$name][1]} queries");
    }
});
