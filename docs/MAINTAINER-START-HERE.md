# START HERE — handoff for a new Laravel / Vue maintainer

## Where things live
- **Backend** `sh-ansar/itp-hrm-backend`: Laravel 12, PHP 8.4.1+, PostgreSQL 16 canonical intent, Sanctum. All actual permissions and business state live here.
- **Frontend** `sh-ansar/itp-hrm-frontend`: Vue 3, TypeScript, composition API/Pinia, a shared BNT-inspired component library with source ZIP prototype parity. Vercel build runs a **synthetic demo**, not the real Laravel database.
- History and task state: `AGENTS.md`, `docs/EXECUTION-PLAN.md`, `docs/CHECKLIST.md`, `docs/DECISIONS.md`.
- Backend package versions are pinned in `composer.lock`; do not upgrade PHP/Laravel/Vue in a feature PR merely for convenience.

## Getting a safe development environment
1. Clone backend and frontend into separate folders. Use branches, PRs and protected `main`; no uncommitted local source-of-truth changes.
2. PHP 8.4.1+, GD, EXIF, PDO driver, Composer; Node 22 and `npm ci` for frontend. Read each repo's README.
3. Generate an independent local `APP_KEY`; use a disposable SQLite DB or a **project-owned** isolated PostgreSQL 16 database. Do not connect to existing shared corporate databases.
4. Install backend dependencies, `php artisan migrate` on the known test database, `php artisan test`; run frontend `npm run typecheck` and `npm run build:demo`. Read `.github/workflows/ci.yml` and frontend browser workflows for exact CI.
5. First-party login is not open signup; isolated test scripts provision **synthetic** users. The real environment requires HTTPS reverse proxy, Secure cookies and approved controlled provisioning. Do not copy CI test account credentials into a real deployment.
6. Update and pin the **exact reviewed backend Git SHA** in frontend browser integration workflow when API contracts change; verify both read-only and write-enabled synthetic test modes.
7. No production/test system access until the owner explicitly approves deployment topology, backup/restore and release flags.

## Adding a maintainable feature
1. Locate the prototype section and user story and compare frontend screenshot to source ZIP. Record exact missing fields/actions and identify the owner of each data field.
2. Write/extend data contract and migration: explicit relational table, UUIDs, same-company FK, unique indexes, revisions, nullable semantics, dates and archival policy. Do NOT modify applied migrations retroactively.
3. In Laravel add FormRequest (validation + authorization), thin controller, domain service for transaction/rules and safe audit. Cross-company IDs must fail.
4. Add SQLite and PostgreSQL feature tests for allowed role, forbidden role, another company, invalid dates, stale revisions, rollback and empty state. Review sensitive data serialization and encrypted storage.
5. In Vue add typed API wrapper; reuse BaseDrawer, BaseFormField, BaseDataTable, tokens and four-language RU/KZ/EN/GE copy. No fake persistent fallback or business logic stored only in Pinia.
6. Test desktop/tablet/mobile, themes, locales, screen-specific scrolling and real API reload using synthetic E2E. Check CI logs, not merely a green Git push.
7. Document PR, contract, migration safety, accepted limitations and next unfinished issue in the execution plan. Merge only after green required checks.

## Strict boundaries
- **Auth**: `app/Domain/Identity`, `GET /api/v1/me/access`; a Vue role is a UX hint, never authorization.
- **HR core**: `app/Domain/Personnel`, `EmployeeVisibility`, employee routes; department managers must not see HR personal fields.
- **Private data**: encrypted national/identity numbers, private photo/PDF; audit contexts contain no PII.
- **Leave**: `LeavePlanWorkflow`, `LeaveDayCounter`, `leave_requests` vs approved `employee_leaves`; do not calculate country holidays or accruals from browser dates.
- **Calendar**: `ProductionCalendarWriter`; full company-defined days, revision 409.
- **Demo**: isolated synthetic records only; never treat Vercel mock output as evidence of PostgreSQL persistence.

## Current open release gates (15 October 2026)
Dedicated PostgreSQL with least-privilege roles; approved HTTPS/Sanctum configuration and user provisioning; full business permission matrix and employee self-service; file upload scanning/retention; external append-only audit archive; migrations backed by restore rehearsal; regulatory payroll/leave entitlement policy and integrations.

See [architecture](ARCHITECTURE.md), [actual DB tables](DATA-MODEL.md), [security](SECURITY.md), [browser session](BROWSER-SESSION.md), [private records](EMPLOYEE-REPEATABLE-PRIVATE-RECORDS.md) and paired frontend `docs` files.
