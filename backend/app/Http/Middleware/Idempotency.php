<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes creates safe to retry: a request repeated with the same
 * Idempotency-Key header gets the first response back instead of creating a
 * second record. Agents and flaky networks both retry.
 */
class Idempotency
{
    private const KEEP_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if (! $key || ! $user || strlen($key) > 100) {
            return $next($request);
        }

        // The token is part of what makes a request "the same": another token of the same agent may see less.
        $tokenId = $request->bearerToken() !== null ? $user->currentAccessToken()->getKey() : 0;
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent().'|'.$tokenId);

        $stored = DB::table('idempotency_keys')
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->where('created_at', '>', now()->subHours(self::KEEP_HOURS))
            ->first();

        if ($stored) {
            if ($stored->request_hash !== $hash) {
                throw new ApiException(409, 'idempotency_key_reused',
                    'This Idempotency-Key was already used for a different request. Use a new key.');
            }

            return response($stored->body, $stored->status, [
                'Content-Type' => 'application/json',
                'Idempotency-Replayed' => 'true',
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            try {
                DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->delete();
                DB::table('idempotency_keys')->insert([
                    'user_id' => $user->id,
                    'key' => $key,
                    'request_hash' => $hash,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent identical request stored it first; nothing to do.
            }
        }

        return $response;
    }
}
