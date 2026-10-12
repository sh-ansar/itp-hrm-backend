# A09d1 — Account state and revocation (partial)

Status: IMPLEMENTED AS A SECURITY FOUNDATION, NOT PRODUCTION APPROVAL.

## Account states
- `invited`: pre-activation identity; cannot authenticate.
- `active`: may authenticate, subject to existing authorization scope.
- `suspended`: temporary access block.
- `disabled`: access block for deactivated accounts.

Account status is distinct from employment status, and is not a substitute for company/department authorization.

## Operational lifecycle
- There is no public registration or HTTP account state administration.
- An authorized operator with separately reviewed console access may use `php artisan hrm:account-status <numeric-user-id> <status>`.
- This command does not provide initial user creation, password reset, invitation delivery, approval workflow or MFA; those remain open.
- State transitions are serialized on the target user row. Repeated requests to set the same state are idempotent.
- When a status changes, database-backed sessions are removed. The active-account middleware also rereads status for each protected web/Sanctum request, so previously valid cookies do not grant access after suspension.
- The last active company admin cannot be deactivated with this operation. Recovery and multi-operator approvals are NOT implemented.

## Security gates still open
- Account changes currently lack an independently protected lifecycle audit record with verified operator identity; do not grant operators console access in production.
- Production requires an approved origin/TLS/trusted-proxy configuration, session storage, explicit provisioning and deprovisioning process, multi-instance revocation tests, audit and security signoff.
- The `active` migration default preserves existing synthetic tests; production migration must first inspect and classify any existing accounts.
- Company role administration and browser login remain OFF by default. No real employee data or sessions are used in CI.
