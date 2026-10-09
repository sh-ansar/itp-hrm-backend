# ITP HRM: preliminary employee-read access model

Status: technical proposal, pending owner approval of complete role/permission matrix. No employee write actions or role-grant API are added by this PR.

## Authorized roles (per company)

| Role code | Initial read access |
| --- | --- |
| `company_admin` | Employee names and personnel numbers **within granted company only** |
| `hr_specialist` | Same limited read scope within granted company |
| `department_manager` | Only employees with an assignment effective today in **explicitly granted departments** in that company |
| `employee` | No employee-list access. Self-service requires a verified user-to-employee mapping, not yet defined |
| Unknown/no company role | Denied (403) |

One user has at most one role per company in the first iteration. Scopes never cascade implicitly to descendant departments. Historical/future-only assignments do not grant manager visibility. Employee details, salary, national IDs, health data, passport and document downloads are not returned.

## Tables and constraints
- `company_user_roles(user_id, company_id, role_code)`: unique per user and company, no default grant.
- `department_access_scopes(user_id, company_id, department_id)`: composite foreign keys enforce both an existing company role and the department belonging to the same company.
- Tables are managed only through trusted administrative DB/bootstrap mechanisms until authenticated, audited grant flows exist. **Do not create an open role-management endpoint.**

## Security expectations
- Every HR route must authenticate with `auth:sanctum`.
- Company ID must be validated before processing; user-provided company ID does **not** grant access.
- SQL always restricts `employees.company_id`, and managers require effective assignments + explicit department scopes.
- The index endpoint returns a bounded paginated minimal projection. 401 anonymous, 403 unauthorized company/role, 422 invalid per_page, 404 malformed company UUID route.
- This is not a full HR privacy or permission solution. Payroll, editing, self-service, audit and full RBAC remain blocked on separate tasks.
