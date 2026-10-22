# Personnel document lifecycle — proposed contract (not implemented)

**Status: design proposal.** Existing API stores authorized private draft metadata/PDF and must not imply a document is signed, issued, or legally effective. Product/legal owner must confirm jurisdiction and qualified e-sign provider before development of final signing.

## Roles and invariants
- `employee`: view only explicitly shared own final/approved documents. Never browse company-wide drafts.
- `department_manager`: no general personnel-document list or private download by virtue of being a manager.
- `hr_specialist`: create/update draft, request internal review, inspect document's allowed history.
- `company_admin`: configure issuance policy and roles; must not forge employee/HR or cryptographic signature.
- All reads/writes scoped to company + record. Enforce authorization in Laravel, not only Vue.
- Every state transition is atomic, revision checked, with actor, purpose and timestamp in append-only audit; uploaded binaries immutable/versioned.

## Proposed state machine
```text
draft -> in_review -> approved_for_issue -> awaiting_signatures -> signed -> issued
           |               |                     |
           v               v                     v
      changes_requested  changes_requested      declined
```
Withdrawal/cancellation is a separate authorized auditable transition, not deletion; `superseded` links the previous final document to a new revision. Rejected review returns to a **new** draft revision. A signed/issued binary cannot be overwritten.

## Boundary of functionality
1. HR saves editable draft metadata and a private PDF/template rendition.
2. Server validates document kind, employee/company, numbering policy, effective date and missing required fields.
3. Reviewer approves a specific immutable file hash + revision, not an editable template.
4. On signing initiation, backend stores provider transaction ID, signer identities/roles and requested signature policy.
5. Provider callback is authenticated, idempotent and verified against expected signers, document hash and certificate/transaction metadata.
6. `signed` requires verified signatures over the exact immutable byte stream; `issued` requires independently authorized finalization if business policy requires it.
7. Final copy is stored privately with retention and audit, and only authorized recipients can download it.
8. Corrections use linked superseding record; no in-place mutation to executed file.

## Data and security
Suggested tables: `personnel_document_revisions` (document_id, revision, SHA-256, storage reference, state), `personnel_document_review_actions`, `personnel_document_signing_transactions` (provider, external_id, idempotency), `personnel_document_signers`, `personnel_document_deliveries`. Exact names to be decided with schema owner, avoiding duplicate existing domain models.
- Virus scanning and file type/size checks before accepting binary.
- Prevent SSRF from external provider callbacks; verify HMAC/certificate and replay nonce.
- Never put IIN, passwords, private file paths, document content or signed URLs into audit/log context.
- Encryption/key escrow, retention, legal hold and disaster recovery require jurisdiction-specific approval.

## Acceptance tests
- Draft never appears as issued; unauthorized role/tenant direct API GET and file download denied.
- Same initiator cannot perform prohibited review/signing roles.
- Stale revision, double action and duplicate callback are idempotent/denied correctly.
- Uploaded PDF hash cannot change after review/signing starts.
- Superseding keeps historical original downloadable only for authorized roles.
- Provider outage/retry and failed verification cannot mark document signed/issued.
- Restore drill confirms private object bytes match DB hashes.
- PDF and signatures verified in isolated staging with real supported provider sandbox, not mocked-only success.

## Decision required before production implementation
Confirm applicable legal regimes (Kazakhstan/Georgia), supported e-sign provider(s), signer categories, document types requiring signatures, numbering and retention policies, and whether a legally distinct approval/issuance authority is required. Until then, **keep only draft/attachment functionality enabled**.
