# ITP HRM — Functional and technical specification (v0.1)
Status: WORKING DRAFT. Source: user-provided presentation "Кадры 2026". Unverified requirements are listed separately; do not treat proposals as customer-approved.

## 1. Purpose and boundaries
Replace fragmented personnel functionality across 1C7, 1C8, FoxPro HR accounting and BSP Wage with one web application, preserve legacy data and established business workflows. New stack by project decision: Laravel 12 REST API, Vue 3/TypeScript SPA, PostgreSQL primary data store and Redis as auxiliary queues/cache. Do not migrate real employee data before an approved migration plan.

## 2. Functional requirements (presentation-derived)
FR-01 Personnel register: employee cards, work experience, reserve, learning history, supporting files.
FR-02 Organization/staffing: divisions, job titles/categories, staffing plans and change history, positions with requirements and allowances, vacancy records.
FR-03 Personnel events: hire, transfer, termination, labor contracts, orders, basis documents and reporting.
FR-04 Leaves: leave types and rules, schedules, balances, approval and reminders.
FR-05 Timekeeping: calendars, shift/individual schedules, absences, sick leave, travel, overtime, timesheet calculation/print/reporting.
FR-06 Learning/talent pool: training category/form/provider/period/certificates; position, level and inclusion/exclusion dates in talent pool.
FR-07 Recruitment: vacancies, candidate CV repository and search.
FR-08 Analytics: workforce numbers, turnover, vacancies, training, time, sick leave, IFRS long-term employee benefit data.
FR-09 Personnel costs: compensation catalogs, position allowances, expense plans and exchange with 1C.
FR-10 Payroll: earned wages, one-off allowances, average wages, contractor payments, deductions including writs of execution, payment lists, pay slips and payroll reports. **Algorithms are NOT supplied by presentation.**

## 3. Cross-cutting nonfunctional requirements (engineering proposals; require approval)
NFR-01 RBAC plus company/department row-level scoping; deny by default; unauthorized access must not expose employee data.
NFR-02 Effective-dated employment/assignment history; no destructive edits to closed periods; audit of sensitive writes and exports.
NFR-03 Consistent reusable Vue component library adapted from demo_bnt_dt; loading, empty, error, focus and responsive states.
NFR-04 Private document storage and guarded download; no public employee records, files, credentials or exports.
NFR-05 Validation server-side, PostgreSQL constraints, atomic transitions, idempotent imports, pagination and bounded queries.
NFR-06 Observability, structured exceptions, correlation ID, controlled client error messages; queue retries with failed job visibility.
NFR-07 Automated backend and frontend CI; tests are required prior to checking boxes.
NFR-08 Separate local/test/staging/production settings; backup/restore drill before cutover.

## 4. Architecture
Separate repositories `sh-ansar/itp-hrm-frontend` and `sh-ansar/itp-hrm-backend`. Laravel modular monolith divided into Organization, Staffing, Personnel, Timekeeping, Leave, Payroll, Documents, Reports, Integration and Administration. API version prefix `/api/v1`. PostgreSQL canonical records; Redis cache, jobs and locks only. SPA via typed API client; credentials as secure first-party cookies subject to final deployment topology.

## 5. Acceptance and traceability
A requirement is DONE only if code, API contract, authorization, database migration, positive/negative tests, interface states, documentation and committed SHA are linked in the checklist. If not applicable, explain in checklist; never check off based on appearance or a demonstration stub. Integration-specific tests must run on PostgreSQL (SQLite unit tests are insufficient).

## 6. Open decisions / customer confirmation
- Which legal jurisdictions, entities and labor-law rules apply?
- Exact personal card fields and printable templates?
- Role/access matrix for branches, departments, HR, payroll and management?
- Original systems' schemas/export methods and matching keys?
- Payroll formulas, cutoffs, deduction rules and verified reconciliation examples?
- Approval workflow/retention terms and full report catalog?
- Hosting, SSO, backup RPO/RTO and data protection approvals?
All unspecified values remain explicit assumptions rather than implementation facts.

## 7. Delivery order
Phase 0: environment, security baseline and shared UI. Phase 1: organization/staffing/employees/assignments. Phase 2: employment events/documents/leaves. Phase 3: timekeeping, talent, recruitment, analytics. Phase 4: payroll only after discovery and validation. Phase 5: legacy migration/cutover.
