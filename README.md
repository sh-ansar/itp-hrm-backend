# ITP HRM — Laravel backend

Laravel 12 / PHP 8.4 personnel-management API. The independent [Vue 3 frontend](https://github.com/sh-ansar/itp-hrm-frontend) uses versioned same-origin `/api/v1` endpoints. PostgreSQL 16 is the planned canonical production database; SQLite is used for isolated tests. Redis may be introduced for queues/cache, not as the employee master store.

## Current implementation (15 October 2026)

This is a bootstrapped, executable Laravel application, **not merely migration scaffolding**. Scoped organization, employees, assignments, staffing, personnel actions, private documents, leave workflows, worktime reads, company calendar edits, authenticated role capabilities and protected employee details are implemented behind appropriate authorization. Identity/military data and repeatable relatives/identity-document records have normalized migrations and protected APIs.

**Not approved for real production HR data.** Business role matrix, dedicated production/staging PostgreSQL, HTTPS/Sanctum trusted-proxy acceptance, account lifecycle, sensitive-data retention, external audit archive, private attachment scanning and backup/restore rehearsal still require owner/security approval. Feature flags default OFF.

## Requirements and local checks

- PHP **8.4.1+**, Composer 2 and required GD + EXIF extensions; SQLite or PostgreSQL PDO driver.
- Never point a development environment at unknown/shared databases.
- `composer install`
- Copy `.env.example` to untracked `.env`, configure an **isolated** DB and generate a unique `APP_KEY` with `php artisan key:generate`. Securely back up the key: employee identifiers use Laravel Crypt.
- `php artisan test` — isolated SQLite feature tests.
- `php artisan route:list --path=api` — inspect contracts.
- `php artisan migrate --force` — **only** against a verified project-owned database with backup/rollback review. Do not use `migrate:fresh` on shared or operational data.
- PostgreSQL 16 integration tests and migrations execute on an ephemeral GitHub Actions service in `.github/workflows/ci.yml`.
- Vue browser E2E provisions synthetic employees in an isolated SQLite test database; a green CI run does not prove a deployed production environment.

For Windows-specific PHP extension settings see [local development](docs/LOCAL-DEVELOPMENT.md). For current module and code boundaries see [architecture](docs/ARCHITECTURE.md), [actual database model](docs/DATA-MODEL.md), [maintainer onboarding](docs/MAINTAINER-START-HERE.md) and [execution plan](docs/EXECUTION-PLAN.md).

## Security and release switches

All sensitive writes require **both** Laravel company/role authorization and explicit approved deployment flags:
- `HRM_BROWSER_LOGIN_ENABLED` — first-party browser login.
- `HRM_ROLE_MANAGEMENT_ENABLED` — controlled role assignment.
- `HRM_PERSONNEL_WRITES_ENABLED` — employee, assignments, profile and private-record writes.
- `HRM_DOCUMENT_WRITES_ENABLED` — draft document and private PDF writes.
- `HRM_WORKTIME_WRITES_ENABLED` — production calendar writes.
- `HRM_LEAVE_WRITES_ENABLED` — leave requests and leave-type changes.

Flags alone do not authorize users. Read `GET /api/v1/me/access` to present Vue capabilities; Laravel controllers independently enforce permission and tenant scope for every request. The demo on Vercel is synthetic, **not** the backend database. The `HRM_ROLE_MANAGEMENT_ENABLED` flag never permits HTTP grants of the company-admin role.

## Handling private employee data

IIN/personal identifiers and identity-document numbers are encrypted at rest. Photos and other file contents use private storage; do not expose raw paths or link directly to private files. Audit context records actor, event, UUID and revision, never personal values. Do not commit real employee identities, addresses, document numbers, private PDFs, `.env`, secrets or screenshots of real employee files.

## Delivery and maintainers

Changes go to named Git branches and reviewed PRs in the **separate backend/frontend repositories**. Existing migrations must never be retroactively edited once merged/deployed; add new migrations. New mutations require FormRequest, server authorization, domain transaction, optimistic revision, bounded read projection, safe audit and SQLite/PostgreSQL tests. Update architecture, handoff contract, CI evidence and `docs/EXECUTION-PLAN.md` per PR.

See [AGENTS.md](AGENTS.md) and [security](docs/SECURITY.md). PHP/JS credentials belong in an external secret store, never source control.
