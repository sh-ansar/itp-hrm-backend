<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBrowserSessionEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // The deployment origin and identity ownership model are not final.
        // Fail closed rather than exposing a new login route prematurely.
        abort_unless(config('hrm.browser_login_enabled', false) === true, 404);

        if (!config('session.http_only', true)
            || !in_array(config('session.same_site'), ['strict', 'lax'], true)) {
            abort(503, 'Browser session cookie configuration is unsafe.');
        }

        if (app()->environment('production')
            && (!config('session.secure', false) || !$request->isSecure())) {
            // Requires correctly configured trusted reverse proxies.
            abort(503, 'HTTPS session configuration is required.');
        }

        return $next($request);
    }
}
