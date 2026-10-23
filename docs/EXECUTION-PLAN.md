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
- [ ] Validate the full PHPUnit suite and browser integration against the current backend before marking the employee-card module DONE.

## Definition of done
Migration + authorized API + validation + history + tests + error/loading/empty UI states + docs + git SHA. For business features, passing only skeleton tests does NOT count as done.

## Next action
Next steps: agree staging HTTPS origin and trusted proxy; run browser E2E against that approved domain and design controlled account provisioning/deprovisioning. CI already validates isolated browser login but not real deployment. Keep both login and role-management flags OFF. Then implement authorized employee mutations and denied/export audit coverage. DB-level ordinary DML protection is implemented; immutable external archive, retention and non-owner runtime DB roles remain open. The role-grant API stays disabled by default. Establish a persistent dedicated PostgreSQL DB only after confirming isolation. Continue GitHub-only branches/PRs.

## Employee card persistence and history (2026-10-13)
- [x] Core personal/contact/workplace fields are wired into the real frontend form, with shared controls and grouped drawer sections.
- [x] Legacy employee edits preserve omitted optional fields; explicit null clears only the requested field. Revision conflicts and transactional audit remain enforced.
- [x] Employee history projects the latest 100 immutable events for the selected company/employee to HR/company administrators only; raw audit context and personal values are excluded.
- [x] Create requests reject unknown fields, including attempted company reassignment.
- [x] Local full SQLite/PHPUnit: 173 tests, 857 assertions passed under PHP 8.5.11; two existing PDO MySQL constant deprecations in framework database config. PHP 8.4/PostgreSQL CI and Chromium persistence/history are checked on the paired PRs before merge.
- Next: protected photo storage/upload and approved sensitive field contracts (identity documents, IIN, bank data); staging/DB isolation remains a separate gate.

## Employee photos and prototype parity — 14 October 2026
- [x] Private employee JPEG/PNG upload, replacement, authenticated reading and removal; all mutations reuse the personnel write gate, company/HR scope, revision checks and atomic audit.
- [x] Decode and normalize images to JPEG up to 1024 px, stripping metadata and trailing data; JPEG EXIF orientation is honored. Inputs are limited to 5 MiB, 4096 px and 8 megapixels.
- [x] Files are private. No original filename, physical path or EXIF metadata is projected into employee responses.
- [x] Employee chart joins use scoped assignment IDs; managers cannot project a latest overlapping assignment from an out-of-scope department. Birth date/gender appear only in HR/admin registers.
- [x] Browser photo upload/reload/replacement/rejection/removal passed on isolated SQLite; backend tests cover role/company/revision, invalid files, normalization and audit rollback cleanup.
- Full validation results and exact reference coverage are recorded on the paired PRs before merge. Real staging login/write gates remain unchanged.
- Next: sensitive IIN/identity/banking contracts; staffing approval/version comparison and payroll rules remain independent domain work.

## Verified vertical slices — 15 October 2026 (GitHub-only, not staging)
- [x] B68 + F120: company production calendar editing, role flag, optimistic revisions, transaction-coupled audit; SQLite and PostgreSQL and Vue browser CI green. Real feature flags remain OFF in deployment.
- [x] B69 + F121: company leave types, draft/submitted/approved/rejected/cancelled requests, four-eyes approval, policy-derived leave days and approved schedule projection; SQLite/PostgreSQL/frontend CI green. Leave accruals and reversals remain OPEN.
- [x] B70 + F122: effective `/api/v1/me/access` capabilities, company-sensitive navigation and module company selection; backend and Vue CI green. Custom real-world roles and employee self-service remain OPEN.
- [x] B71 + F123: protected employee personal and military data, encrypted IIN, versioned edits, HR-only detail, mobile browser flow; green on SQLite/PostgreSQL/TypeScript and Chromium.
- [x] B72: repeatable family and identity documents, encrypted document numbers, primary partial unique index, soft archive, HR-only API; SQLite/PostgreSQL 16 CI green.
- [x] F124: Vue family/identity manager, frontend PR #124 merged with its frontend build, CI, Chromium and demo E2E checks passing. Production rights and storage remain separate gates.
- [ ] Production acceptance: dedicated project PostgreSQL, actual HTTPS/staging login/proxy, business role matrix, proper private file import/scan, real employment/payroll rules, backup and restoration rehearsal.

### Next maintainer priority
Follow `docs/MAINTAINER-START-HERE.md`; F124 is merged and browser-verified. Next review document retention/identity confidentiality, leave/attendance corrections and country-specific statutory rules. Apply migrations only on disposable CI or an approved project-owned staging DB. Do not declare production readiness.

## Contract expiry windows — 16 October 2026
- Employment contracts use the existing normalized, versioned `employee_contracts` model and HR-only endpoints; no duplicate employee fields or new migration.
- Optional employee-register `contract_expiry_days=30|60|90`, default 30, counts only the existing authorized employee query. Manager aggregates remain null; cross-company requests remain forbidden.
- Isolated SQLite contract tests: 7 passed, 61 assertions. CI must also pass PostgreSQL before merge; paired frontend verifies selector, server count, edit and reload.
- Compact route/scenario map: `docs/PRODUCTION-SCENARIOS-20261016.md`. Production acceptance is limited to the evidence recorded there.

## Employee self-service — 16 October 2026
- Additive `employee_user_links`: explicit company-admin assignment, tenant FK, one account per employee/company, optimistic revision and transactional audit. No email/name matching. Revoked membership cannot use a retained link.
- Own workspace exposes only linked employee identity, own submitted correspondence and confirmed leave; never private profiles, HR planning notes or other employees. Company membership is enforced independently of navigation.
- Own draft/edit/submit/cancel endpoints reuse `LeavePlanWorkflow`, day policies, overlap/revision guards and existing HR/admin decisions. Four-eyes now also excludes an approver's own linked employee record, even when HR created its request.
- Isolated security/workflow tests cover forged identities, cross-company links, duplicate/stale links, unlinked/revoked access, own revisions and approved projection. SQLite and PostgreSQL CI required before merge.
- Next: broader own-data tabs (timekeeping and permitted personal documents), leave accrual/reversal rules remain separate work; do not invent balances.


## 19 October 2026 — Employee annual leave entitlements (backend PR #102)

- [x] Explicit year/employee/ordinary leave-type grant ledger, adjustments and next-year carryover, with additive migration, company-row locking, optimistic revisions and security audit.
- [x] Read-only `GET /me/leave-balances` for explicitly linked employees and HR/admin read with tenant isolation. Missing employee grants and incomplete calendars return unknown/null, never auto-seeded values.
- [x] Approved ordinary leave periods are checked against configured days. Global mandatory check remains **OFF** until company HR/Legal business policy is agreed and all historical year periods are loaded.
- [x] SQLite and PostgreSQL CI validated PR #102; no production migration or feature activation performed.
- [ ] Approve business rules for annual accrual, carryover cap/expiry, employment anniversary vs calendar year, reversals, historical deficits and statutory protected leave.
- [ ] Rehearse staging migration, verify live PostgreSQL backup and roles, then approve flag activation by company.
- [x] Frontend PR #157 merged with all five checks green (annual leave balances UI, Chromium, demo and `/hrm` subpath). Annual leave policy activation remains separate.
- Developer handoff: `docs/LEAVE-BALANCES.md`.


## 19 October 2026 — Supporting documents for protected leave

- [x] Private MinIO supporting-PDF record, authorized own/HR download, HR review, rejection and replacement. Medical, maternity and childcare leave do not consume ordinary annual balances.
- [x] Backend PR #105 merged, SQLite/PostgreSQL security tests passed. Two independent feature flags remain OFF by default.
- [x] Frontend PR #159 merged after five green checks, including role-isolated Chromium private-document upload/review.
- [ ] Before production release approve document retention/deletion, malware screening, HR authorization/medical confidentiality and jurisdiction/company-specific evidence policies.
- [ ] Stage PostgreSQL migration, back up data and validate MinIO, HTTPS and account-linked role isolation, then separately enable uploads and mandatory evidence after pending requests are reviewed.
- Maintainer guidance: `docs/PROTECTED-LEAVE-EVIDENCE.md`.


## 19 October 2026 — Internal personnel order review (backend PR #107)

- [x] HR/admin can create a PDF draft with an auditable creator, and the author can submit a numbered, dated, private PDF for review.
- [x] A **different company administrator** can approve or reject (reason mandatory on rejection). Stale revisions, self-approval, own-subject approval, cross-company and unlinked roles are denied.
- [x] Separate employee PDF release is withheld until an order is internally approved when the opt-in feature is enabled; revocation remains available.
- [x] Backend SQLite and PostgreSQL CI passed for PR #107, merged into main. No production configuration, migration or records were modified.
- [x] Frontend PR #160 merged after five green checks including HR→admin→employee Chromium review/release and `/hrm` subpath E2E.
- [ ] Legal/HR must decide electronic signature, numbering, pre-action authorization vs already-executed personnel action, retention and compliance before any production rollout.
- [ ] Back up and rehearse additive migration on staging, validate existing shared orders, MinIO and role matrix. Explicitly enable `HRM_ORDER_APPROVALS_ENABLED` only after separate approval.
- Maintainer contract: `docs/PERSONNEL-ORDER-REVIEW.md`.


## 19 October 2026 — Unified approval inbox (backend PR #109, frontend PR #161)

- [x] HR document registry supports tenant-isolated `document_type=order&status=submitted`; pagination `meta.total` is the accurate database count, not just records on the current page. Backend SQLite/PostgreSQL CI green, PR #109 merged.
- [x] «Кадровые процессы» shows server-filtered pending leave, bonus and order counts using their existing authorized endpoints. Categories that the caller cannot read remain hidden. Mobile-responsive RU/KK/EN/KA; exact order filter/deep link verified in isolated Chromium.
- [x] Frontend PR #161 merged after five green checks; no read permissions broadened, no production flags enabled.
- [ ] Review frontend PR #162 (confirmation before executed hire/transfer/termination); tests must prove Cancel does not modify staffing assignments and authorized confirmation still works.
- [ ] Production activation and full E2E with owned PostgreSQL/MinIO, backups, HTTPS and verified organization-specific role matrix remain outstanding.

## 22 October 2026 — Staged manager and HR leave review

- Added default-OFF HRM_LEAVE_MANAGER_APPROVAL_ENABLED and an additive review migration. Existing legacy workflow remains compatible while OFF.
- With the flag ON, the employee submits, an explicitly department-scoped manager recommends/rejects, then a different HR/admin makes the final decision. Manager approval does not publish or deduct leave. Confidential request fields and evidence metadata are redacted from manager reads/writes.
- Security tests cover stage bypass, revoked/transferred scope, company boundaries, self-review, repeated/stale decisions, forged metadata, role change and confidentiality; legacy workflow/evidence/balance suites remain required.
- Verification: final SQLite suite and PostgreSQL CI results are recorded on the PR.
- Production untouched: no config activation, migration or employee-data changes. Paired Vue/real-browser verification remains required before activation. Notifications and approved-leave reversals remain separate unfinished work.
- Maintainer contract: docs/LEAVE-MANAGER-REVIEW.md.


## 23 October 2026 — Future approved leave reversal

- Added a separate default-OFF, company-admin-only reversal endpoint with required trimmed reason, optimistic revisions and company/own-employee restrictions.
- Preserves approved leave and request history, records actor/time/reason, and atomically audits cancellation. Schedule, employee workspace, balance and overlap projections now exclude cancelled bookings; rebooking is possible.
- Started/actual attendance, closed entitlement years, recorded timesheet entries and locked timesheet periods prevent automatic reversal. Retroactive corrections remain separate HR policy work.
- Isolated PHP 8.4.26/SQLite regression: 26 tests, 364 assertions passed, including four new reversal tests. PostgreSQL CI and paired UI acceptance remain required before activation.
- No production data or feature flags changed.

## 23 October 2026 — period acceptance follow-up (local, not production)

- Extended WorktimePeriodReviewTest with department-manager/employee/foreign-company denial, absence of period/audit mutations after denied requests, actual administrator self-approval denial, and rejected reopen reasons without side effects.
- Verified under PHP 8.4.14 with project-local Composer dependencies and exif enabled per process: Worktime tests 13 passed, 149 assertions, SQLite in-memory. No backend runtime or migration changes in this follow-up.
- Paired Vue acceptance against backend d6117d7 in a separate disposable browser-e2e.sqlite passed: real HR entry save, submit, different-admin approval, write denial, reopen reason validation, persisted revision/audit, stale-response month switching, controlled GET outage and delayed-read edit gating. All POST/PUT transitions used real Laravel HTTP; only a read outage was simulated.
- PostgreSQL verification of these additional tests and remote CI are still required. Production databases, flags and SMTP unchanged.
- Next: publish reviewed paired changes, run PostgreSQL CI, then complete approved-leave reversal and durable notification acceptance.
