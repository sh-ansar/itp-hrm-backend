# ITP HRM — company-scoped permission templates

## Architecture
Four canonical roles remain unchanged: `company_admin`, `hr_specialist`, `department_manager`, `employee`.
`company_role_templates` defines *restrictive* company-specific profiles, never new privileged system roles. A template has a UUID, company UUID, immutable code + base_role, name, optional description, JSON capability booleans, active/archived status, revision and timestamps. Optional `company_user_roles.custom_role_id` is constrained by the same `company_id`. Membership is always tied to exactly one canonical base role.

A company administrator may create/modify/archive/delete a template only behind `HRM_ROLE_MANAGEMENT_ENABLED=true` and `HRM_CUSTOM_ROLE_TEMPLATES_ENABLED=true`. Both default OFF. Custom templates cannot have `company_admin` as base, grant admin roles, assign `roles.interface`/`roles.assign`, change their immutable base or expand rights above the live canonical role. Capabilities are intersected with `CompanyCapabilities` **on every request**, so disabling a server feature removes access even if an older template stored true. `settings.view` and `self_service.view` always retain their canonical state.

**Authorization is always server-side**. `EnforceCompanyRoleTemplate` is extra middleware after Sanctum+active account. It checks the caller's company membership and template, then maps each company resource and method to explicit permissions. Unknown resources fail closed for custom roles. All existing detailed authorizers remain in place and can only further narrow access. A new company endpoint must be added to the middleware allowlist, with positive and negative API tests, before exposing it to custom roles.

For employee details, `employees.view` allows the basic registry and basic employee card only. `employees.extended_view` additionally governs private fields, protected profile, education and audit projection. `employees.write` is separate; the controller's effective write flags must reflect this. Private leave evidence has an independent `leaves.evidence.view` gate in addition to the normal leave and owning-user authorization. Company manager department scopes are still checked by canonical authorizers.

Company role assignments are serializable within user row locks and transactional audit, with grant/replace/revoke semantics. Template writes use row locks, compare-and-swap `revision` (409 on stale save), strict validation of UUID/company/base, and audit events. A template cannot be archived or deleted while assigned. Built-in `company_admin` and self membership changes remain forbidden over HTTP.

## API
- `GET /api/v1/companies/{company}/role-templates`: list templates with assigned_count and effective capabilities.
- `POST /api/v1/companies/{company}/role-templates`: body `{code,name,description,base_role,capabilities}`; returns 201.
- `PUT /api/v1/companies/{company}/role-templates/{uuid}`: body `{revision,name?,description?,capabilities?,status?}`, immutable `base_role` and `code`.
- `DELETE /api/v1/companies/{company}/role-templates/{uuid}`: body `{revision}`, only with zero assignments; returns 204.
- `PUT /api/v1/companies/{company}/memberships/{user}`: optional `custom_role_id` (null clears) plus canonical `role_code` matching the template's `base_role`, and manager `department_ids`. The template must be active and belong to the company.
- `GET /api/v1/me/access`: effective server-granted capabilities and custom role name/id, all session-private/no-store.
- `GET /api/v1/companies/{company}/memberships`: paginated, includes template id/name and server audit. Frontend reads all pages (100 per call).

## Deployment
1. Merge backend + frontend only after CI, browser E2E and tenant tests pass.
2. Back up **production HRM DB** and verify actual restore in a separate database before *any* migration. Other systems on the shared host must not be altered.
3. Apply the additive migration `2026_11_04_140001_create_company_role_templates.php` to HRM only, with the authorized migration role.
4. Deploy backend and frontend atomically to the HRM isolated Apache/FastCGI paths with rollback to the previous build.
5. Set both feature flags true only after the migration, route and browser checks, using HRM-specific configuration. Never set `APP_DEBUG=true` on production.
6. Verify custom role create → grant → view/deny endpoints → update/revoke → audit, plus four canonical roles, on controlled test accounts. Check HRM and non-HRM service hashes.
7. The migration is additive; rollback old code without dropping the new column when production assignments exist. Never drop the table/column after real roles are created without a data-preserving migration plan.

## Validation
- Tests: `tests/Feature/CompanyRoleTemplateSecurityTest.php` including role escalation, cross-company denial, manager scope, profile redaction, medical evidence, stale revision, archived role and flags.
- Browser: `C:\deploy\hrm\qa-playwright\qa-rbac-role-matrix-20261104.mjs` on the isolated `hrm_role_matrix_qa_20261104` database.
- New capability keys require both server policy, middleware classification, UI label, and positive and negative tests.
