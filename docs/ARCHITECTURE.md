# HRM backend architecture — implemented boundaries (15 October 2026)

## Deployment status and principles

The executable Laravel 12 app follows a **modular monolith**. The Vue 3 SPA is a separate repository; both communicate through `/api/v1`. PostgreSQL is the intended canonical storage; Redis is optional auxiliary infrastructure. The hosted Vue demonstration uses synthetic records, not a deployed PostgreSQL backend. A dedicated project-owned PostgreSQL service, secure reverse-proxy topology and production HR data collection are **not yet authorized**.

## Code ownership and layers

| Layer | Location | Responsibility |
| --- | --- | --- |
| Routes | `routes/api.php` | v1 entry points, UUID boundaries, Sanctum/active-account middleware |
| HTTP validation | `app/Http/Requests/*` and older controller validators | Field schemas and policy checks; unexpected identity fields rejected |
| Controllers | `app/Http/Controllers/Api/V1/*` | Thin orchestration and safe JSON response shape |
| Domain services | `app/Domain/{Identity,Personnel,Audit,Leave,Worktime}/*` | Business invariants, company scoping, state transitions, transactional writes |
| Persistence | `database/migrations/*` | Append-only schema evolution, UUID entities, FK/unique indexes, temporal/revision constraints |
| Tests | `tests/Feature/*` and `tests/Unit/*` | Tenant isolation, auth denial, concurrency, validation, rollback on PostgreSQL/SQLite |
| CI | `.github/workflows/ci.yml` | PHP 8.4 test suite + PostgreSQL 16 migrations/integration suite |
| Browser integration | Frontend `tests/e2e/*` | Synthetic end-to-end Vue + same-origin Laravel + CSRF, pinned backend revision |

Prefer feature-specific `FormRequest` + domain service for all **new** mutations, and extract shared authorization rather than repeating role checks. Older controllers still contain some validation/authorization and should be migrated incrementally, not wholesale in a risky single refactor.

## Business modules and invariants

- **Identity/access:** user accounts, company memberships, department scopes; `GET /api/v1/me/access` projects per-company effective capabilities. An employee role must not inherit HR rights. Do not union roles between companies.
- **Organization/personnel:** stable `employees.id` and company membership; names/contact fields and employment assignments. Assignment dates preserve history rather than mutating past periods.
- **Staffing/actions:** versioned staffing plans/lines and personnel actions. Only permitted company roles can mutate and all changes carry revision/audit.
- **Leave:** `leave_requests` is the draft/submitted/approved decision state machine. Only approved requests publish into `employee_leaves`; planned dates and actual dates stay separate. The leave balance/entitlement ledger and reversal of approved leaves are **future work**.
- **Worktime:** `production_calendar_days` stores explicit tenant-specific day types; 5/2 is only an explicit frontend suggestion. Shift schedules, entries and absences have separate tables. Locked timesheet lifecycle remains **future work**.
- **Sensitive employee details:** `employee_private_profiles` and `employee_military_registration` are one-to-one, while family and identity documents are one-to-many. HR-only detail responses, encrypted identifiers, versioned edits, archive over deletion. Physical identity/military document attachments are **not** equivalent to metadata and need a separate secure flow.
- **Audit/documents:** immutable-ish database-audit event ledger guarded against ordinary UPDATE/DELETE; private personnel-document storage with controlled download. Tamper-proof replication, malware scanning and retention approval remain **release gates**.
- **Payroll/reporting:** prototype and limited read models are not verified payroll computation. No statutory formulas, source mapping or compensation rules may be invented.

## Write-side consistency

1. Validate complete request and business permission server side, never trust Vue-selected role.
2. Lock a stable parent company or employee row on a transaction for multi-row invariants.
3. Check expected integer record revision; return HTTP 409 on conflict with no partial writes.
4. Enforce multi-company foreign-key boundaries where possible, including composite FKs; avoid mutable canonical balances.
5. Write the mutation and minimal HR audit **in the same database transaction**.
6. Respond with revision and safe identifiers; Vue refetches the authorized projection rather than fabricating durable state.

Use idempotent jobs and a transactional outbox when **external integrations actually begin**. Avoid premature microservices, redundant KZ catalogs or parallel stores of the same HR attribute.

## Authentication and privacy

Sanctum first-party cookie/session and CSRF are designed for same-origin HTTPS reverse proxy; both browser-login and business writes remain default disabled in real deployment until security review. No browser bearer tokens, wildcard CORS, plaintext secret/config commits or private employee data in localStorage. Encrypted fields depend on a durable APP_KEY and a controlled rotation plan.

## Read next

- [Database tables](DATA-MODEL.md), [architecture decisions](DECISIONS.md)
- [Access contract](EFFECTIVE-ACCESS-CONTRACT.md)
- [Employee private profile](EMPLOYEE-PRIVATE-PROFILE.md) and [family/identity](EMPLOYEE-REPEATABLE-PRIVATE-RECORDS.md)
- [Leave workflow](LEAVE-WORKFLOW-CONTRACT.md), [workforce calendar](WORKFORCE-ARCHITECTURE.md)
- [Browser session](BROWSER-SESSION.md), [maintainer onboarding](MAINTAINER-START-HERE.md)
