<?php

namespace App\Http;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception raised under /api into one JSON shape:
 * {"error": {"code": "...", "message": "...", "details": {...}}}.
 */
class ApiErrorRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        412 => 'version_mismatch',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'rate_limited',
    ];

    public function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ApiException) {
            return new JsonResponse(['error' => array_filter([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details,
            ])], $e->status);
        }

        if ($e instanceof ValidationException) {
            return $this->respond(422, $e->getMessage(), ['fields' => $e->errors()]);
        }

        if ($e instanceof AuthenticationException) {
            return $this->respond(401, 'Authentication is required.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : (JsonResponse::$statusTexts[$status] ?? 'Error');

            return $this->respond($status, $message, [], $e->getHeaders());
        }

        $details = config('app.debug')
            ? ['exception' => $e::class, 'message' => $e->getMessage()]
            : [];

        return $this->respond(500, 'Something went wrong on our side.', $details);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $headers
     */
    private function respond(int $status, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $error = [
            'code' => self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'request_failed'),
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }
}
