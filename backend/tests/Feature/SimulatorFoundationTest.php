<?php

use App\Ai\AiProvider;
use App\Models\OutgoingEmail;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Outbox;
use App\Simulator\Actions\ProvisionSimTeam;
use App\Simulator\Actions\PurgeSimRun;
use App\Simulator\Models\SimMail;
use App\Simulator\Models\SimMember;
use App\Simulator\Models\SimRun;
use App\Simulator\Models\SimTeam;
use App\Simulator\Simulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

function simRun(array $config = [], string $status = 'running'): SimRun
{
    return SimRun::create(['name' => 'Test run', 'status' => $status, 'config' => $config + ['volume' => 'small'], 'seed' => 7]);
}

function simTeam(SimRun $run, int $size = 5, string $onboarding = 'fast', int $number = 1): SimTeam
{
    $team = SimTeam::create(['run_id' => $run->id, 'name' => "Team {$number}", 'size' => $size, 'onboarding' => $onboarding]);

    return app(ProvisionSimTeam::class)->handle($run, $team, $number);
}

function signUp(array $headers, string $email): mixed
{
    return test()->withHeaders($headers)->postJson('/api/v1/auth/register', [
        'name' => 'Sim Person', 'email' => $email, 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password',
    ]);
}

beforeEach(fn () => config(['simulator.enabled' => true]));

it('does not exist while it is switched off', function () {
    $operator = User::factory()->create(['is_operator' => true]);

    $this->actingAs($operator)->getJson('/api/v1/admin/sim')->assertOk()->assertJsonPath('data.enabled', true);

    config(['simulator.enabled' => false]);
    $this->getJson('/api/v1/admin/sim')->assertNotFound();
    expect(Simulator::enabled())->toBeFalse();
});

it('is closed to everyone who is not an operator', function () {
    [, $owner] = workspaceWith();

    $this->actingAs($owner)->getJson('/api/v1/admin/sim')->assertNotFound();
});

it('needs a second switch in production', function () {
    $this->app['env'] = 'production';
    expect(Simulator::enabled())->toBeFalse();

    $this->actingAs(User::factory()->create(['is_operator' => true]))->getJson('/api/v1/admin/sim')->assertNotFound();

    config(['simulator.allow_production' => true]);
    expect(Simulator::enabled())->toBeTrue();
    $this->getJson('/api/v1/admin/sim')->assertOk()->assertJsonPath('data.production', true);
});

it('keeps mail to simulated people from leaving and keeps the link they need', function () {
    $run = simRun();
    $email = Simulator::email($run->id, 1, 1);

    signUp([Simulator::HEADER => $run->headerFor('t1m1')], $email)->assertCreated();

    expect(User::query()->where('email', $email)->value('sim_run_id'))->toBe($run->id)
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(OutgoingEmail::query()->count())->toBe(0);

    $mail = SimMail::query()->where('to_email', $email)->sole();
    expect($mail->kind)->toBe('VerifyEmail')->and($mail->run_id)->toBe($run->id)
        ->and($mail->links[0])->toContain('/verify-email?');
});

it('gives simulator addresses only to requests signed by a run that is going on', function () {
    $run = simRun();
    $email = Simulator::email($run->id, 1, 2);

    signUp([], $email)->assertUnprocessable();
    signUp([Simulator::HEADER => "{$run->id}.t1m2.".str_repeat('0', 64)], $email)->assertUnprocessable();

    $finished = simRun(status: 'finished');
    signUp([Simulator::HEADER => $finished->headerFor('t1m2')], Simulator::email($finished->id, 1, 2))->assertUnprocessable();

    expect(User::query()->whereNotNull('sim_run_id')->count())->toBe(0);
});

it('counts address limits per simulated person, and gives a forged header nothing', function () {
    $run = simRun();
    $attempt = fn (string $header) => $this->withHeaders([Simulator::HEADER => $header])
        ->postJson('/api/v1/auth/login', ['email' => fake()->unique()->safeEmail(), 'password' => 'wrong-password']);

    // Fifteen simulated people from one address: none is held back by the ten-a-minute limit per address.
    foreach (range(1, 15) as $n) {
        $response = $attempt($run->headerFor("m{$n}"));
        expect($response->status())->not->toBe(429);
        expect($response->headers->get('Server-Timing'))->toContain('queries');
    }

    // The same with a made-up signature counts against the address like anyone else.
    $statuses = array_map(fn (int $n) => $attempt("{$run->id}.x{$n}.".str_repeat('a', 64))->status(), range(1, 12));
    expect($statuses)->toContain(429);
});

it('creates a team directly, with its roles and a starting backlog', function () {
    $run = simRun();
    $team = simTeam($run, 7);

    $workspace = Workspace::query()->findOrFail($team->workspace_id);
    expect($team->status)->toBe('ready')
        ->and($workspace->sim_run_id)->toBe($run->id)
        ->and(SimMember::query()->where('team_id', $team->id)->count())->toBe(7)
        // The agent of a seven-person team joins later, through the API.
        ->and($workspace->memberships()->count())->toBe(6)
        ->and($workspace->memberships()->pluck('role')->map->value->sort()->values()->all())->toBe(['admin', 'guest', 'member', 'member', 'member', 'owner'])
        ->and(DB::table('work_items')->where('workspace_id', $workspace->id)->count())->toBe(30 + 3)
        ->and(DB::table('projects')->where('workspace_id', $workspace->id)->count())->toBe(3)
        ->and(DB::table('pages')->where('workspace_id', $workspace->id)->count())->toBe(3)
        // Seeding told nobody: no inbox entries, no queued work.
        ->and(DB::table('inbox_items')->count())->toBe(0);

    // A member can sign in with the password the run holds for them.
    $member = SimMember::query()->where('team_id', $team->id)->where('persona', 'developer')->firstOrFail();
    $this->withHeaders([Simulator::HEADER => $run->headerFor('m')])
        ->postJson('/api/v1/auth/login', ['email' => $member->email, 'password' => $member->password])->assertOk();
});

it('gives the same team for the same seed', function () {
    $titles = function (): array {
        $team = simTeam(simRun(), 3);

        return DB::table('work_items')->where('workspace_id', $team->workspace_id)->orderBy('id')->pluck('title')->all();
    };

    expect($titles())->toBe($titles());
});

it('leaves a team that signs up for real to its members', function () {
    $run = simRun();
    $team = simTeam($run, 4, 'real');

    expect($team->status)->toBe('awaiting_signup')->and($team->workspace_id)->toBeNull()
        ->and(SimMember::query()->where('team_id', $team->id)->whereNull('user_id')->count())->toBe(4)
        ->and(User::query()->whereNotNull('sim_run_id')->count())->toBe(0);

    // The lead signs up and creates the workspace through the API; both are marked as part of the run.
    $lead = SimMember::query()->where('team_id', $team->id)->orderBy('id')->firstOrFail();
    signUp([Simulator::HEADER => $run->headerFor('lead')], $lead->email)->assertCreated();
    $this->postJson('/api/v1/workspaces', ['name' => $team->name])->assertCreated();

    expect(Workspace::query()->where('sim_run_id', $run->id)->count())->toBe(1);
});

it('keeps simulated people and workspaces out of the numbers, the lists and the announcements', function () {
    [$real] = workspaceWith();
    $team = simTeam(simRun(), 5);
    $operator = User::factory()->create(['is_operator' => true]);

    $this->actingAs($operator)->getJson('/api/v1/admin/overview')->assertOk()
        ->assertJsonPath('data.workspaces', 1)->assertJsonPath('data.people', 2)->assertJsonPath('data.items', 0);

    $this->getJson('/api/v1/admin/workspaces')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $real->slug);
    $this->getJson('/api/v1/admin/workspaces?simulated=true')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $team->workspace_id)->assertJsonPath('data.0.simulated', true);
    $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/users?simulated=true')->assertOk()->assertJsonCount(5, 'data');

    expect(app(Outbox::class)->recipients(['audience' => 'everyone', 'important' => true])->count())->toBe(2);
});

it('does not hold a simulated team to plan limits, and never takes it to checkout', function () {
    config(['plans.enforce' => true, 'cashier.secret' => 'sk_test_x', 'plans.plans.team.stripe_price' => 'price_x']);
    $team = simTeam(simRun(), 2);
    $workspace = Workspace::query()->findOrFail($team->workspace_id);
    $owner = User::query()->findOrFail($workspace->created_by);

    // Three projects exist already (the sample and two from the pack); the free plan stops at three.
    $this->actingAs($owner)->postJson("/api/v1/workspaces/{$workspace->slug}/projects", ['name' => 'Fourth', 'key' => 'FOUR'])->assertCreated();
    $this->postJson("/api/v1/workspaces/{$workspace->slug}/billing/checkout", ['plan' => 'team'])->assertStatus(409);
});

it('answers AI help in a simulated workspace without calling a model', function () {
    $fake = new FakeAiProvider;
    $this->app->instance(AiProvider::class, $fake);
    $team = simTeam(simRun(), 2);
    $workspace = Workspace::query()->findOrFail($team->workspace_id);
    $workspace->forceFill(['ai_enabled' => true])->save();

    $this->actingAs(User::query()->findOrFail($workspace->created_by))
        ->postJson("/api/v1/workspaces/{$workspace->slug}/ai/draft-item", ['project' => 'WEB', 'text' => 'Customers cannot change their email address'])
        ->assertOk()->assertJsonPath('data.kind', 'task');

    expect($fake->prompts)->toBe([]);
});

it('lets a simulated workspace send webhooks only to the receiver of the lab', function () {
    $team = simTeam(simRun(), 1);
    $workspace = Workspace::query()->findOrFail($team->workspace_id);
    $base = "/api/v1/workspaces/{$workspace->slug}/webhooks";

    $this->actingAs(User::query()->findOrFail($workspace->created_by))
        ->postJson($base, ['name' => 'Out', 'url' => 'https://example.com/hook'])->assertUnprocessable();
    $this->postJson($base, ['name' => 'Sink', 'url' => Simulator::sinkUrl()])->assertCreated();
});

it('removes everything a run created', function () {
    Storage::fake('local');
    [$real, $realOwner] = workspaceWith();
    $run = simRun();
    $team = simTeam($run, 6);
    simTeam($run, 2, 'real', 2);
    signUp([Simulator::HEADER => $run->headerFor('x')], Simulator::email($run->id, 2, 1))->assertCreated();
    Storage::disk('local')->put("attachments/{$team->workspace_id}/1/file", 'x');
    Storage::disk('local')->put("attachments/{$real->id}/1/file", 'x');

    app(PurgeSimRun::class)->handle($run);

    expect(Workspace::query()->pluck('id')->all())->toBe([$real->id])
        ->and(User::query()->pluck('id')->all())->toBe([$realOwner->id])
        ->and(DB::table('work_items')->count())->toBe(0)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(SimMail::query()->count())->toBe(0)
        ->and(SimMember::query()->count())->toBe(0)
        ->and(SimTeam::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->count())->toBe(0)
        ->and(Storage::disk('local')->exists("attachments/{$team->workspace_id}/1/file"))->toBeFalse()
        ->and(Storage::disk('local')->exists("attachments/{$real->id}/1/file"))->toBeTrue()
        ->and(SimRun::query()->whereKey($run->id)->exists())->toBeTrue();
});

it('prunes runs that ended before the retention period', function () {
    $old = simRun(status: 'finished');
    $old->forceFill(['finished_at' => now()->subDays(30)])->save();
    simTeam($old, 2);
    $recent = simRun(status: 'finished');
    $recent->forceFill(['finished_at' => now()->subDay()])->save();

    $this->artisan('sim:prune')->assertSuccessful();

    expect(SimRun::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Workspace::query()->count())->toBe(0);
});

it('gives a simulated caller as many minutes of allowance as its run packs into one', function () {
    $run = simRun();
    $run->update(['speed' => 3]);
    $attempts = fn (string $header, int $times) => array_map(fn () => $this->withHeaders([Simulator::HEADER => $header])
        ->postJson('/api/v1/auth/login', ['email' => fake()->unique()->safeEmail(), 'password' => 'wrong-password'])->status(), range(1, $times));

    // Ten a minute for a real caller; at 3x a simulated one gets thirty, and not one more.
    expect($attempts($run->headerFor('fast'), 30))->not->toContain(429)
        ->and($attempts($run->headerFor('fast'), 1))->toBe([429]);
});
