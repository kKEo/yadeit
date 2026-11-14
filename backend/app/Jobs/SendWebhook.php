<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\WebhookTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One attempt to deliver one event to one webhook. Every attempt is written
 * to the delivery log. A failed attempt schedules the next one itself, with
 * growing pauses; when the last one fails the event is given up, and a
 * webhook that keeps failing switches itself off.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    /** Retries are scheduled by the job itself, so the queue runs each attempt once. */
    public int $tries = 1;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public int $workspaceId,
        public int $webhookId,
        public string $eventId,
        public string $event,
        public array $payload,
        public int $attempt = 1,
    ) {}

    public function handle(CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace) {
            $current->runIn($workspace, fn () => $this->deliver());
        }
    }

    private function deliver(): void
    {
        $webhook = Webhook::find($this->webhookId);

        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $status = null;
        [$error, $addresses] = WebhookTarget::check($webhook->url);
        $started = microtime(true);

        if ($error === null) {
            try {
                $response = Http::timeout((int) config('webhooks.timeout'))
                    ->withoutRedirecting()
                    ->withOptions($this->pinned($webhook->url, $addresses))
                    ->withHeaders([
                        'User-Agent' => 'Yadeit-Webhooks/1',
                        'X-Yadeit-Event' => $this->event,
                        'X-Yadeit-Delivery' => $this->eventId,
                        // t is when it was signed; v1 is HMAC-SHA256 of "<t>.<body>" with the webhook secret.
                        'X-Yadeit-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $webhook->secret),
                    ])
                    ->withBody($body, 'application/json')
                    ->post($webhook->url);

                $status = $response->status();
                $error = $response->successful() ? null : 'The receiver answered '.$status.'.';
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 500);
            }
        }

        $webhook->deliveries()->create([
            'workspace_id' => $webhook->workspace_id,
            'event_id' => $this->eventId,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempt' => $this->attempt,
            'status_code' => $status,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'succeeded' => $error === null,
            'created_at' => now(),
        ]);

        if ($error === null) {
            $webhook->update(['consecutive_failures' => 0]);

            return;
        }

        $pauses = (array) config('webhooks.retry_after');
        // A receiver that says the request itself is wrong will say so again; do not repeat it.
        $worthRetrying = $status === null || $status >= 500 || in_array($status, [408, 429], true);

        if ($worthRetrying && isset($pauses[$this->attempt - 1])) {
            self::dispatch($this->workspaceId, $this->webhookId, $this->eventId, $this->event, $this->payload, $this->attempt + 1)
                ->delay((int) $pauses[$this->attempt - 1]);

            return;
        }

        $failures = $webhook->consecutive_failures + 1;
        $giveUp = $failures >= (int) config('webhooks.disable_after_failures');

        $webhook->update([
            'consecutive_failures' => $failures,
            'is_active' => ! $giveUp,
            'disabled_at' => $giveUp ? now() : null,
        ]);
    }

    /**
     * Makes the connection go to the addresses that passed the check, not to
     * whatever the host name answers a moment later.
     *
     * @param  list<string>  $addresses
     * @return array<string, mixed>
     */
    private function pinned(string $url, array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $port = parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);
        $list = implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $addresses));

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$list}"]]];
    }
}
