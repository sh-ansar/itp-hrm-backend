# Effective company capabilities contract (B70)

## Purpose
The Laravel API is the only authority for tenant roles and business writes.
Vue consumes GET /api/v1/me/access to tailor menus and views, but every
backend controller MUST continue authorizing each request. The projection is
not a JWT claim and is never accepted as proof of permission.

GET /api/v1/me/access
- Authenticated Sanctum account only; no user lookup or freeform role parameters.
- Cache-Control: private, no-store. Re-fetch on session changes or protected
  navigation because role revocation must take effect immediately.
- Schema:
  data.companies[] = {
    company_id: uuid, company_name: string,
    role_code: company_admin|hr_specialist|department_manager|employee,
    department_scopes: [{ id: uuid, name: string }],
    capabilities: Record<capability_key, boolean>
  }
  meta = { schema_version: 1, source: "server" }
- Role checks are per COMPANY and never unioned across different companies.
- Unknown roles get no privileges. Employee-only membership has no HR reads.

## Current capabilities
- Reads: overview.view, employees.view, employees.extended_view,
  organization.view, staffing.view, personnel_actions.view, worktime.view,
  leaves.view, documents.view, directories.view, development.view,
  reports.view, settings.view.
- Writes: employees.write, staffing.write, documents.write,
  worktime.calendar.edit, leave.requests.manage, leave.requests.approve,
  leave.types.manage, roles.assign.
- roles.interface is FALSE in connected mode: Vue demo role matrix/users/logs
  are NOT the real role assignment database and must not be displayed as live.
- reports.view is FALSE until an authorized backend reporting contract exists.
  Vercel demo visual remains separately available.

## Initial roles (business approval pending)
- company_admin: implemented HR reads, write capabilities gated by flags.
- hr_specialist: implemented HR reads, personnel/document/leave plan edits
  gated by flags; cannot approve a leave or edit global calendar.
- department_manager: scoped minimal employee/org/worktime/leave reads;
  cannot view staffing, HR documents or write.
- employee: settings only; complete self-service is NOT ready.
- A user can belong to multiple companies with DISTINCT roles. Vue must
  use the active company context, not a union of all roles.
  The backend always validates path company and route scopes.

## Maintainer quality gates
- Each capability must map to an implemented backend guard.
- Role schema changes require version bump and client tests.
- Negative feature tests: roles, company isolation, manager scopes, feature
  flag OFF/ON, revocation and stale sessions.
- No production role-management switch while its flag defaults OFF.
- Custom role matrices need dedicated normalized role/permission tables,
  migrations and audited APIs; current demo matrix is not a real feature.
- Handoff: review backend B70 + frontend F122 together.
