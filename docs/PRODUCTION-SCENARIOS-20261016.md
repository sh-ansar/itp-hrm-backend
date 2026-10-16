# Production scenario map — 16 October 2026

Routes below are relative to `/hrm-api/api/v1`. `C` means `/companies/{company}` and `E` means `C/employees/{employee}`. “CI” means synthetic browser integration, not a production write to business records. Frontend #150 and #151 browser checks passed; deployed frontend cd61f68, backend 148b7f9 before the expiry-window follow-up.

| Screen / action | Frontend API module | Backend route | Evidence / gap |
|---|---|---|---|
| Home / personnel summary | personnel, staffing, personnelActions | GET C/employees, C/staffing-plans, C/personnel-actions | Connected reads; responsive CI |
| Employees / create, edit | personnel | POST C/employees; PUT E | Write CI |
| Employee card / profile, contacts, address | personnel | GET E; PUT E; PUT E/private-profile/{section} | Write/reload CI; private boundary preserved |
| Employee card / photo, education, family, identity | employeePhoto, personnel | E/photo; E/education; E/private-records/{section} | Synthetic write/reload CI |
| Organization / departments and positions | organization | C/departments; C/positions | Write CI |
| Staffing / plans and lines | staffing | C/staffing-plans, lines, approval | Write CI |
| Personnel actions / hire, transfer, termination | personnelActions | POST E/personnel-actions/{hire,transfer,terminate} | Write CI |
| Bonuses / draft, submit, decision | bonuses | C/bonuses; /submit; /decision | Workflow CI; existing mechanism reused |
| Schedules / create, edit, assign; timesheets | worktime | C/worktime/schedules; schedules/{id}/employees/{id}; timesheet/{id} | Write/reload CI |
| Calendar / dates | worktime | GET/PUT C/worktime/calendar | Bulk API exists; UI only fills missing 5/2 dates and edits one saved date at a time |
| Leave / types, requests, submit, decision | leaves | C/leave-types; C/leave-requests; /submit; /decision | Workflow CI; maternity category exists; employee self-service remains separate |
| Documents and orders / draft, upload, download | documents | C/documents and file routes | Synthetic private PDF browser CI |
| Reports / tabs | personnel, staffing, development, worktime | Existing authorized read routes | Connected browser CI; no new report service required |
| Directories / CRUD | directories | C/directories/{group} | Write CI |
| Roles / grant and revoke | roles, access | C/memberships/{user}; /me/access | Server authorization exists; current A09 disables role writes, dedicated role browser acceptance remains open |
| Settings / notification persistence, password | preferences, session | GET/PUT /me/preferences; POST /auth/password outside /api/v1 | Persistence/reload and password policy CI |
| Contracts / history, create, edit, expiry | personnel | GET/POST E/contracts; PUT E/contracts/{id}; GET C/employees | Write/reload/revision CI; follow-up adds selectable 30/60/90-day summary |

## Next confirmed gaps

- Bulk modification of already populated calendar dates using the existing versioned batch PUT.
- Dedicated browser acceptance of role assignment and department scope with admin/HR/manager/employee identities; do not infer success from route availability.
- Remaining employee compensation/allowance/deduction editing and attachments must be traced to existing models before designing writes.
- This map does not certify all prototype fields, all localized copy, or every role-specific production flow.
