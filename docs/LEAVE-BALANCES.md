# Leave balance ledger: maintainer contract

Implemented in backend PR #102. This module records **reviewed annual leave entitlements** for each employee, leave type and calendar year. It does not implement or infer legal accrual. All stored values are company-scoped.

## Key concepts

- `leave_types.annual_days` is a **reference guideline**, never an assigned employee allowance. A missing entitlement year returns `state=unconfigured`, `granted_days=null`, `available_days=null`.
- The ledger applies **only** to ordinary leave categories. Maternity, childcare and medical leave use independent workflows. Their periods must never consume annual days.
- `leave_balance_periods` is one row per `(company, employee, ordinary leave type, year)`. `granted_days` is entered explicitly by a company administrator. `adjustment_days` and `carried_days` are updated only by audited transactions. `revision` provides optimistic concurrency.
- `leave_balance_entries` records positive and negative adjustments, grants, carryovers and closures with the acting user, date and **reason**. Server-side `hr_audit_events` is the append-only security audit; don't modify historical ledger entries with ad hoc SQL.
- `booked_days` is based on **approved** `employee_leaves`, including imported historical approved leaves. Do not also deduct the request that produced each approved leave.
- `pending_days` is the total of **submitted**, not yet approved requests. It is displayed separately and does **not** decrement `available_days` until approval.
- While a year is **open**, `available_days = granted_days + carried_days + adjustment_days - booked_days`. If the entitlement isn't configured or a cross-year working-day count cannot be verified, `available_days=null`, not zero. A negative balance can reveal an existing historical deficit; **never conceal or overwrite** it with a fabricated grant. A **closed** year reports `available_days=0` because it cannot accept new bookings; `closing_available_days` is the immutable recorded balance immediately before closure, and `carried_out_days` is the amount explicitly transferred to its next year. Remaining untransferred days are not assumed to be paid out or forfeited.
- A leave crossing December/January is apportioned by year. Full approved periods reuse the preserved `planned_days`; cross-year working-day parts require a complete `production_calendar_days` for the relevant slice, otherwise the affected balance is unknown.

## Company administrator workflows

| Action | Endpoint suffix | Checks |
| --- | --- | --- |
| Grant year | `POST /leave-balances/periods` | Approved reason, year, employee, ordinary type, 0–366 days, unique year |
| Adjust | `POST /leave-balances/periods/{period}/adjust` | Revision, signed days, reason, not closed, nonnegative post-adjustment balance |
| Carry forward | `POST /leave-balances/periods/{period}/carryover` | Target is next calendar year, same company/employee/type, source pending count 0, enough source days, both years open, once per source |
| HR read | `GET /leave-balances?employee_id=...&year=YYYY` | Company admin/HR role and correct company; no mutations |
| Own read | `GET /me/leave-balances?year=YYYY` | Current active role plus explicit `employee_user_links`; submitted employee IDs rejected |

All endpoints above are under `/api/v1/companies/{company}`. Reads are private/no-store. Carryover credits the target and closes the previous year **atomically**. The old year retains `closing_available_days` for audit even when a partial carry is approved. Closing is not a statutory payout or leave forfeiture; any financial interpretation needs an independently approved HR policy.

## Feature flags and release gates

- `HRM_LEAVE_WRITES_ENABLED`: existing leave requests. The new ledger does not turn it on automatically.
- `HRM_LEAVE_BALANCE_WRITES_ENABLED`: explicit grant/adjust/carry endpoints (**default false**). Only `company_admin` may write.
- `HRM_LEAVE_BALANCE_ENFORCEMENT_ENABLED`: company-wide mandatory entitlement checks at leave approval (**default false**). Independently, once a year is explicitly configured for an employee/type, that period's own overspending/closure checks apply.
- Before enabling global enforcement, assess all employees, active leave types, current/prior-year periods, existing approvals and calendar completeness. Missing grants must fail closed, not silently seed annual-day defaults.
- Record written company HR/Legal policy on entitlement eligibility, accrual frequency, work-year vs calendar-year boundary, carryover limits/expiry, negative historical balances, protected leave, partial years, terminations and reversals. These policies are **not** implied by Kazakhstan, Georgia or any other country's defaults.
- Production deployment must separately verify backups/rollback of the additive migration, PostgreSQL permissions, HR/admin role matrix, audit archive, HTTPS/auth, isolated MinIO and redacted logs. A green GitHub test run isn't a live production acceptance.

## Tests and maintenance

Run `php artisan test --filter=LeaveBalanceLedgerTest` in an isolated SQLite environment and check PostgreSQL 16 jobs in `.github/workflows/ci.yml`. The synthetic tests cover unconfigured vs zero, cross-company denial, unlinked/revoked user, overspending, protected categories, duplicate and stale revisions, source closure, second carry attempt and cross-year missing calendars. Frontend PR #157 covers role-scoped UI, year selector and Chromium E2E.

Do not change already-merged migrations. Add subsequent migrations and tests for legal accrual/reversals, carryover expiry, caseworker approvals or payroll integration **after** the business policy is agreed.
