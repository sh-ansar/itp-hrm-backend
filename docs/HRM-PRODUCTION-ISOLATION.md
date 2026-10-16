# HRM production deployment and isolation

Updated 2026-10-16. This guide describes the dedicated HRM runtime, not the
platform or Unityre application deployments.

## Boundaries

| Component | Location / endpoint |
| --- | --- |
| Frontend | `https://platform.itpmining.kz/hrm/` |
| HRM static files | `C:\deploy\hrm\site\current` |
| API | `https://platform.itpmining.kz/hrm-api/` |
| HRM-only upstream | `127.0.0.1:18080`, separate Apache |
| HRM PHP | PHP 8.4 FastCGI at `127.0.0.1:18082` |
| PostgreSQL | `127.0.0.1:55432`, DB and schema `hrm` |
| Windows services | `ITP-HRM-HTTP`, `ITP-HRM-FastCGI`, `ITP-HRM-PostgreSQL16` |

The primary Apache TLS virtual host includes
`C:\deploy\hrm\httpd-isolated\platform-hrm-routing.conf`.
The HTTP virtual host includes an HRM-path-only HTTPS redirect.
These directives were inserted once into the existing Apache vhost, with
byte-preserving backups under
`C:\deploy\hrm\deployment-state\backups`.

**Never deploy HRM by placing it into `platform/dist/hrm`.** The platform's
own frontend release process replaces its entire `dist` folder, deleting
unknown subdirectories. The independent Apache alias avoids recurrence of that
incident. Likewise never register `/hrm-api/` using a dist-folder
`.htaccess`; it will disappear during the next platform build.

## Safe updates

1. Merge reviewed changes into the corresponding GitHub `main` only after
   unit tests, TypeScript and Chromium tests have passed.
2. Backend: fetch the exact approved commit in its own HRM checkout, validate
   `.env` access control and dependencies. Run Laravel migrations separately
   with `hrm_migrator`, never with `hrm_app`.
3. Frontend: build using `npm run build:htdocs` from the approved commit,
   verify `VITE_APP_BASE_PATH=/hrm/` and `VITE_API_BASE_URL=/hrm-api/api/v1`.
   Copy into a staging folder next to `site/current` and preserve the private
   `.htaccess`; verify hashes, then atomically swap directories and keep
   the previous site for rollback. Do not touch platform index/assets.
4. Verify public HRM JSON health, denied unauthenticated API, HTML deep links,
   SVG MIME types, HTTPS redirects and absence of access to `.env`.
5. Verify platform and Unityre before and after using **newly captured,
   contemporaneous** hashes. Prior baselines may legitimately become stale
   following unrelated, authorized platform deployments.
6. Roll back only the HRM Git commit/static site if there is a regression. A
   change to Apache routing must use the archived vhost and syntax testing.

## Operational gates

- `HRM_BROWSER_LOGIN_ENABLED` must remain off until the initial account can
  use a strong one-time secret and an audited password-change workflow.
- HRM personnel, leave, bonus, worktime, documents, and role writes are
  independent feature flags. Never enable all at once.
- Company mappings contain three portal IDs in isolated HRM tables. Portal DB
  and user accounts were not modified by HRM bootstrap.
- Passwords and cloud storage credentials must never be committed, printed in
  logs or embedded in CLI arguments. Initial admin credential is held encrypted
  using Windows DPAPI until secure handoff.
- MinIO and SMTP are separate integration gates: private bucket, tenant-aware
  authorization, secure invite workflow and background jobs must be verified
  before sensitive documents or onboarding messages are sent.

## Minimum smoke tests

```
GET /hrm/                         -> 200 text/html HRM
GET /hrm/login                    -> 200 text/html HRM
GET /hrm-api/api/v1/health        -> 200 JSON service=itp-hrm-api
GET /hrm-api/api/v1/companies     -> 401 JSON when anonymous
GET /hrm-api/.env                 -> 403
HTTP /hrm/login                  -> 301 HTTPS
HTTP /hrm-api/api/v1/health      -> 301 HTTPS
GET /unityre/ and /              -> 200 unaffected
```

Deployment scripts and validation manifests are stored in the private
`C:\deploy\hrm\deployment-state` directory. Those are operational records,
not substitutes for reviewed, version-controlled application source.
