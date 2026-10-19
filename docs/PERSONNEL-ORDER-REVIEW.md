# Internal personnel order review — maintainer contract

Implemented in backend PR [#107](https://github.com/sh-ansar/itp-hrm-backend/pull/107) and frontend PR [#160](https://github.com/sh-ansar/itp-hrm-frontend/pull/160).

## Business scope

There are two **deliberately separate** workflows:

1. **Personnel actions** (`personnel_actions`) execute a hire, transfer, or termination and update effective-dated `employee_assignments`. They already have their own API, access controls, optimistic revisions, and audit.
2. **Personnel order documents** (`personnel_documents` with `document_type=order`) are privately stored PDF records. The new internal review is a document control, **not** an electronic signature, state-recognized issuance of an order, payroll instruction, or command to execute a personnel action.

Do not make an `approved` PDF silently mutate an assignment. Linking `personnel_action_id` is metadata only; order review does not retroactively validate the underlying completed action.

## States and access

| From | Action | Result | Roles |
| --- | --- | --- | --- |
| New order | HR/admin creates draft | `draft` | HR specialist or company administrator |
| `draft` | Attach checksum-registered private PDF via existing MinIO endpoint | `draft` + revision increment | HR/admin with document writes |
| `draft` | Submit with current revision | `submitted` | **Original author only**, active HR or admin |
| `submitted` | Independent approve | `approved` | **Different company administrator only** |
| `submitted` | Independent reject with reason (5–500 chars) | `rejected` | **Different company administrator only** |
| `approved` | Separately share PDF with employee | no state change; `employee_visible=true` | HR/admin under existing private-document sharing rules |
| Any shared document | Revoke employee access | no state change; `employee_visible=false` | HR/admin |

The reviewer must differ from the order creator and submitter and cannot have an `employee_user_links` relationship to the subject employee. Revoked roles cannot review. A rejected order is terminal: make a *new draft* with a corrected PDF; historical evidence remains immutable. No automatic self-approval, implicit re-submission, or assignment changes.

Submit requires: employee and company ownership, `status=draft`, `document_number`, `issued_on`, `created_by_user_id`, a private MinIO PDF path scoped to company/document, PDF content-type, stored size and SHA-256 metadata. Legacy records with `created_by_user_id=null` are **not** silently attributed to someone.

Every write uses company-row transaction locking plus `revision` optimistic concurrency. State changes and audit events commit together. Separate file-upload revision checks still apply. API errors do not reveal storage paths, filenames, hashes or access keys.

## API endpoints

All under `/api/v1/companies/{company}`:

- `POST /documents` — existing HR document draft; now records `created_by_user_id` for new records.
- `POST /documents/{document}/file` — existing private PDF upload; only on drafts.
- `POST /documents/{document}/submit` — accepts only `{ "revision": N }`.
- `POST /documents/{document}/decision` — accepts `{ "revision": N, "decision": "approved" | "rejected", "comment": "..." }`.
- `GET /documents` — HR queue with `status`, reviewer IDs/timestamps and note. Metadata has `order_approvals_enabled` and `can_review_orders`; no storage identifiers.
- `PUT /documents/{document}/employee-access` — existing HR release; when order review is enabled, **a new share** requires `status=approved`. Revocation always remains possible.
- `GET /me/documents` — self-service list of explicitly shared documents, not unapproved HR drafts.
- `GET /me/documents/{document}/file` — employee's authenticated, tenant-scoped private PDF download.

## Feature flags and release gates

- `HRM_DOCUMENT_WRITES_ENABLED` — existing private document writes, default **false**.
- `HRM_ORDER_APPROVALS_ENABLED` — the new order review and mandatory approval-before-sharing rule, default **false**. It **also requires** document writes to use its endpoints.
- Existing private MinIO and legacy document migration flags are independent. None is activated by merging a Git PR.

Before enabling in production: take a PostgreSQL backup, rehearse the additive migration, verify HR/admin role separation with at least two authorized accounts, private MinIO and backups, isolation between companies, revocation and employee self-service. Inventory pre-existing shared orders before switching the gate on; review will not retroactively revoke already shared PDFs, and historical authors remain `null`.

**Pending, not covered:** electronic/qualified signatures, file malware scanning, legal numbering rules by jurisdiction, the exact effective-date policy, approval of a *proposed* personnel action before it is executed, automatic order generation and signed-document retention. These require legal/HR policy and dedicated workflows. An approved internal PDF is not evidence of statutory signature compliance.

## Tests and maintenance

- `php artisan test --filter=PersonnelOrderApprovalTest` with SQLite; PostgreSQL 16 in `.github/workflows/ci.yml`.
- Chromium HR → company admin → employee release: `tests/e2e/personnel-order-review.test.mjs` in frontend. Uses fake PDFs, synthetic identities and isolated SQLite/MinIO.
- Do not directly edit rows or bypass the workflow with SQL. Always add new migrations and auditable transitions.
