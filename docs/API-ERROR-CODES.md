# A12c — Typed API error codes (incremental)

The `/api/v1` middleware appends a machine-readable `code` on JSON HTTP error responses, without removing Laravel's existing `message` or field-specific `errors`. It also emits `X-Error-Code`. Successful response bodies do not change.

| Status | Code |
| --- | --- |
| 400 | BAD_REQUEST |
| 401 | UNAUTHENTICATED |
| 403 | FORBIDDEN |
| 404 | NOT_FOUND |
| 405 | METHOD_NOT_ALLOWED |
| 409 | CONFLICT |
| 419 | CSRF_EXPIRED |
| 422 | VALIDATION_FAILED |
| 429 | RATE_LIMITED |
| 503 | SERVICE_UNAVAILABLE |

Other 5xx statuses map to `INTERNAL_ERROR`, and otherwise unmapped 4xx to `HTTP_ERROR`. Only JSON responses flowing through matched API middleware are covered. Error rendering outside the middleware, safe production exception sanitization, frontend typed handling and staging test are still separate tasks. A12 remains open.

Security: never put exception traces, credentials, personnel data, or raw request payloads into the code field. Request correlation is provided separately by `X-Request-ID`.
