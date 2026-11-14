<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('log_progress')]
#[Description('Notes a step in your run log on an item you hold, for the people following your work: thought (plan or reasoning), action (something done), error (you cannot go on; ends the run as failed). Log meaningful steps, not every command.')]
class LogProgress extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'type' => $schema->string()->enum(['thought', 'action', 'error'])->required(),
            'body' => $schema->string()->description('Markdown')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $result = $this->api('POST', 'items/'.rawurlencode((string) $request->get('key')).'/log', $this->only($request, ['type', 'body']));

        return $result->ok()
            ? $this->result(['logged' => true, 'run_status' => $result->data()['status'], 'entries' => count($result->data()['entries'])])
            : $this->failure($result);
    }
}
