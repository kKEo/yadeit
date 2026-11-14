<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\TokenScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agents are workspace members that act through access tokens. Each has a
 * human owner. Members create agents; the owner and workspace admins manage
 * them and their tokens. Agents cannot manage agents.
 */
class AgentController extends Controller
{
    private const MAX_TOKENS_PER_AGENT = 20;

    public function __construct(private CurrentWorkspace $current) {}

    public function index(Workspace $workspace): JsonResponse
    {
        $agents = $this->agents($workspace)->with('owner')->withCount('tokens')->orderBy('name')->get();

        return response()->json(['data' => $agents->map(fn (User $agent) => $this->present($agent))->values()]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->humansOnly($request);
        $this->current->requireRole(WorkspaceRole::Member);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $agent = DB::transaction(function () use ($data, $request, $workspace): User {
            $agent = new User;
            $agent->forceFill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => UserType::Agent,
                'owner_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
                // Never used: agents cannot sign in and receive no email.
                'email' => 'agent-'.Str::uuid().'@agents.invalid',
                'password' => Str::random(64),
                'email_notifications' => false,
            ])->save();

            $workspace->memberships()->create(['user_id' => $agent->id, 'role' => WorkspaceRole::Member]);

            return $agent;
        });

        return response()->json(['data' => $this->present($agent->load('owner')->loadCount('tokens'))], 201);
    }

    public function update(Request $request, Workspace $workspace, int $agent): JsonResponse
    {
        $model = $this->manageable($request, $workspace, $agent);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $model->forceFill($data)->save();

        return response()->json(['data' => $this->present($model->load('owner')->loadCount('tokens'))]);
    }

    /** Removes the agent from the workspace and revokes all its tokens. Its past activity stays attributed to it. */
    public function destroy(Request $request, Workspace $workspace, int $agent): Response
    {
        $model = $this->manageable($request, $workspace, $agent);

        DB::transaction(function () use ($model, $workspace): void {
            $model->tokens()->delete();
            $workspace->memberships()->where('user_id', $model->id)->delete();
        });

        return response()->noContent();
    }

    public function tokens(Request $request, Workspace $workspace, int $agent): JsonResponse
    {
        $model = $this->manageable($request, $workspace, $agent);
        $tokens = $model->tokens()->orderByDesc('id')->get();
        $keys = $this->projectKeys($tokens->all());

        return response()->json(['data' => $tokens->map(fn (PersonalAccessToken $token) => $this->presentToken($token, $keys))->values()]);
    }

    /**
     * Creates a token. The secret is returned once, in `token`, and cannot be
     * read again: store it where the agent runs.
     */
    public function createToken(Request $request, Workspace $workspace, int $agent): JsonResponse
    {
        $model = $this->manageable($request, $workspace, $agent);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', 'in:read,write'],
            'projects' => ['sometimes', 'array', 'max:50'],
            'projects.*' => ['string', 'max:10'],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        if ($model->tokens()->count() >= self::MAX_TOKENS_PER_AGENT) {
            throw new ApiException(409, 'too_many_tokens', 'This agent already has '.self::MAX_TOKENS_PER_AGENT.' tokens. Revoke one first.');
        }

        $projectIds = $this->resolveProjects($data['projects'] ?? []);
        $expiresAt = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;

        $created = $model->createToken($data['name'], TokenScope::abilities($data['access'] === 'write', $projectIds), $expiresAt);

        return response()->json(['data' => [
            ...$this->presentToken($created->accessToken, $this->projectKeys([$created->accessToken])),
            'token' => $created->plainTextToken,
        ]], 201);
    }

    public function revokeToken(Request $request, Workspace $workspace, int $agent, int $token): Response
    {
        $model = $this->manageable($request, $workspace, $agent);

        $deleted = $model->tokens()->whereKey($token)->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException('Token not found.');
        }

        return response()->noContent();
    }

    /** @return Builder<User> */
    private function agents(Workspace $workspace): Builder
    {
        return User::query()
            ->where('type', UserType::Agent)
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $workspace->memberships()->select('user_id'));
    }

    /** The agent, if the caller is its owner or a workspace admin. */
    private function manageable(Request $request, Workspace $workspace, int $agentId): User
    {
        $this->humansOnly($request);

        $agent = $this->agents($workspace)->whereKey($agentId)->first()
            ?? throw new NotFoundHttpException('Agent not found.');

        $isOwner = $agent->owner_id === $request->user()->id && $this->current->role()?->atLeast(WorkspaceRole::Member);

        if (! $isOwner && ! $this->current->role()?->atLeast(WorkspaceRole::Admin)) {
            throw new AccessDeniedHttpException('Only the agent\'s owner or a workspace admin can manage it.');
        }

        return $agent;
    }

    private function humansOnly(Request $request): void
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'Agents and their tokens are managed by people.');
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function resolveProjects(array $keys): array
    {
        $keys = array_values(array_unique(array_map('strtoupper', $keys)));

        if ($keys === []) {
            return [];
        }

        $found = Project::query()->whereIn('key', $keys)->pluck('id', 'key');
        $unknown = array_values(array_diff($keys, $found->keys()->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'projects' => ['Unknown project: '.implode(', ', $unknown).'. Pass project keys of this workspace.'],
            ]);
        }

        return array_values(array_map('intval', $found->values()->all()));
    }

    /**
     * @param  array<int, PersonalAccessToken>  $tokens
     * @return array<int, string> project id => key
     */
    private function projectKeys(array $tokens): array
    {
        $ids = array_merge([], ...array_map(fn (PersonalAccessToken $token) => TokenScope::projectIds($token) ?? [], $tokens));

        return $ids === [] ? [] : Project::query()->whereIn('id', $ids)->pluck('key', 'id')->all();
    }

    /** @return array<string, mixed> */
    private function present(User $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'owner' => $agent->owner ? ['id' => $agent->owner->id, 'name' => $agent->owner->name] : null,
            'token_count' => (int) ($agent->tokens_count ?? 0),
            'created_at' => $agent->created_at,
        ];
    }

    /**
     * @param  array<int, string>  $projectKeys
     * @return array<string, mixed>
     */
    private function presentToken(PersonalAccessToken $token, array $projectKeys): array
    {
        $projectIds = TokenScope::projectIds($token);

        return [
            'id' => $token->id,
            'name' => $token->name,
            'access' => TokenScope::canWrite($token) ? 'write' : 'read',
            // null means every project of the workspace.
            'projects' => $projectIds === null
                ? null
                : array_values(array_filter(array_map(fn (int $id) => $projectKeys[$id] ?? null, $projectIds))),
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
        ];
    }
}
