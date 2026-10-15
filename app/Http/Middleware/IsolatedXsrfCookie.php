<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A separate XSRF cookie avoids overwriting cookies of other apps hosted
 * at platform.itpmining.kz. The default name preserves existing clients.
 */
class IsolatedXsrfCookie extends ValidateCsrfToken
{
    protected function newCookie($request, $config)
    {
        $name = (string) config('hrm.xsrf_cookie_name', 'XSRF-TOKEN');

        if ($name === 'XSRF-TOKEN') {
            return parent::newCookie($request, $config);
        }

        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $name)) {
            throw new \InvalidArgumentException('Invalid HRM XSRF cookie name');
        }

        return new Cookie(
            $name,
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }
}
