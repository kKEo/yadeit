<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiException;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Services\WebhookDispatcher;
use App\Support\CurrentWorkspace;
use App\Support\WebhookTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Webhooks send a signed HTTP request to an address of your choice when
 * items change. Workspace admins manage them; agents cannot.
 *
 * Verify a delivery: take `t` and `v1` from the X-Yadeit-Signature header,
 * compute HMAC-SHA256 of "<t>.<raw body>" with the webhook's secret, compare
 * with `v1`, and reject old timestamps. X-Yadeit-Delivery is the same on
 * every retry of one event.
 */
class WebhookController extends Controller
{
    private const MAX_PER_WORKSPACE = 20;

    public function __construct(private CurrentWorkspace $current) {}

    public function index(Request $request): JsonResponse
    {
        $this->adminsOnly($request);

        return response()->json(['data' => Webhook::query()->orderBy('id')->get()->map(fn (Webhook $w) => $w->summary()), 'events' => Webhook::EVENTS]);
    }

    /** The signing secret is returned once, in `secret`. */
    public function store(Request $request): JsonResponse
    {
        $this->adminsOnly($request);
        $data = $this->validated($request, creating: true);

        if (Webhook::query()->count() >= self::MAX_PER_WORKSPACE) {
            throw new ApiException(409, 'too_many_webhooks', 'A workspace can have '.self::MAX_PER_WORKSPACE.' webhooks. Delete one first.');
        }

        $secret = 'whsec_'.Str::random(40);
        $webhook = Webhook::create([...$data, 'secret' => $secret, 'created_by' => $request->user()->id]);

        return response()->json(['data' => [...$webhook->summary(), 'secret' => $secret]], 201);
    }

    /** Setting is_active to true also clears a switch-off caused by repeated failures. */
    public function update(Request $request, Workspace $workspace, int $webhook): JsonResponse
    {
        $this->adminsOnly($request);
        $model = $this->find($webhook);
        $data = $this->validated($request, creating: false);

        if (($data['is_active'] ?? false) === true) {
            $data += ['consecutive_failures' => 0, 'disabled_at' => null];
        }

        $model->update($data);

        return response()->json(['data' => $model->summary()]);
    }

    public function destroy(Request $request, Workspace $workspace, int $webhook): Response
    {
        $this->adminsOnly($request);
        $this->find($webhook)->delete();

        return response()->noContent();
    }

    /** The latest delivery attempts, newest first. */
    public function deliveries(Request $request, Workspace $workspace, int $webhook): JsonResponse
    {
        $this->adminsOnly($request);
        $limit = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 30;

        $rows = $this->find($webhook)->deliveries()->orderByDesc('id')->limit((int) $limit)->get();

        return response()->json(['data' => $rows->map(fn (WebhookDelivery $d) => $d->summary())]);
    }

    /** Sends a `ping` event so you can check the receiver and its signature check. */
    public function ping(Request $request, Workspace $workspace, int $webhook, WebhookDispatcher $dispatcher): JsonResponse
    {
        $this->adminsOnly($request);
        $model = $this->find($webhook);

        if (! $model->is_active) {
            throw new ApiException(409, 'webhook_inactive', 'This webhook is switched off. Switch it on first.');
        }

        return response()->json(['data' => ['event_id' => $dispatcher->ping($model, $workspace)]], 202);
    }

    private function adminsOnly(Request $request): void
    {
        if ($request->user()->isAgent()) {
            throw new ApiException(403, 'not_available_to_agents', 'Webhooks are managed by people.');
        }

        $this->current->requireRole(WorkspaceRole::Admin);
    }

    private function find(int $id): Webhook
    {
        return Webhook::find($id) ?? throw new NotFoundHttpException('Webhook not found.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'url' => [$required, 'string', 'max:2000'],
            'events' => ['sometimes', 'nullable', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(Webhook::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url']) && ($problem = WebhookTarget::problem($data['url'])) !== null) {
            throw ValidationException::withMessages(['url' => [$problem]]);
        }

        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }

        return $data;
    }
}
