# Protected leave: supporting evidence, privacy and rollout

Implemented in backend PR #105 with the matching Vue interface in frontend PR #159.

## Scope and responsibilities

The existing leave workflow supports `medical` (temporary incapacity), `maternity` and `childcare` leave types. These are **protected absences**, not a deduction from annual ordinary leave grants. Their duration, eligibility, payment, extension, state benefit and statutory forms are deliberately **not inferred** by this feature: those require the applicable Kazakhstan/Georgia/company policy and legal sign-off.

A single supporting PDF may be attached to each leave request. The document remains private in the configured MinIO disk. The application stores only an opaque server key, byte count, SHA-256, review state, revision, and authorized actor IDs. The original upload filename, diagnosis and confidential content are excluded from API list views and audit context.

## Roles and states

- **Employee:** may attach/read PDF only for a leave request they authored and whose employee record is explicitly linked to their active company account. They cannot review or view anyone else's evidence.
- **Company administrator / HR specialist:** may attach/read/review documents for their company. Evidence review for an employee record linked to the reviewer's own user is forbidden.
- **Department manager:** no HR-wide evidence access; their own explicitly linked authored request remains accessible.
- **Other employee, unlinked user, inactive company membership, other tenant:** inaccessible. Use HTTP 404 to avoid exposing document existence.
- **Attachment review:** `pending` → `verified` or `rejected`; rejection requires a reason. A rejected document can be replaced with a new file; its revision changes. Previously verified evidence cannot be silently replaced.
- **Leave approval:** existing four-eyes guard remains. With mandatory evidence enabled, a protected leave request cannot be approved without `verified` status. Ordinary leave balances are unaffected. Changing dates or leave category after attaching evidence is prohibited; cancel and create a new request when a new basis is required.

Review state means an **authorized HR document check**. It does not mean an electronic signature, a physician's diagnosis, legal eligibility determination, or statutory payroll authorization.

## API (all under /api/v1/companies/{company})

| Action | Route |
| --- | --- |
| Upload / replace rejected PDF | `POST /leave-requests/{leaveRequest}/evidence/file` (multipart `file` + `request_revision`) |
| Download authenticated PDF | `GET /leave-requests/{leaveRequest}/evidence/file` |
| HR check / reject | `POST /leave-requests/{leaveRequest}/evidence/review` (`revision`, `decision`, optional `comment`) |

Existing `GET /leave-requests` (HR) and `GET /me/workspace` (self) return only `evidence_status` and `evidence_revision`, never storage paths. Downloads enforce authorization on **every request**, send `no-store`, `nosniff`, and `attachment`, and log an audited event without sensitive content.

## Feature flags and production gates

- `HRM_LEAVE_WRITES_ENABLED`: existing leave workflow, default OFF.
- `HRM_PROTECTED_LEAVE_EVIDENCE_ENABLED`: enable private upload and HR review, default **OFF**.
- `HRM_PROTECTED_LEAVE_EVIDENCE_REQUIRED`: require review at protected leave approval, default **OFF**. Do not activate until every in-flight protected request has a verified attachment or an approved transition plan.

**Before production enablement:** independently verify encrypted private MinIO bucket/policy, retention/disposal schedule for sensitive data, backup/restore, antivirus/content screening, HTTPS and cookie scope, correct employee account linking, HR permission matrix, legal document requirements, and audit access. Current upload validation enforces PDF MIME, header and 5 MiB size but **does not perform malware scanning or authenticate the issuer**. Do not claim a clinically validated document or a legally compliant payroll process on this basis alone.

No real medical information belongs in CI fixtures; all tests use synthetic PDF bytes and isolated MinIO. PHPUnit suite `ProtectedLeaveEvidenceTest` covers tenant isolation, private reads, review, rejection, replacement, stale revision, and optional activation. Browser scenario lives in frontend `tests/e2e/protected-leave-evidence.test.mjs`.

## Deferred policy decisions

- Country/company-specific paid leave and state benefit integrations
- Electronic signature and qualified document issuance
- Medical certificate authenticity/validation, issue/extension dates and sensitive diagnosis fields
- Explicit deletion/retention policy for replaced evidence
- Managers' generalized coverage/absence views without any medical document or diagnosis exposure
