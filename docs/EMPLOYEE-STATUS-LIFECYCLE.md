# HRM employee employment status and archive contract

## Purpose
The employee **archive is a derived read-only view**, not a destructive archive operation or a document-retention state. It does not delete an employee, private records, attachments, audit history, assignments or personnel actions.

## Effective-date semantics
Assignment intervals are inclusive: `valid_from <= today <= valid_to`, or open ended when `valid_to` is null. The termination action's `effective_on` is recorded as the **last working day** (`employee_assignments.valid_to`). Consequently an employee terminated on 28 October remains `active` on 28 October and enters the `archived` filter on 29 October, if no other current or future appointment exists. This is consistent with payroll/timesheet eligibility for the final working day.

The server currently exposes:
- `active`: at least one current effective assignment;
- `planned`: no current assignment but at least one future assignment;
- `archived`: no current or future assignment, with at least one ended assignment;
- `unassigned`: no current, future or ended assignments.

A new future appointment after termination makes the employee `planned`; from its start date the employee becomes `active`. Earlier assignments and the termination remain immutable and discoverable in personnel history. No automatic account deletion, permission grant, email invitation or leave-balance adjustment occurs from a filter change.

## Authorization and boundaries
Archive entries are returned only through the existing company/department-scoped EmployeeVisibility authorization, filtered before pagination. Employee details and private documents retain their own independent authorization. Archived employees are not globally readable or implicitly downloadable.

## Acceptance and UX
The HR registry archive tab uses server `status=archived`. An empty archive immediately after a same-day termination is expected until the next calendar day. Explain this in the empty state; do not falsify employee status or mark a document as legally issued. Onboarding/re-hiring must preserve the original employment record and all personnel audit events.

For a jurisdiction/company that requires **immediate administrative archiving on the termination day**, define a separate approved administrative archive/retention state with its own authorization, legal retention and audit; do not silently change effective-dated history.

Tests: `EmployeeRegistryStatusFilterTest` (company-scoped filtering before pagination) and `PersonnelActionWorkflowTest` (hire → terminate → day-after archive → rehire → active, history retained).
