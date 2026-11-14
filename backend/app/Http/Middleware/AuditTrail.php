<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the audit log. Which requests count is decided here in one list,
 * by route name, so that adding an endpoint never silently skips the log:
 * it either appears in the list or it is not audited.
 *
 * Only requests that succeeded are recorded, plus failed sign-ins. Secrets
 * and long texts in the request are left out.
 */
class AuditTrail
{
    public const ACTIONS = [
        'auth.login' => 'account.signed_in',
        'auth.logout' => 'account.signed_out',
        'auth.register' => 'account.registered',
        'auth.password.reset' => 'account.password_reset',
        'me.destroy' => 'account.deleted',
        'me.export' => 'account.exported',
        'invitations.accept' => 'invitation.accepted',
        'workspaces.store' => 'workspace.created',
        'workspaces.update' => 'workspace.updated',
        'workspaces.destroy' => 'workspace.deleted',
        'workspaces.export' => 'workspace.exported',
        'members.update' => 'member.role_changed',
        'members.destroy' => 'member.removed',
        'workspace-invitations.store' => 'invitation.sent',
        'workspace-invitations.destroy' => 'invitation.withdrawn',
        'agents.store' => 'agent.created',
        'agents.update' => 'agent.updated',
        'agents.destroy' => 'agent.removed',
        'agent-tokens.store' => 'token.created',
        'agent-tokens.destroy' => 'token.revoked',
        'webhooks.store' => 'webhook.created',
        'webhooks.update' => 'webhook.updated',
        'webhooks.destroy' => 'webhook.deleted',
        'projects.store' => 'project.created',
        'projects.update' => 'project.updated',
        'projects.destroy' => 'project.deleted',
        'projects.feedback-form' => 'feedback_form.changed',
        'states.store' => 'workflow.state_added',
        'states.update' => 'workflow.state_changed',
        'states.destroy' => 'workflow.state_removed',
        'ai.configure' => 'ai.settings_changed',
        'billing.checkout' => 'billing.checkout_started',
        'pages.destroy' => 'page.deleted',
        'items.destroy' => 'item.deleted',
        'admin.workspaces.update' => 'operator.workspace_changed',
        'admin.users.update' => 'operator.user_changed',
        'admin.mail.send' => 'operator.mail_sent_now',
        'admin.mail.destroy' => 'operator.mail_deleted',
        'admin.broadcasts.store' => 'operator.broadcast_sent',
    ];

    /** Request fields that never go into the log. */
    private const SECRET = ['password', 'password_confirmation', 'current_password', 'api_key', 'token', 'secret'];

    /** Request fields that are content, not settings: too long and not what an audit is about. */
    private const CONTENT = ['body', 'description', 'question', 'summary', 'text'];

    public function handle(Request $request, Closure $next): Response
    {
        // Whoever is signed in before the request: signing out or deleting the account forgets them afterwards.
        $before = $request->user();
        $response = $next($request);

        $name = (string) $request->route()?->getName();
        $action = self::ACTIONS[$name] ?? null;

        // Sharing a page by link is the one page change worth a record.
        if ($name === 'pages.update' && $request->hasAny(['public', 'status'])) {
            $action = 'page.sharing_changed';
        }

        if ($name === 'auth.login' && $response->getStatusCode() === 422) {
            $email = $request->input('email');

            // Only a real attempt on an address is worth a row; anything else would let strangers fill the log.
            if (is_string($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $this->record('account.sign_in_failed', $request, null, ['email' => mb_strtolower($email)]);
            }

            return $response;
        }

        if ($action !== null && $response->isSuccessful()) {
            $this->record($action, $request, $request->user() ?? $before, $this->details($request));
        }

        return $response;
    }

    /** @param  array<string, mixed>|null  $details */
    private function record(string $action, Request $request, mixed $actor, ?array $details): void
    {
        $workspace = $request->route('workspace');
        $token = $actor instanceof User && $request->bearerToken() !== null ? $actor->currentAccessToken() : null;
        $params = collect($request->route()?->parameters() ?? [])
            ->except('workspace')
            ->map(fn (mixed $value, string $key) => $key.'='.(is_object($value) && method_exists($value, 'getRouteKey') ? $value->getRouteKey() : (is_scalar($value) ? $value : '?')))
            ->implode(' ');

        AuditEvent::create([
            'workspace_id' => $workspace instanceof Workspace ? $workspace->id : null,
            'workspace_slug' => $workspace instanceof Workspace ? $workspace->slug : null,
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'actor_name' => $actor instanceof User ? $actor->name : null,
            'actor_type' => $actor instanceof User ? $actor->type->value : null,
            'token_name' => $token instanceof PersonalAccessToken ? $token->name : null,
            'action' => $action,
            'subject' => $params !== '' ? mb_substr($params, 0, 200) : null,
            'details' => $details ?: null,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function details(Request $request): array
    {
        if ($request->isMethodSafe() || ! $request->isJson()) {
            return [];
        }

        $details = [];

        foreach ($request->json()->all() as $key => $value) {
            if (in_array($key, self::SECRET, true)) {
                $details[$key] = '(hidden)';
            } elseif (in_array($key, self::CONTENT, true)) {
                continue;
            } elseif (is_scalar($value) || $value === null) {
                $details[$key] = is_string($value) ? mb_substr($value, 0, 200) : $value;
            } elseif (is_array($value) && count($value) <= 20) {
                $details[$key] = array_map(fn (mixed $v) => is_scalar($v) ? $v : '…', array_values($value));
            }
        }

        return $details;
    }
}
