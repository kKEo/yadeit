<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The step between "an app asks for access" and "access is given": the person
 * chooses which of their agents the app works as. An app never acts as the
 * person themselves.
 */
class OAuthConsentController extends Controller
{
    /**
     * The agents a person can hand to an app: their own, in workspaces that are open and where they are still a full member.
     *
     * @return Builder<User>
     */
    public static function agentsOf(User $user): Builder
    {
        return User::query()->where('type', 'agent')->where('owner_id', $user->id)
            ->whereIn('workspace_id', fn ($q) => $q->select('workspace_memberships.workspace_id')->from('workspace_memberships')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                ->where('workspace_memberships.user_id', $user->id)
                ->whereIn('workspace_memberships.role', ['owner', 'admin', 'member'])
                ->whereNull('workspaces.suspended_at'))
            ->orderBy('name');
    }

    public function approve(Request $request, ClientRepository $clients): Response
    {
        $user = $request->user();
        $data = $request->validate(['agent' => ['required', 'integer']]);

        $agent = self::agentsOf($user)->whereKey($data['agent'])->first()
            ?? throw ValidationException::withMessages(['agent' => ['Choose one of your agents.']]);

        // Which app this is comes from the request Passport stored, never from the form.
        $pending = $request->session()->get('authRequest');
        $authRequest = is_string($pending) ? unserialize($pending, ['allowed_classes' => [
            AuthorizationRequest::class, BridgeClient::class, Scope::class, BridgeUser::class,
        ]]) : null;

        if (! $authRequest instanceof AuthorizationRequest) {
            abort(400, 'This authorization request has expired. Start again from the app.');
        }

        $clientId = $authRequest->getClient()->getIdentifier();
        $client = $clients->findActive($clientId) ?? abort(400, 'This app is not known here.');

        McpConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $clientId],
            ['agent_id' => $agent->id, 'client_name' => mb_substr((string) $client->name, 0, 255)],
        );

        AuditEvent::create([
            'workspace_id' => $agent->workspace_id,
            'workspace_slug' => Workspace::query()->whereKey($agent->workspace_id)->value('slug'),
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_type' => 'human',
            'action' => 'agent.app_connected',
            'subject' => "agent={$agent->id}",
            'details' => ['app' => (string) $client->name],
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return app()->call([app(ApproveAuthorizationController::class), 'approve'], ['request' => $request]);
    }
}
