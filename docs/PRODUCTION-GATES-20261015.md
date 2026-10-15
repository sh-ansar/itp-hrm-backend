# HRM production checkpoint — 15 October 2026

This is a handover/acceptance checklist, **not** a deployment script or a statement that HRM is production-ready.

## Live status observed on WIN-487ME2E68DR

| Layer | Confirmed state | Release gate |
| --- | --- | --- |
| Public frontend | `https://platform.itpmining.kz/hrm/` serves Vue, including deep links, ITP mark and favicon; Git frontend `81e36955165052f2087041f931d8e2dabe6c70f0` | Static-only OK |
| Frontend rollback | Previous HRM directory retained privately under `C:\deploy\hrm\deployment-state\backups\hrm-before-81e3695`; version/hash manifest `hrm-brand-81e3695.json` | Preserve |
| Apache | Existing platform site and Unityre served normally; no global Apache config edit or service restart in HRM frontend rollout | Do not alter shared `/api` or Unityre routes |
| Backend | Laravel 12 at Git backend main `7b659cfabcf5b7eacf3baa7af9b0290229a2157e` with locked dependencies staged outside webroot; isolated PHP 8.4.26 available | **Not running as public API** |
| API route | `/hrm-api/api/v1/me` currently responds with **HTML from an existing site**, not HRM JSON | **Do not treat as live authentication** |
| PostgreSQL | Fresh separate PostgreSQL 16 cluster staged in `C:\deploy\hrm\postgres-isolated\data` on temporary loopback port `55432`; log shows a test startup and clean shutdown; **no dedicated HRM service running** | Dedicated service identity, roles, HRM database/schema, backup and restore validation |
| Existing databases | Other PostgreSQL listeners/service(s), including legacy recovery, remain in use by unrelated projects | Do not stop, restart, reuse, or migrate these |
| MinIO | Laravel private-document support exists in code; no validated production HRM private bucket/credentials or end-to-end network tests recorded | Block real file uploads |
| SMTP and invitations | No enabled HRM SMTP sender, queue worker or accepted onboarding release on this deployment | Block welcome emails and invitation activation |
| Companies | Portal source records found: ITP Mining, ITP Georgia and **ITP Solution**. Requested HRM display name is **ITP Solutions** | Copy read-only metadata into new HRM DB with an external-ID mapping; no shared portal DB writes |
| First administrator | Real HRM account and company grants have **not** been provisioned in an isolated live DB | Create via controlled interactive setup or verified one-time activation; do not place user-provided passwords in files, commits or logs |

## Ordered go-live gates

1. **Data boundary:** finish configuration of the existing new HRM-only PostgreSQL cluster. Secure a dedicated, least-privilege service identity; keep listener on loopback and a free dedicated port. Provision `hrm_db` and restricted `hrm_app` login with separate `hrm_migrator`, `hrm` schema and permissions. Ensure audited off-host encrypted backups plus tested restoration. Do not run `migrate:fresh` against any existing data.
2. **Laravel service:** enable a dedicated managed PHP >=8.4.1 runtime outside `htdocs` on loopback, per-service `.env`, `APP_DEBUG=false`, secure cookie and cache configuration, private logs, health and read-only smoke routes. All write feature flags should remain off initially.
3. **MinIO:** private bucket with no anonymous policy, independent credentials and TLS. Verify Laravel authorization on every download for employee, company and document; denial after role revocation; audit and storage-outage behavior. Plan separate protection for sensitive identity, medical and family evidence beyond PDF documents.
4. **Onboarding and SMTP:** implement/merge and test idempotent, transactional employee activation after a legally effective assignment. Future-start and reserve employees must **not** receive account mail until eligible. Use one-time expiring activation, no plaintext password emails, separate SMTP identity, asynchronous queue/outbox, retries and audit. Prevent dispatch after termination or revocation. Test sender configuration with synthetic recipients before real employee emails.
5. **Initial companies/roles:** import only the three confirmed companies into HRM's own database, link to portal external IDs to prevent duplicates. Bootstrap one company administrator with controlled secret handling; verify ITP Mining, ITP Georgia and ITP Solutions appear in authorized company switcher. Ordinary employee, HR and department-manager roles must expose only permitted data.
6. **Route and cookie isolation:** add an Apache path-scoped `/hrm-api/` proxy to HRM's **separate** loopback Laravel service after testing, without touching `/api/`, `/unityre/` or `/unityre-api/`. The platform's global `X-Forwarded-Prefix: /unityre-api` must not leak into HRM proxy requests. Use unique `hrm_session` and `HRM-XSRF-TOKEN`, host-only secure cookie policy and explicit Sanctum stateful host configuration.
7. **Browser and business acceptance:** run real Laravel+Vue E2E against staging and then the public host: 200/401/403/419, login/logout, company isolation, access revocation, HR write controls, read-only user UI, downloads, 4 locales (RU/KZ/EN/GE), light/dark, mobile/tablet, registry filters, employees/personnel records, organization, staffing, holidays, leave approvals, protected maternity/medical workflows and bonus approvals. Audit vs original prototypes; CI success alone is **not** proof of full 1:1 parity.
8. **Go/no-go:** enable real personnel writes, SMTP and document transfers **only** after acceptance, restore test and data-protection review. Recheck platform and Unityre status codes, service status, Apache hashes and performance. Keep rollback separate: roll back new HRM route/service/release only, never other services or PostgreSQL clusters.

## Deployment rules

- Git commits and CI before release, pin the exact frontend/backend SHA; don't deploy dirty worktrees.
- Static Vue only under the HRM web folder; backend, SQL cluster, uploads and secrets stay outside the webroot.
- Archive pre/post checksums and HRM-only backup privately. Current static rollback does not require an Apache restart.
- Do not claim that an available login form means the API works: as of this checkpoint the HRM API is *not* published.
- Never publish or hard-code user passwords, SMTP secrets, database passwords, private MinIO keys or invitation tokens.
- A future maintainer must verify the current machine state again before following this document; ports and running processes may have changed.

## Source pointers

- Frontend: `sh-ansar/itp-hrm-frontend` main, `deploy/README-HTDOCS.md`, `docs/BRAND-ASSETS.md`.
- Backend: `sh-ansar/itp-hrm-backend` main, `docs/ISOLATED-HOSTING-WINDOWS.md`, `docs/PRIVATE-MINIO-DOCUMENTS.md`.
- HRM document authorization foundation: backend PR #78; protected leave categories: backend PR #77 and frontend PR #129.
- Original user-provided designs/ZIP assets remain the UI acceptance reference, rather than this checklist.
