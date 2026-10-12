# Implementation checklist — source of truth
Check `[x]` ONLY when acceptance evidence exists. CI status must be confirmed separately; commit alone is not proof CI passed. Sync frontend progress with backend master checklist. Every change references FR/NFR in SPEC.md.

## A — Foundation
- [x] A01 Separate repositories and local folder `C:\dev\itp-hrm`. Evidence: frontend `25ea4b8`, backend `caf20fa`.
- [x] A02 Initial Vue shell and successful local Vite build. Evidence: local build verified 2026-10-09.
- [x] A03 Laravel application and versioned health route. Evidence: backend `caf20fa`.
- [x] A04 SQLite-isolated HR core migration tests. Evidence: backend `dbfd310`, 4 tests / 11 assertions.
- [x] A05 Agent workflow contract and roadmap. Evidence: backend `fde5c83`, frontend `e67dced`.
- [x] A06 Main-branch CI confirmed green on both repos: frontend run 37947670780, backend run 37947686211 (2026-10-09).
- [ ] A07 PostgreSQL dedicated ITP HRM database + least-privilege user + isolated integration tests. BLOCKED: existing instances are shared/unknown ownership; no dedicated DB identified.
- [ ] A08 Full audit, authorization and tenant-scoping acceptance tests (read-only slice is covered under A08a; mutation permissions and audit remain).
- [x] A08a Read-only employee listing: per-company roles, explicit manager department scope, negative and temporal access tests; SQLite and PostgreSQL CI on PR #2, run 37949311877. Role matrix still provisional.
- [x] A08b Default-OFF authenticated company-admin grants/revocations for lower-privilege roles; idempotent updates, transactional audit, scoped validation and success-read audit. Tested in PR #4 on SQLite and PostgreSQL 16: 39 tests / 138 assertions (run 38041729281). Role matrix and activation still require approval.
- [ ] A08c Audited permission-denial/export events, protected audit viewing and retention, approved role matrix, non-owner DB credentials, concurrent-write tests and independent tamper-evident audit replication.
- [x] A08d DB-level audit mutation guards in PostgreSQL 16 and SQLite: UPDATE/DELETE rejected; PostgreSQL TRUNCATE rejected. 45 tests passed on each database (SQLite 150 assertions; PostgreSQL 149 assertions); PR #6, CI run 38045155468. **Not** protection against DB owners/superusers.
- [ ] A09d Controlled account provisioning, auditable lifecycle and immediate revocation; remains open pending operator audit, first-admin bootstrap and staging session E2E.
- [x] A09d1 Explicit account states and revocation for protected browser/Sanctum requests, last-active-admin guard and controlled console state transition; PR #11 merged `c36fca6`, SQLite and PostgreSQL CI run 38207724400. Follow-up regression coverage PR #12 merged `089d76d`, both DB jobs green in run 38207895109. Does NOT close A09d (operator audit, provisioning, staging remain).
- [ ] A09 Complete browser login/session with approved origin/TLS/CSRF topology and end-to-end integration (feature currently default-OFF).
- [x] A09a Default-OFF Laravel web login/logout/current-user endpoints, Sanctum stateful middleware, CSRF integration tests, 2-level login throttle and strict same-origin CORS. SQLite 62 tests/224 assertions, PostgreSQL 16 62 tests/223 assertions, no warnings; PR #8 run 38120806247.
- [ ] A09b Approved production origin, trusted reverse proxies, HTTPS cookie configuration, controlled account provisioning and session expiry/rotation E2E checks.
- [ ] A09c Vue login/client integration and browser E2E tests across frontend/backend on **approved HTTPS staging**; hostname/trusted proxy not yet selected.
- [x] A09c1 Ephemeral Chromium integration for Vue + Laravel + Sanctum, same-origin proxy: 5 browser tests passed (guest guard, login/session rotation and refresh, API auth, CSRF 419, logout/revocation, credential error/429, CORS denial). Frontend PR #3 merged, commit `947030d`, run `38206484870`; backend pinned at `0c1cdb18`. **Not** staging/prod verification.
- [x] A10 Ephemeral PostgreSQL 16 service in GitHub Actions; core + access migrations and integration tests green without warnings, run 37949311877. This does NOT provision a persistent database.
- [ ] A11 Reusable UI tables, forms, dialogs, feedback and accessibility tests.
- [x] A11a Shared accessible BaseNotice and responsive pending-module BaseEmptyState, frontend PRs #5/#6, merges `18ef12e` / `2b499cb`; frontend build runs 38211222432 / 38211460465 and Chromium session runs 38211222383 / 38211460462 green. Overall A11 remains open.
- [ ] A11b Shared loading/table/form/dialog primitives plus focused accessibility and interaction tests.
- [x] A11b1 Shared accessible loading skeleton, frontend PR #7 merge `d4f0fe3`, build CI 38211560294 and Chromium CI 38211560259.
- [x] A11b2 Responsive reusable data table with stable row identity, frontend PR #8 merge `65e2d49`, build CI 38211772650 and Chromium CI 38211772621. Table feature integration and focused interaction tests remain open.
- [x] A11b3 Reusable form and modal dialog primitives with synthetic Chromium interaction tests (validation ARIA, Escape/close, table semantics), frontend PR #9 merge `4947e12` and PR #10 merge `7e2a501`; build CI 38212232592 / 38212500964 and Chromium CI 38212232492 / 38212500968 green. A11 overall still OPEN.
- [ ] A12 Structured error handling / API response contract and tests.
- [x] A12a JSON error boundary regression tests (401/403/404/422), PR #14, merge `3a23b39`, SQLite/PostgreSQL CI run 38208205522.
- [x] A12b Server-generated X-Request-ID for API requests, ignores untrusted client header; PR #15, merge `77763b5`, SQLite/PostgreSQL CI run 38208910888.
- [x] A12c1 Additive typed JSON API error codes, PR #17 merge `0388718`, SQLite/PostgreSQL CI run 38210037402. This does not cover all exception paths.
- [x] A12d Vue API error metadata (typed code + request ID), frontend PR #4 merge `0e4c896`, build CI run 38210335626 and Chromium E2E run 38210335704.
- [ ] A12e Centralized exception sanitization, correlation in safe diagnostics and full API/browser regression. A12 overall remains OPEN.

## B — First connected business scenario
- [x] A11c Synthetic Chromium responsive regression for shared field, scrollable table and dialog at 375/768/1440px, frontend PR #13 merge `e5b3841`, build CI 38214256172 and Chromium CI 38214256220 green. Full visual parity and module integration remain OPEN.
- [ ] A11d BNT reusable directory controls: frontend PR #18 adds BasePageHeading, BaseSelectField, BasePagination and semantic Golos font tokens, removing duplicated directory layout in both modules. Frontend PR #18 merged as `8c5f1a3`, build CI 38220494860 and Chromium CI 38220494923 both green. Keep open until Chromium and visual parity checks pass.
- [ ] B01 Company hierarchy CRUD + validation + company isolation.
- [x] B01/B02 read-only organizational directory: company names and scoped department listing, backend PR #25 merge `4b5b1f3`, SQLite/PostgreSQL run 38215514386; Vue organization screen frontend PR #14 merge `d3bccaa`, build 38215669698 and Chromium 38215669655; real API browser isolation frontend PR #15 merge `8c5fdfa`, build 38215850855 and Chromium 38215850860. B01/B02 overall remain OPEN: privileged writes, full hierarchy, cycle prevention and visual acceptance pending.
- [x] LOCAL-01 Ansar isolated local smoke on 2026-10-12: backend Laravel API health HTTP 200, Vue /organization HTTP 200, frontend build passed (1625 modules), PHPUnit Feature 91 tests / 303 assertions passed with local-only PHP extensions and generated APP_KEY. Local-first-party HTTP only; no real accounts, no production access. Full visual acceptance and B02 tree mutation/cycle prevention remain OPEN; the scoped B02 Chromium regression is recorded separately.
- [x] B02-read Visual parent hierarchy cues, no hidden parent disclosure and nested synthetic Chromium regression: frontend PR #16 merge `075682e`, build run 38219073537 and Chromium run 38219073519 green. Full tree pagination, write authorization and database cycle prevention remain OPEN.
- [x] PERSONNEL-read Read-only company-scoped employee register replaces frontend placeholder: frontend PR #17 merge `940dad5`, build run 38218824098 and Chromium run 38218824303 green. Backend company-scoped employee list was already implemented; full employee cards, edit actions and dedicated browser isolation test remain OPEN.
- [ ] B02 Departments tree and cycle prevention.
- [ ] B03 Positions/staffing versions and approved state.
- [ ] B04 Full employee register, unique personnel number and authorization (create/update/delete and audit not implemented).
- [x] B04a Read-only minimal employee list with authorized company/department scopes and bounded pagination (PR #2, run 37949311877).
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
