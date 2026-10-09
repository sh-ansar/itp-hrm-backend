# Implementation checklist — source of truth
Check `[x]` ONLY when acceptance evidence exists. CI status must be confirmed separately; commit alone is not proof CI passed. Sync frontend progress with backend master checklist. Every change references FR/NFR in SPEC.md.

## A — Foundation
- [x] A01 Separate repositories and local folder `C:\dev\itp-hrm`. Evidence: frontend `25ea4b8`, backend `caf20fa`.
- [x] A02 Initial Vue shell and successful local Vite build. Evidence: local build verified 2026-10-09.
- [x] A03 Laravel application and versioned health route. Evidence: backend `caf20fa`.
- [x] A04 SQLite-isolated HR core migration tests. Evidence: backend `dbfd310`, 4 tests / 11 assertions.
- [x] A05 Agent workflow contract and roadmap. Evidence: backend `fde5c83`, frontend `e67dced`.
- [ ] A06 CI verified green in GitHub for both repos (workflows committed; run conclusion not yet verified).
- [ ] A07 PostgreSQL dedicated ITP HRM database + least-privilege user + isolated integration tests. BLOCKED: existing instances are shared/unknown ownership; no dedicated DB identified.
- [ ] A08 Audit/authorization and tenant-scoping acceptance tests.
- [ ] A09 User login/session with proper CSRF and secure cookie topology.
- [ ] A10 CI database service and migrations against PostgreSQL.
- [ ] A11 Reusable UI tables, forms, dialogs, feedback and accessibility tests.
- [ ] A12 Structured error handling / API response contract and tests.

## B — First connected business scenario
- [ ] B01 Company hierarchy CRUD + validation + company isolation.
- [ ] B02 Departments tree and cycle prevention.
- [ ] B03 Positions/staffing versions and approved state.
- [ ] B04 Employee register, unique personnel number and authorization.
- [ ] B05 Effective-dated assignment + overlap/conflict restrictions.
- [ ] B06 Vue connected organization, employee and staffing views.
- [ ] B07 API+UI integration tests with PostgreSQL, error/loading/empty states.

## C — Subsequent presentation scope
- [ ] C01 Hire/transfer/termination and order lifecycle.
- [ ] C02 Contract templates and private employee documents.
- [ ] C03 Leaves, schedules and balances.
- [ ] C04 Work schedules, absence classification, timesheets and print.
- [ ] C05 Learning, кадровый резерв, recruitment.
- [ ] C06 Statistics, personnel costs, IFRS data and reports.
- [ ] C07 Payroll calculations and reconciliation with source system — BLOCKED pending actual formulas.

## D — Readiness
- [ ] D01 Security review (permissions, scopes, sensitive logs/files).
- [ ] D02 Reliable backup and successful restoration test.
- [ ] D03 Performance, concurrency and recovery checks.
- [ ] D04 Trial import with reconciliation; no unapproved real data.
- [ ] D05 Staging acceptance and production release approval.

## Update template (for each closed item)
Requirement ID | summary | code SHA/PR | test proof | reviewer/date | remaining caveats.
