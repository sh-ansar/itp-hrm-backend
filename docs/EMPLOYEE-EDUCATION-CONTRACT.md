# B74 — formal employee education / qualifications

## Why this is a separate domain record
In the source HRM employee card's development tab, "Образование и
квалификация" lists formal schooling, institution, specialization, dates
and supporting documents. This differs from individual `employee_training`
courses (type, mode, topic, provider, training dates). **Never merge
formal degrees and courses into one training table.**

## Implemented schema and contract
- New additive `employee_education_records` with UUID primary key,
  company_id/employee_id composite FK, education_type, organization,
  started_on, ended_on, specialization, document_reference,
  revision, archived_at and timestamps. Multi-row per employee.
- Optional dates remain null when not known; do not substitute January 1
  or current date for legacy unknown dates. If both provided, end >= start.
- document_reference is **metadata**. It does not mean the certificate was
  uploaded, verified or signed; use a separate secure attachment subsystem.
- GET company employee detail (HR/company_admin only) returns
  `private_profile.education[]` (up to 100 current rows) with
  `meta.private_education_truncated`; normal managers do not receive this.
- POST `/api/v1/companies/{company}/employees/{employee}/education`
  submits all six fields, returns id+revision 1.
- PUT `/.../education/{record}` submits all fields and expected revision;
  409 on stale update.
- DELETE `/.../education/{record}` with `{revision}` archives the row;
  404 for already archived records.
- Authorization delegates to existing `EmployeePrivateWriteAuthorization`:
  role HR/company_admin AND company/employee scope AND the default-OFF
  HRM_PERSONNEL_WRITES_ENABLED feature flag.

## Transaction and audit
Each mutation locks the employee parent, checks record version and company,
writes immutable-ish audit metadata (employee UUID, education UUID, revision,
operation) in the same DB transaction. The audit must not contain
institution, student name, specialization or document reference.

## Acceptance and next module
Backend PHPUnit tests on SQLite/PostgreSQL 16: creation, multiple records,
nullable historical dates, invalid end date, cross-company and manager denial,
stale revision, no destructive deletion, audit without private values.

Frontend F125 must reuse BaseDrawer and the existing private-record management
component rather than inventing a second list/editor; display real education
records in the existing "Образование и квалификация" table, with read-only
mode if the backend denies writes. All UI copy must support RU/KZ/EN/GE.

Not covered: education recognition/accreditation, degrees equivalence across
countries, scanned diplomas, identity verification, legal retention and
non-HR employee self-service. Business/HR owners must specify these later.
