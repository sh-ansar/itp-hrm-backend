# Initial data model

Organization: companies, branches, departments (parent_id), positions. Staffing: staffing_versions, staffing_units, vacancies. Employees: persons, employments, employee_assignments. Personnel: personnel_events, contracts, orders. Leave: leave_types, leave_entitlements, leave_requests. Timekeeping: work_calendars, work_schedules, attendance_events, timesheets. Payroll: pay_components, payroll_periods, payroll_results. Platform: users, roles, permissions, documents, audit_events, import_batches, source_mappings.

Effective date (`valid_from`, `valid_to`) and stable identifiers must be preserved on assignments, positions and staffing; historical reports must not depend on current structure. Hard deletion of referenced HR records is prohibited. Source-system identifiers map through `source_mappings`; repeat imports must be idempotent and report discrepancies.

Payroll formulas, jurisdiction-specific deductions and source formats require separate discovery with source-system owners. Do not invent coefficients or default statutory rules.
