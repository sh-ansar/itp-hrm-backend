# ITP HRM — Worktime cycle planning, phase 1

## Release boundaries

This branch introduces employee-specific **planned shifts**. It does not calculate payroll,
post salary, record payment transactions, create future actual attendance or edit production.
After the new migration is applied, enable the flags separately in a controlled environment:

- `HRM_WORKTIME_WRITES_ENABLED=true` — existing worktime write gate
- `HRM_WORKTIME_PLANNING_ENABLED=true` — new schedule templates / planning
- `HRM_WORKTIME_MANAGER_PLANNING_ENABLED=true` — allow managers to assign approved templates within department scopes

**All new flags default to false.** Manager flag without the other two has no effect.
Do not turn these on in production before backing up PostgreSQL, verifying restore in an
isolated database, verifying company calendars, and passing role-based UI/API acceptance.

## Data model

- `work_schedule_patterns` holds immutable JSON pattern versions referenced by a schedule.
  `days` is 1–60 ordered `{minutes, shift_code}` entries, minimum one working day.
  `calendar_policy=company` requires seven Monday-first entries and respects
  company holidays / non-working dates and shortened workdays.
  `calendar_policy=cycle` repeats continuously from each assignment's start and
  offset (different crews can start in different phases).
- `employee_work_schedules` records the assignment interval, pinned pattern ID,
  offset, and cycle anchor. Reassigning a future sub-interval splits history rather than
  restarting the existing crew's schedule outside that interval.
- `employee_work_plans` stores the planned daily minutes and day_kind; facts remain
  only in `worktime_entries`. Existing legacy read/write endpoints continue to work
  when planning is disabled.

## API, company-scoped and authenticated

- `GET /api/v1/companies/{company}/worktime/schedule-templates` – non-financial
  schedule catalogue for HR and scoped team planners.
- `GET/PUT /api/v1/companies/{company}/worktime/schedules/{schedule}/pattern`
  – see current pattern; HR/admin only can create a new version. PUT requires schedule revision.
- `POST /api/v1/companies/{company}/worktime/schedules/{schedule}/bulk-plan` –
  preview with `apply=false`, then submit identical payload with `apply=true`,
  `confirm=true`, and matching `preview_token`. Maximum 200 employee IDs, up to 366
  consecutive future days. Existing planned days require explicit
  `replace_future_plans=true`. Conflicts (missing calendar, missing employment,
  closed period, existing actual, approved leave, recorded absence) are blockers.
- `GET /api/v1/companies/{company}/worktime/plans?from=...&to=...&employee_ids[]=...`
  – paginated, tenant-authorized read of plan days. Deny hidden employee IDs.

## Security / invariants

- A manager can **read** the shared schedule catalogue but cannot change template
  definitions or see salary or personal identity data.
- Manager writes are restricted to their granted departments and descendants,
  and to employees with uninterrupted, unambiguous employment assignments
  inside that scope **for every day** in the requested period.
- Preview/apply hold a tenant lock, and apply checks a deterministic snapshot
  token. Failed planning is atomic. Approved/submitted months and recorded actuals
  are immutable. Audit records the actor, company, schedule/version and counts,
  never protected personal/financial values.
- Existing timesheet bulk fill uses only verified day plans for nonstandard
  schedules; it still never creates actual attendance for future dates.
- Scheduling a holiday shift records **plan only**; any overtime, rest day, pay
  premiums or labor compliance rules must be reviewed as separate payroll features.

## QA

Run `php vendor/phpunit/phpunit/phpunit --filter WorktimeCyclePlanningTest` on
ephemeral SQLite. Run the full suite with an **ephemeral random APP_KEY** and
never reuse production credentials. Frontend: `npm run typecheck`,
`npm run build:htdocs`, plus `tests/e2e/schedule-cycle-planning.test.mjs`
against an isolated Vite server. PostgreSQL migration and rollback tests
must precede the first real deployment.

## Next phases

1. Add calendar matrix (employees × days), server-side filter/group/search,
   per-day override and cross-month bulk selection.
2. Manager approval of timesheets at department scope; monthly completeness
   checks, locks, audit and workflow notifications.
3. Accountant role with granular financial capabilities and approved-timesheet
   data for payroll proposals. Never show foreign salaries to managers.