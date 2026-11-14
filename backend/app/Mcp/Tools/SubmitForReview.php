<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('submit_for_review')]
#[Description('Hands finished work to a person: moves the item to the review state, posts your summary as a comment and ends your run. Link the pull request first with link.')]
class SubmitForReview extends ApiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Item key, e.g. WEB-12')->required(),
            'summary' => $schema->string()->description('Markdown: what you did, how it was verified, what is left open')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $result = $this->api('POST', 'items/'.rawurlencode((string) $request->get('key')).'/submit-for-review', ['summary' => $request->get('summary')]);

        return $result->ok()
            ? $this->result(['submitted' => true, 'state' => $result->data()['state']['name'], 'next' => 'A person reviews it now. You are done with this item unless it comes back.'])
            : $this->failure($result);
    }
}
