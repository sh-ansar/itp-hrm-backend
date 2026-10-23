# HRM ↔ centralized DMS integration boundary

Status: **architecture decision**, implementation deferred until the central document system and its identity/authorization contracts are available.

## Ownership

HRM is authoritative for:
- people, companies, departments, positions, assignments and employment history;
- HR workflow initiation, participants, manager/HR decisions, deadlines, outcomes and audit of the HR action;
- references to external workflow/document objects, their last verified status, synchronization timestamps and correlation IDs.

The future centralized document/workflow platform is authoritative for:
- canonical binaries and versions, retention, signers, signatures and signature verification;
- document templates, legally issued artifacts, controlled storage and final document archive;
- document-level permissions and download URLs after rechecking HRM user identity and company scope.

**No second signing/storage/archive engine should be built in HRM.** Existing HRM private draft/PDF endpoints are legacy/temporary functionality; retain compatibility without extending their legal scope. No user-facing status may imply a legally signed document based solely on HRM approval.

## Future interface

Prefer a versioned API and event contract. Suggested operations, to be finalized jointly:
- `POST /dms/v1/workflows` with `source_system=hrm`, `source_operation_id`, `company_id`, workflow type, participant roles and an idempotency key.
- `GET /dms/v1/workflows/{id}` for an explicitly authorized status projection.
- Signed, authenticated DMS callback/event: workflow_started, participant_decision, workflow_completed, workflow_rejected, workflow_cancelled, document_issued.
- `GET /dms/v1/documents/{id}/access` to obtain a **short-lived authorized view**, not a long-lived public PDF URL.

IDs must be opaque, company-scoped and non-reusable across tenants. Use a unique `source_operation_id` + operation type + idempotency key; verify callback signature, event sequence and tenant mapping before mutating workflow state.

## Data contract to prepare in HRM later

A minimal external reference projection may include:
`company_id`, `personnel_action_id`, `external_system`, `external_workflow_id`, `external_document_id`, `external_status`, `last_event_id`, `last_synced_at`, `revision`.

Do **not** store duplicate PDF binaries or signing keys in the HRM linkage table. Sensitive payloads must be minimized. The outbox/inbox pattern should atomically store HRM workflow intent and make retries safe; callbacks must be idempotent, with retries and dead-letter alerts.

## Authorization and user experience

- A user may launch a workflow only when authorized for the specific company and personnel action in Laravel.
- Managers see only scoped staff and workflow metadata; employees see only their own authorized workflow steps.
- A shortcut from an HRM action opens the central DMS approval only after server-side SSO or short-lived ticket exchange. Do not pass PII or shared credentials in URLs.
- HRM shows status and recent decisions, but final verification and canonical document view happen in DMS.
- Revoked company access or manager scope must stop new DMS access even if an old deep link exists.
- If DMS is offline, preserve the HRM action and show a retryable integration status; do not mark anything signed/completed optimistically.

## Acceptance criteria for the later integration

1. An HR administrator starts a real scoped process, receives exactly one DMS workflow after retries and reload, and sees its current status in HRM.
2. Reviewer receives a DMS task, completes it once, and HRM receives an authenticated idempotent status callback.
3. Cross-tenant IDs, forged callbacks, expired tickets and revoked roles are rejected at both systems.
4. Long-lived public PDF links or replicated binaries are never exposed by HRM.
5. Status reconciliation detects missing callbacks and supports manual diagnosis without rewriting the HR audit trail.
6. Rollback and recovery exercises cover both systems and event replay.

## Not in current scope

Implementing qualified digital signatures, canonical PDF storage, external DMS archival, or speculative API endpoints before the external contract exists. Leave all existing write feature flags and production integration settings unchanged.
