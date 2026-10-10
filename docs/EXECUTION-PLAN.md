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
- [ ] Final role matrix and release approval, denied-access/export auditing, DB-level audit immutability, CRUD authorization and CSRF deployment topology.

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

## Definition of done
Migration + authorized API + validation + history + tests + error/loading/empty UI states + docs + git SHA. For business features, passing only skeleton tests does NOT count as done.

## Next action
Next separate PR: agree and enforce secure first-party authentication/CSRF topology, define audit retention and database-level protection, then implement authorized employee create/update operations with tests. The role-grant API stays disabled by default. Establish a persistent dedicated PostgreSQL DB only after confirming isolation. Continue GitHub-only branches/PRs.
