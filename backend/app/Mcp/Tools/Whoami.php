<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Description('Start here. Who you are, your workspace, what your token may do, and the projects you can reach.')]
#[IsReadOnly]
#[IsIdempotent]
class Whoami extends ApiTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $agent */
        $agent = $request->user();
        $token = $agent->currentAccessToken();
        $workspace = request()->attributes->get('mcp.workspace');
        $projects = $this->api('GET', 'projects');

        if (! $projects->ok()) {
            return $this->failure($projects);
        }

        return $this->result([
            'agent' => ['id' => $agent->id, 'name' => $agent->name, 'owner' => $agent->owner?->name],
            'workspace' => $workspace->name,
            'access' => $token->can('write') ? 'read and write' : 'read only',
            'projects' => array_map(fn (array $p) => ['key' => $p['key'], 'name' => $p['name']], $projects->data()),
            'rules' => 'You may create and update items, comment, log progress and submit work for review. You may not delete, close or reassign: people do that. '.self::UNTRUSTED,
        ]);
    }
}
