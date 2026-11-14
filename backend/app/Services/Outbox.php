<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\OutgoingEmail;
use App\Models\User;
use App\Notifications\Announcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Announcements: who receives one, queuing a message per person, and sending what waits. */
class Outbox
{
    /**
     * The people an announcement goes to: verified, not suspended, never agents.
     *
     * @param  array{audience: string, plan?: string|null, important?: bool}  $filter
     * @return Builder<User>
     */
    public function recipients(array $filter): Builder
    {
        $query = User::query()->where('type', 'human')->whereNull('suspended_at')->whereNotNull('email_verified_at');

        if (! ($filter['important'] ?? false)) {
            $query->where('email_notifications', true);
        }

        $roles = match ($filter['audience']) {
            'owners' => ['owner'],
            'admins' => ['owner', 'admin'],
            default => null,
        };
        $plan = $filter['plan'] ?? null;

        if ($roles !== null || $plan !== null) {
            $query->whereIn('id', DB::table('workspace_memberships')
                ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                ->whereNull('workspaces.suspended_at')
                ->when($roles !== null, fn ($q) => $q->whereIn('workspace_memberships.role', $roles))
                ->when($plan !== null, fn ($q) => $q->where('workspaces.plan', $plan))
                ->select('workspace_memberships.user_id'));
        }

        return $query;
    }

    /** Stores the announcement and one waiting message per recipient. */
    public function queue(Broadcast $broadcast): Broadcast
    {
        return DB::transaction(function () use ($broadcast) {
            $broadcast->save();
            $count = 0;

            $this->recipients(['audience' => $broadcast->audience, 'plan' => $broadcast->plan, 'important' => $broadcast->important])
                ->select(['id', 'name', 'email'])
                ->chunkById(500, function ($users) use ($broadcast, &$count): void {
                    $now = now();
                    OutgoingEmail::query()->insert($users->map(fn (User $user) => [
                        'broadcast_id' => $broadcast->id,
                        'kind' => 'Announcement',
                        'to_email' => $user->email,
                        'to_name' => $user->name,
                        'subject' => $broadcast->subject,
                        'status' => OutgoingEmail::WAITING,
                        'attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                    $count += $users->count();
                });

            $broadcast->forceFill(['recipients' => $count])->save();

            return $broadcast;
        });
    }

    /**
     * Sends one message of an announcement. Returns whether it went out.
     * Taking the row first means two senders never send the same message.
     */
    public function send(OutgoingEmail $email): bool
    {
        $taken = OutgoingEmail::query()->whereKey($email->id)
            ->whereIn('status', [OutgoingEmail::WAITING, OutgoingEmail::FAILED])
            ->update(['status' => OutgoingEmail::SENDING, 'updated_at' => now()]);

        $broadcast = $email->broadcast;

        if ($taken === 0 || $broadcast === null) {
            return false;
        }

        // The row was taken by a query; bring this copy of it up to date.
        $email->forceFill(['status' => OutgoingEmail::SENDING])->syncOriginal();

        try {
            Notification::route('mail', [$email->to_email => (string) $email->to_name])
                ->notifyNow(new Announcement($broadcast->subject, $broadcast->body, $email->id));

            $email->forceFill(['status' => OutgoingEmail::SENT, 'attempts' => $email->attempts + 1, 'error' => null, 'sent_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            $attempts = $email->attempts + 1;
            $email->forceFill([
                'status' => $attempts >= OutgoingEmail::MAX_ATTEMPTS ? OutgoingEmail::FAILED : OutgoingEmail::WAITING,
                'attempts' => $attempts,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return false;
        }
    }

    /**
     * Sends what waits, oldest first. Returns [sent, failed].
     *
     * @return array{int, int}
     */
    public function sendWaiting(int $limit): array
    {
        // A sender that died mid-message leaves it "sending"; give those back.
        OutgoingEmail::query()->where('status', OutgoingEmail::SENDING)->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => OutgoingEmail::WAITING]);

        $sent = $failed = 0;

        OutgoingEmail::query()->with('broadcast')->where('status', OutgoingEmail::WAITING)->orderBy('id')->limit($limit)->get()
            ->each(function (OutgoingEmail $email) use (&$sent, &$failed): void {
                $this->send($email) ? $sent++ : $failed++;
            });

        return [$sent, $failed];
    }
}
