<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiErrorCode
{
    private const CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        419 => 'CSRF_EXPIRED',
        422 => 'VALIDATION_FAILED',
        429 => 'RATE_LIMITED',
        503 => 'SERVICE_UNAVAILABLE',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status < 400 || !$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (!is_array($payload) || array_is_list($payload)) {
            return $response;
        }

        $code = self::CODES[$status] ?? ($status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR');

        // Stable machine code is additive: Laravel's message and validation
        // errors stay intact for existing first-party consumers.
        $payload['code'] = $code;
        $response->setData($payload);
        $response->headers->set('X-Error-Code', $code);

        return $response;
    }
}
