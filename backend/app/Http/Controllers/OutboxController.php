<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Broadcast;
use App\Models\OutgoingEmail;
use App\Services\Outbox;
use App\Support\Plans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The operator's view of outgoing email: the log of what was sent, the
 * announcements that still wait, and sending an announcement.
 */
class OutboxController extends Controller
{
    public function __construct(private Outbox $outbox) {}

    /** Filters: status (waiting, sending, sent, failed), q (recipient or subject), broadcast (id). Newest first. */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['sometimes', Rule::in(OutgoingEmail::STATUSES)],
            'q' => ['sometimes', 'string', 'max:100'],
            'broadcast' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);

        $query = OutgoingEmail::query()->orderByDesc('id');

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        if (isset($input['broadcast'])) {
            $query->where('broadcast_id', (int) $input['broadcast']);
        }

        if (isset($input['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($input['q']))).'%';
            $query->where(fn ($q) => $q->where('to_email', 'like', $like)->orWhereRaw('LOWER(subject) LIKE ?', [$like]));
        }

        $page = $query->cursorPaginate($input['limit'] ?? 50)->withQueryString();
        $counts = DB::table('outgoing_emails')->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');

        return response()->json([
            'data' => collect($page->items())->map(fn (OutgoingEmail $e) => $this->present($e)),
            'next_cursor' => $page->nextCursor()?->encode(),
            'counts' => collect(OutgoingEmail::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    /** One message. The text is included for announcements only; other mail is logged without it. */
    public function show(int $id): JsonResponse
    {
        $email = OutgoingEmail::query()->with('broadcast')->findOrFail($id);

        return response()->json(['data' => [
            ...$this->present($email),
            'body' => $email->broadcast?->body,
        ]]);
    }

    /** Sends a waiting or failed announcement message now instead of waiting for the next run. */
    public function send(int $id): JsonResponse
    {
        $email = OutgoingEmail::query()->with('broadcast')->findOrFail($id);

        if (! in_array($email->status, [OutgoingEmail::WAITING, OutgoingEmail::FAILED], true) || $email->broadcast === null) {
            throw new ApiException(409, 'conflict', 'Only a waiting or failed message can be sent.');
        }

        $this->outbox->send($email);
        $email->refresh();

        if ($email->status !== OutgoingEmail::SENT) {
            throw new ApiException(502, 'mail_failed', 'The mail server refused the message: '.($email->error ?? 'no reason given'));
        }

        return response()->json(['data' => $this->present($email)]);
    }

    /** Removes a message: a waiting one is never sent, a sent one leaves the log. */
    public function destroy(int $id): Response
    {
        $deleted = OutgoingEmail::query()->whereKey($id)->where('status', '!=', OutgoingEmail::SENDING)->delete();

        if ($deleted === 0) {
            OutgoingEmail::query()->findOrFail($id);

            throw new ApiException(409, 'conflict', 'This message is being sent right now.');
        }

        return response()->noContent();
    }

    /** The announcements sent so far, with how many messages of each still wait. */
    public function broadcasts(): JsonResponse
    {
        $broadcasts = Broadcast::query()->orderByDesc('id')->limit(50)->get();
        $counts = OutgoingEmail::query()->whereIn('broadcast_id', $broadcasts->pluck('id'))
            ->groupBy('broadcast_id', 'status')
            ->get(['broadcast_id', 'status', DB::raw('COUNT(*) as total')]);

        return response()->json(['data' => $broadcasts->map(fn (Broadcast $b) => $this->presentBroadcast(
            $b, $counts->where('broadcast_id', $b->id)->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
        ))]);
    }

    /** Who an announcement would reach, before anything is sent: the number and a few addresses. */
    public function preview(Request $request): JsonResponse
    {
        $filter = $this->filter($request);
        $query = $this->outbox->recipients($filter);

        return response()->json(['data' => [
            'recipients' => (clone $query)->count(),
            'sample' => $query->orderBy('id')->limit(5)->pluck('email'),
        ]]);
    }

    /**
     * Queues an announcement. `confirm_recipients` must repeat the number from
     * the preview: if the audience changed in between, nothing is sent.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:20000'],
            'audience' => ['required', Rule::in(Broadcast::AUDIENCES)],
            'plan' => ['sometimes', 'nullable', Rule::in(Plans::keys())],
            'important' => ['sometimes', 'boolean'],
            'confirm_recipients' => ['required', 'integer', 'min:1'],
        ]);

        $filter = ['audience' => $data['audience'], 'plan' => $data['plan'] ?? null, 'important' => (bool) ($data['important'] ?? false)];
        $count = $this->outbox->recipients($filter)->count();

        if ($count !== (int) $data['confirm_recipients']) {
            throw new ApiException(409, 'recipients_changed',
                "This announcement would now reach {$count} people, not {$data['confirm_recipients']}. Check the preview again.",
                ['recipients' => $count]);
        }

        $broadcast = $this->outbox->queue(new Broadcast([
            'subject' => $data['subject'],
            'body' => $data['body'],
            ...$filter,
            'created_by' => $request->user()->id,
            'created_by_name' => $request->user()->name,
        ]));

        return response()->json(['data' => $this->presentBroadcast($broadcast, [OutgoingEmail::WAITING => $broadcast->recipients])], 201);
    }

    /** @return array{audience: string, plan: string|null, important: bool} */
    private function filter(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(Broadcast::AUDIENCES)],
            'plan' => ['sometimes', 'nullable', Rule::in(Plans::keys())],
            'important' => ['sometimes', 'boolean'],
        ]);

        return ['audience' => $data['audience'], 'plan' => $data['plan'] ?? null, 'important' => (bool) ($data['important'] ?? false)];
    }

    /** @return array<string, mixed> */
    private function present(OutgoingEmail $email): array
    {
        return [
            'id' => $email->id,
            'kind' => $email->kind,
            'to' => ['email' => $email->to_email, 'name' => $email->to_name],
            'subject' => $email->subject,
            'status' => $email->status,
            'attempts' => $email->attempts,
            'error' => $email->error,
            'broadcast_id' => $email->broadcast_id,
            'sent_at' => $email->sent_at,
            'created_at' => $email->created_at,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function presentBroadcast(Broadcast $broadcast, array $counts): array
    {
        return [
            'id' => $broadcast->id,
            'subject' => $broadcast->subject,
            'body' => $broadcast->body,
            'audience' => $broadcast->audience,
            'plan' => $broadcast->plan,
            'important' => $broadcast->important,
            'recipients' => $broadcast->recipients,
            'waiting' => ($counts[OutgoingEmail::WAITING] ?? 0) + ($counts[OutgoingEmail::SENDING] ?? 0),
            'sent' => $counts[OutgoingEmail::SENT] ?? 0,
            'failed' => $counts[OutgoingEmail::FAILED] ?? 0,
            'created_by' => $broadcast->created_by_name,
            'created_at' => $broadcast->created_at,
        ];
    }
}
