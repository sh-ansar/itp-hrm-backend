# Execution plan — ITP HRM

Updated: 2026-10-09. Source: "Кадры 2026" presentation.

## Status definitions
- DONE: source committed and tests passed.
- IN PROGRESS: code being implemented, tests or dependencies incomplete.
- BLOCKED: prerequisite absent; include specific reason.
- TODO: not started.

## Gate 0 — Technical foundation
- [x] Separate frontend/backend repositories and local checkout at C:\dev\itp-hrm.
- [x] Vue 3/TypeScript initial shell; TypeScript and Vite build passed.
- [x] Laravel 12 installed; API health endpoint.
- [x] PHP project-local extensions and isolated SQLite tests: 4 passed, 11 assertions.
- [x] Developer workflow policy in AGENTS.md.
- [ ] Dedicated PostgreSQL ITP HRM database and least-privilege account (BLOCKED until credentials/isolated instance confirmed). Do not touch existing databases.
- [x] Frontend/backend CI confirmed green on main; backend also tests PostgreSQL 16 in ephemeral GitHub service.
- [x] Initial read-only scoped personnel list + authentication, company role and department access checks; PostgreSQL and SQLite tests validated on PR #2.
- [x] PR #4 subphase: opt-in (default disabled) role grant/revoke API for non-admin roles, transactional audit and sensitive list-read audit; SQLite/PostgreSQL tests passed.
- [x] PR #6 subphase: audit UPDATE/DELETE blocked in PostgreSQL/SQLite; PostgreSQL TRUNCATE blocked, 45 tests passed on both drivers.
- [x] A09a subphase (PR #8): default-disabled session login/logout, Sanctum stateful API, negative login throttling/CSRF tests, strict CORS for same-origin SPA.
- [x] A09c1 subphase (frontend PR #3): 5/5 Chromium browser integration tests passed for real Vue/Laravel HTTP sessions, CSRF, rate limit, logout and same-origin CORS on disposable CI runner. This is not approved staging.
- [ ] Final role matrix, staging HTTPS/session topology and login activation, denied-access/export auditing, immutable external audit archive, retention, non-owner runtime DB role and employee CRUD authorization.

## Gate 1 — First vertical slice
- [ ] Company, departments, positions and employees secured API (CRUD with validation, tenancy checks, audit, transactions).
- [ ] Effective-dated assignments including no overlapping active assignment rule.
- [ ] Vue views for organization, employees and assignments using reusable UI components.
- [ ] End-to-end API and UI tests.

## Gate 2 — Personnel actions
- [ ] Hire/transfer/separation events, orders, contracts and attachments.
- [ ] Approval workflow, roles and scoped audit.

## Gate 3 — Remaining presentation scope
- [ ] Leave schedule and absence handling.
- [ ] Timekeeping, calendar and timesheets.
- [ ] Staffing versions, vacancies and personnel costs.
- [ ] Training, reserve, candidates and reports.
- [ ] Payroll specification and reconciliation against legacy BSP Wage before calculations.

## Local employee card slice (2026-10-13)
- [x] Nullable personal/contact and workplace fields, role-scoped detail response, create/update validation and form.
- [x] Local migrations 000006 and 000007 applied; new API integration test passed (8 assertions).
- [x] Frontend TypeScript/Vite build passed; decorative kicker labels removed from six sections.
- [ ] Profile photo storage/upload, related organization lookups (site, team, supervisor IDs), pixel comparison with ZIP reference and browser E2E.
- [ ] Resolve four pre-existing full PHPUnit suite failures; do not mark full employee-card module DONE before security/integration validation.

## Definition of done
Migration + authorized API + validation + history + tests + error/loading/empty UI states + docs + git SHA. For business features, passing only skeleton tests does NOT count as done.

## Next action
Next steps: agree staging HTTPS origin and trusted proxy; run browser E2E against that approved domain and design controlled account provisioning/deprovisioning. CI already validates isolated browser login but not real deployment. Keep both login and role-management flags OFF. Then implement authorized employee mutations and denied/export audit coverage. DB-level ordinary DML protection is implemented; immutable external archive, retention and non-owner runtime DB roles remain open. The role-grant API stays disabled by default. Establish a persistent dedicated PostgreSQL DB only after confirming isolation. Continue GitHub-only branches/PRs.
