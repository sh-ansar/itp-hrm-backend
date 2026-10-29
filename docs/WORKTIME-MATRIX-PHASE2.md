# ITP HRM — Phase 2: calendar timesheet matrix (employee × day)

## Scope / deployment gate

Stacked on Phase 1 schedule-pattern planning, not a standalone production patch.

- Company-scoped GET `/api/v1/companies/{company}/worktime/matrix` supports
  `from`, `to` (1–62 contiguous calendar days, can cross month boundaries),
  `page` / `per_page` (max 50 employees), search by full name and personnel
  number, filters by department / schedule / any-recorded / none-recorded,
  grouping by department, schedule or no grouping.
- Cells show **planned** vs **actual** minutes without converting unknowns to 0.
  Worktime plans override generic company calendars, including valid weekend
  shifts. Unverified nonstandard shifts are explicitly identified.
- GET is accessible to HR, company admins and delegated department managers.
  Managers are restricted to their explicit department scopes and descendants.
  A **single unambiguous department assignment must span the full selected
  interval**; a mid-period transfer requires separate smaller date ranges.
  This conservative rule prevents cross-team data disclosure.
- POST `/api/v1/companies/{company}/worktime/matrix/bulk-edit` accepts either
  `{employee_ids:[],dates:[]}` for a rectangle or **exact sparse**
  `{cells:[{employee_id,date},...]}`. Maximum 50 employees, 62 dates and
  2500 cells. Operations:
  - `fill_missing`: fills only missing actuals from verified planned minutes.
  - `set_worked`: updates worked minutes for selected past workdays; existing
    rows require explicit `overwrite_existing=true`; leave/absence/overtime
    rows cannot be overwritten by this bulk action.
- Preview with `apply=false`, then same selected cells and options with
  `apply=true`, `confirm=true`, matching signed *snapshot digest*
  `preview_token`. The token is a consistency digest, **not** an authentication
  token. All calls require authenticated company permissions. The tenant is
  locked in a single database transaction on every evaluation.
- Future days are skipped, never written as actual attendance. Approved /
  submitted month in a cross-month selection **blocks the entire operation**.
  Recorded leave and absence are skipped and reported. Missing calendar,
  ambiguous employment and conflicting existing records are blockers.
- **Direct HR attendance, no approval:** company admins and HR specialists
  enter and correct actual worktime without submit/approve/reopen actions.
  Department managers can READ authorized team worktime and plan future team
  schedules but **cannot post actual hours**, even when the obsolete
  `HRM_WORKTIME_MANAGER_ENTRY_ENABLED` variable is set.
  The legacy period approval API is retained for compatibility only; the
  normal rollout keeps `HRM_WORKTIME_PERIOD_APPROVALS_ENABLED=false` and
  the approval widget is removed from Timesheets. Previously submitted or
  approved periods remain immutable and visibly marked read-only.
  There is no company-wide employee access, salary, bank, payroll, protected
  leave details or family information in the matrix response.
- The globally shared private employee registry remains **direct-scope only**
  (do not grant descendants automatically on HR person cards). The schedule
  planner's own read endpoint authorizes descendants independently.

## Rollout

`HRM_WORKTIME_MATRIX_ENABLED=false` by default. Requires the Phase 1
`employee_work_plans` migration; no new database migration in Phase 2.
Keep the flag off in production until:

1. Companion backend/frontend PRs pass both isolated PostgreSQL 16 tests
   and connected Chromium role/flow checks.
2. Restore rehearsal completed, all payroll/privacy role boundaries reviewed.
3. Acceptance with HR and a scoped manager on a disposable test company;
   confirm no future actuals, no closed-month edits, no hidden employee IDs.
4. Controlled deployment with flags OFF, then enable matrix for QA.

## QA commands

- Backend: `php vendor/phpunit/phpunit/phpunit --filter WorktimeMatrixTest`
  and full suite in isolated SQLite with ephemeral `APP_KEY`.
- Frontend: `npm run typecheck` and `npm run build:htdocs`.
- Browser: run `tests/e2e/worktime-matrix.test.mjs` against a loopback-only Vite
  server; tests mock data and assert sparse selection stays sparse and exact.
  Real PostgreSQL E2E must additionally pass in GitHub Actions.

## Deferred

Payroll, wage calculations, financial permissions for accountants, per-day
overtime/absence approval, salary reports, and department approvals of the
timesheet are **not** implemented in this phase; those require distinct roles,
business rules, document/audit policies, and acceptance tests.