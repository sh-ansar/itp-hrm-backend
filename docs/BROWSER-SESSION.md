# A09 browser authentication — same-origin contract

**Status:** tested server-side implementation only; **disabled by default**. No production domain, trusted proxy policy or source of user accounts has been approved.

## Endpoints
- `GET /sanctum/csrf-cookie` initializes the first-party CSRF cookie.
- `POST /auth/login` validates email/password and authenticates through Laravel's `web` session guard. It returns only `data.id` and `data.name` and never issues browser bearer tokens. Its role-management feature flag is unrelated.
- `GET /auth/me` returns safe identity to the signed-in user.
- `POST /auth/logout` requires authentication and CSRF, destroys session data and rotates the CSRF token.
- `GET /api/v1/me` remains `auth:sanctum` protected; the `statefulApi()` middleware permits web-cookie authentication from explicitly configured first-party origins.

## Fail-closed release flag
`HRM_BROWSER_LOGIN_ENABLED=false` is the safe default. Disabled login/current/logout routes return 404. When enabled, the middleware rejects configurations without an HttpOnly session cookie or Lax/Strict SameSite, and refuses **production** login without a Secure session cookie over HTTPS.

The current code does not prove the reverse proxy is trusted or correctly forwards the scheme; this must be verified with the final deployed hostname and TLS endpoint before enabling.

## Same-origin SPA
Development uses Vite's reverse proxy:
- Browser -> http://localhost:5173/api/* -> Laravel.
- Browser -> http://localhost:5173/auth/* -> Laravel.
- Browser -> http://localhost:5173/sanctum/* -> Laravel.

Set `SANCTUM_STATEFUL_DOMAINS=localhost:5173` on the backend and `VITE_API_BASE_URL=/api/v1` on the frontend. Keep the browser first-party origin consistent. `config/cors.php` denies all cross-origin requests; if production later requires subdomains, do not add wildcard origins. Document and explicitly review every exact allowed origin, cookie domain, Secure/SameSite policy and trusted proxy before changing CORS.

## Threat controls
- No public registration, password reset, SSO, or account management yet. Initial users must be provisioned separately.
- Unknown emails and incorrect passwords use the same validation message; credential attempts are limited to 5 per hashed email+IP and 20 per IP in 60 seconds.
- Session ID and CSRF token regenerate after login; session invalidated on logout.
- Laravel web CSRF is enforced for unsafe methods; tests explicitly override Laravel's PHPUnit CSRF bypass to verify 419 without a token and success with a matching token.
- All mutation endpoints using `auth:sanctum` also require a stateful trusted Origin/Referer and CSRF token for first-party browser clients.
- Login UI must never persist password/session or HR data in localStorage.

## Remaining acceptance gates (A09)
- Final domain/origin and HTTPS termination architecture, trusted proxy and Secure cookie verification.
- Real account provisioning and deprovisioning, reset/recovery policy, inactive-account handling, optional SSO/MFA.
- Browser-based end-to-end tests: CSRF-cookie delivery/rotation, login, page refresh, API session, logout, 419 and 429, expired session, cross-origin denial.
- Session expiration across browser restarts, CSRF behavior under load/retries, protection against credential stuffing in a multi-instance cache (use Redis with appropriate shared limits).
- Security assessment before the `HRM_BROWSER_LOGIN_ENABLED` flag can be enabled.
- Do not mark complete A09 until all listed gates are reviewed and tested. See Issue #7.
