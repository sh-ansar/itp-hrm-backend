<?php

namespace App\Http\Middleware;

use App\Services\HrmSaasEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies only to authenticated HRM company routes, never to public pages,
 * Portal, platform-wide administration or unrelated applications.
 */
final class EnforceHrmSaasEntitlements
{
    public function handle(Request $request, Closure $next): Response
    {
        app(HrmSaasEntitlements::class)->forRequest($request);
        return $next($request);
    }
}
