# A12 — API error response contract (first regression slice)

Status: A12 NOT COMPLETE. This file captures the **current observed Laravel contract**, not a blanket promise for all future endpoints.

- Under JSON Accept requests, anonymous protected routes return 401 with a JSON message and no HR identity record.
- Unknown versioned API endpoints return a JSON 404.
- Authorization checks run before protected membership payload validation, so unauthorized callers receive 403 rather than field-validation details.
- Invalid browser login payloads return 422 with `message` and field-indexed `errors`; credentials are never echoed.
- 419 represents rejected browser CSRF; 429 represents excessive authentication attempts (see A09 tests).

Next A12 substeps: explicitly centralize error-code catalog, provide request correlation IDs and safe production exception rendering, add cross-database tests, and standardize frontend client mapping. Avoid changing legacy successful response shape until both clients are migrated.
