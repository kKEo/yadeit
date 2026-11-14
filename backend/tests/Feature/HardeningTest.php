<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Support\WebhookTarget;

it('treats only the public internet as a webhook target', function (string $address, bool $public) {
    expect(WebhookTarget::isPublic($address))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['100.127.255.254', false],
    ['100.128.0.1', true],
    ['198.18.0.1', false],
    ['192.0.0.1', false],
    ['::1', false],
    ['64:ff9b::7f00:1', false],
    ['2002:7f00:1::', false],
    ['::ffff:10.0.0.1', false],
]);

it('keeps a project-limited token from writing workspace-wide pages', function () {
    [$workspace, $owner] = workspaceWith();
    $base = "/api/v1/workspaces/{$workspace->slug}";
    $this->actingAs($owner)->postJson("{$base}/projects", ['name' => 'Website', 'key' => 'WEB'])->assertCreated();
    $projectId = Project::query()->withoutGlobalScopes()->where('key', 'WEB')->value('id');
    $agent = User::factory()->agent($workspace, $owner)->create();
    $token = $agent->createToken('scoped', ['read', 'write', "project:{$projectId}"])->plainTextToken;

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withoutHeader('Origin')->withToken($token);

    $this->postJson("{$base}/pages", ['title' => 'Everywhere'])->assertUnprocessable()->assertJsonPath('error.details.fields.project.0', fn ($m) => str_contains($m, 'limited to certain projects'));
    $id = $this->postJson("{$base}/pages", ['title' => 'Scoped', 'project' => 'WEB'])->assertCreated()->json('data.id');
    $this->patchJson("{$base}/pages/{$id}", ['project' => null])->assertUnprocessable();
});

it('does not log failed sign-ins that are not attempts on an address', function () {
    $this->postJson('/api/v1/auth/login', ['email' => str_repeat('a', 5000), 'password' => 'x']);
    $this->postJson('/api/v1/auth/login', ['email' => ['a' => 'b'], 'password' => 'x']);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('lets a workspace lower but not raise its AI allowance on the installation\'s key', function () {
    [$workspace, $owner] = workspaceWith();
    $default = (int) config('ai.monthly_token_limit');
    $ai = "/api/v1/workspaces/{$workspace->slug}/ai";

    $this->actingAs($owner)->putJson($ai, ['monthly_token_limit' => $default * 10])->assertOk()->assertJsonPath('data.monthly_token_limit', $default);
    $this->putJson($ai, ['monthly_token_limit' => 1000])->assertOk()->assertJsonPath('data.monthly_token_limit', 1000);
});
