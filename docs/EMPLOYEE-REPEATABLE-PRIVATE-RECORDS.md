# Employee family and identity records – B72 architecture and handoff

## Source screen and intent
Source archive Zип.zip:
- Employee cards / Личные данные 1.png: identity documents, issuer, issue/expiry dates.
- Employee cards / Личные данные 2.png: multiple relatives and their dates.
The source is ONLY a UI prototype, never historical HR data.

## Normalized PostgreSQL schema (additive migration)
- employee_family_members: id PK, employee_id + company_id composite FK,
  relationship, full_name, birth_date, document_reference, revision,
  archived_at, created_at, updated_at. One row per relative.
- employee_identity_documents: id PK, employee_id + company_id composite FK,
  document type, number_ciphertext, personal_number_ciphertext,
  country_of_issue, issued_by, issued_on, expires_on, is_primary, revision,
  archived_at, timestamps. One row per document version.
- Existing employee private profile tables and employees remain untouched.
- Encrypt document identifiers with Laravel Crypt and rotate APP_KEY only through
  a planned re-encryption migration.
- Non-destructive archive, no hard deletion of submitted HR historical details.

## Permissions and business invariants
- Only company_admin/hr_specialist with HRM_PERSONNEL_WRITES_ENABLED=true
  may create, edit or archive. Others get 403 (or 404 if disabled).
- GET detail includes records only for these HR roles; the manager and ordinary
  employee receive NO private_profile property. All queries tenant scoped.
- Mutation serializes on the parent employee row, checks the record's revision,
  archives rather than deletes, and writes safe audit in the same transaction.
- If a document becomes primary, older primary records are demoted and their
  revisions incremented, avoiding stale overwrite. Only one primary selected
  by the application transaction; this requires a separate DB-level invariant
  before importing raw records from external systems.
- Missing or unknown fields fail validation, document expiry must not precede
  issuance. Never log a family member name or document number in audit context.
- Folders and filenames in the screenshots are NOT uploaded document binaries.
  document_reference is external metadata, and private attachments must be
  linked to the existing authorized document subsystem with independent
  virus scanning, access checks, retention and download authorization.

## API contract
POST /companies/{company}/employees/{employee}/private-records/family
  { relationship, full_name, birth_date:null|YYYY-MM-DD,
    document_reference:null|string } -> HTTP 201 { data:{id,revision:1} }
PUT /companies/{company}/employees/{employee}/private-records/family/{record}
  same fields + revision:int -> HTTP 200 with next revision
DELETE /companies/{company}/employees/{employee}/private-records/family/{record}
  { revision:int } -> soft archive, next revision

POST/PUT/DELETE with section=identity likewise:
  { type, number, personal_number:null|string,
    country_of_issue:null|string, issued_by:null|string,
    issued_on:null|YYYY-MM-DD, expires_on:null|YYYY-MM-DD,
    is_primary:boolean, [revision on PUT/DELETE] }
  Sensitive numbers only decrypted in HR-only GET/detail.

Existing GET employee detail adds:
  private_profile.family[] (visible non-archived, up to 100),
  private_profile.identity_documents[] (primary first, up to 100),
  meta.private_family_truncated and meta.private_identities_truncated.
A full paginated private-records endpoint is required for organizations
exceeding 100 entries; do not misrepresent 100 as the full history.

## Acceptance and next handoff
- SQLite and PostgreSQL tests for encryption, date constraints, cross-company
  denial, optimistic conflict and archive retention.
- Vue client F124 must use typed contracts and role flags, and reload on save.
- Avoid hardcoded nationalities/military/relationship dropdown dictionaries:
  use approved tenant/jurisdiction reference catalogs in a separate phase.
- HR/legal approval required for data minimization and collection of children/
  dependents before production activation. Do not use real family data in CI.
