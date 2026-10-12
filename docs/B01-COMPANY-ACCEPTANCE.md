# B01 — Company hierarchy: implementation and acceptance contract

Status: planned; not implemented or approved. Source: `docs/SPEC.md` FR-02, NFR-01/02/05 and `docs/ACCESS-MODEL.md`.

## Boundaries and implementation sequence
1. Inspect existing company models, memberships, scopes, policies and migrations before modifying schemas. Preserve UUID company IDs and existing membership APIs.
2. Introduce company listing/detail read APIs only after adding explicit authentication, active-account guard, and company-scoped authorization. Avoid tenant-global enumeration and sensitive personnel data in responses.
3. Introduce company creation/update only after the business-approved admin role matrix. Deny by default and use explicit feature gates for privileged operations.
4. Hierarchy parent-child relationships must reject self-parent and cycles, prohibit cross-tenant parent references, and prevent invalid deletion/reparenting; check the full ancestor chain within a transaction.
5. Validate names, uniqueness scope, maximum hierarchy depth and archival semantics with stakeholders rather than inventing business rules; no destructive implicit cascades.
6. Audit successful sensitive writes without logging credentials, employee data or raw submitted payloads; include a safe correlation ID.

## Mandatory tests before marking B01 complete
- SQLite **and** PostgreSQL: authenticated in-scope reads and protected writes, unauthorized and unscoped access, no company enumeration, tenant isolation, UUID validation, self-parent/cycle failures, parent changes, and transaction rollback.
- Pagination and bounded queries for listing; stable machine error codes and trace IDs on failures.
- Vue presentation aligned to `docs/DESIGN-SYSTEM.md`: shared table/form/modal states; responsive Chromium 375/768/1440; loading, empty and error states; no synthetic data displayed as real.
- Link the exact backend/frontend PR SHAs and successful CI to `docs/CHECKLIST.md`. Partial scaffolding does **not** close B01.

## Open approvals / dependencies
Business-approved organization identifiers, parent relations across legal entities, admin authorization matrix, mandatory fields, archival/deletion policy, and specific printable forms from `Кадры 2026`. These requirements must be confirmed before write APIs or migrations are accepted. No production rollout.
