# Employee private profile (B71) – source prototype -> Laravel/PostgreSQL

## Source of truth / scope
Reference: project archive Zип.zip, Employees Page -> Employee cards ->
"Личные данные 0/1/2/3". These images are visual requirements, NOT production
employee records.

This release implements durable storage for:
- Personal identifiers and HR-only personal reference details.
- Nationality/citizenship, marital status, registration and residential
  addresses, entrance/floor/apartment, place of birth, emergency contact.
- Military-registration status, category, document reference and comment.

It does NOT pretend that screenshot examples are actual employee data.
Identity-document versions, multiple family members, multiple addresses,
document uploads and birth certificate attachments are separate normalized
tables / secure private-file workflows planned for B72+. Payroll data and
worktime records will be projected from their own subsystems, never copied
into a flat employee-profile JSON column.

## Tables, keys, confidentiality
employee_private_profiles
- employee_id UUID PK; company_id UUID; composite
  FK(employee_id, company_id) -> employees(id, company_id).
- IIN/personal identifier stored only in iin_ciphertext (Laravel Crypt with
  server APP_KEY). Do not expose in logs, dashboards, registry or search.
- Mutable fields are columns, not an unvalidated dynamic JSON object.
- revision, created_at, updated_at; one row per employee per company.

employee_military_registration
- employee_id UUID PK; company_id UUID; same tenant-constrained FK.
- status, category, document_reference, comment + revision/timestamps.
- document_reference is a neutral HR reference string, NOT a public URL,
  filesystem path, stored binary or proof of uploaded verification.
- No storage of military document contents in this table.

The database is migrated additively. No updates to existing employees
or old migrations. Restrict deletion to preserve employment history.

## API
Existing GET /api/v1/companies/{company}/employees/{employee} now has:
- HR/company_admin: private_profile.personal (including revision) and
  private_profile.military (including revision), even when sections unfilled.
- department_manager: no private_profile property whatsoever.
- meta.private_profile_writes_enabled indicates true only to HR/admin
  when the HRM_PERSONNEL_WRITES_ENABLED feature flag is ON.

PUT /api/v1/companies/{company}/employees/{employee}/private-profile/{section}
section = personal | military
- Protected by auth:sanctum, active-account middleware, company membership,
  EmployeeVisibility and HR/admin role; default OFF with HTTP 404.
- Request is an entire section snapshot: explicit nullable fields and
  revision:null for first insert or current positive integer for update.
- 409 on stale revision (no updates/audit); 422 for invalid or unexpected
  fields; 403 for unauthorized membership; 404 if target missing or disabled.
- Laravel FormRequest validates; domain service owns transaction,
  parent-employee FOR UPDATE lock, encryption and privacy-safe audit.
- Response { data:{section,revision} }; frontend should reload current
  employee detail instead of applying guessed optimistic PII state.

## Security and handoff notes
- Encrypted values require durable secured APP_KEY backups. Key rotation
  needs an explicit re-encryption migration; never change APP_KEY casually.
- Do not display decrypted national identifier to department managers or
  ordinary employees, even if they can see basic name/position.
- Do not put PII in hr_audit_events.context. Audit action includes only
  employee UUID, section and revision.
- Separate permission scopes apply per company; roles never union.
- Use PostgreSQL and SQLite CI, plus authenticated browser tests before
  release. Stage write flags under HTTPS/CSRF only after QA review.
- Data retention, sensitive-fields access review and privacy policy must
  be approved by HR/legal before collecting real identifiers.
