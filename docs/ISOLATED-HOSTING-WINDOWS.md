# HRM: isolated deployment alongside existing Windows/Apache applications

This is a PREPARATION document. No Windows service, website, DNS, database, MinIO bucket, or production feature flag is changed by this repository.

## Non-negotiable boundaries

- Keep other Apache virtual hosts, their `/api` aliases, PHP versions, PostgreSQL instances and webroots unchanged.
- The backend requires PHP >= 8.4.1; do **not** try to run it in an existing global Apache mod_php 8.2 process. Use a dedicated supported PHP 8.4 HTTP runtime on loopback or in an isolated VM/container, managed by its own service account.
- The existing host-level PostgreSQL port 5432 may already serve unrelated applications or recovery tasks. Do not reuse or restart that service. Provision a *different* PostgreSQL 16 data directory, service and loopback listener, e.g. port 55432, after operator approval.
- Publish only the built Vue `dist` directory. Laravel source, `.env`, `storage`, `vendor`, uploads, exports, database data, backups and MinIO credentials must stay **outside** every Apache DocumentRoot.
- Do not copy real HR records or seed demo accounts into a production database.

## Chosen route: existing platform host, separate HRM paths

The frontend will be deployed **exactly like Unityre**, as static Vue files in
`C:/xampp/htdocs/hrm/dist/` with an isolated Apache `Alias /hrm/` under the
existing `platform.itpmining.kz` HTTPS VirtualHost. No new frontend HTTP server
is needed. Vue is built with base `/hrm/` and all deep links reload through its
own SPA fallback. Separate API requests use `/hrm-api/`, never the existing
platform `/api/` or Unityre `/unityre-api/`.

See the frontend repository's `deploy/README-HTDOCS.md` and
`deploy/platform-hrm-alias.conf.example`. The frontend-only route can be
prepared independently. **Do not enable real HRM login or staff data access**
until the distinct Laravel PHP 8.4+ runtime, PostgreSQL user/database and
private MinIO are tested. The same hostname means HRM also needs a unique
session cookie and an independently named XSRF cookie so other Laravel apps
cannot overwrite its security tokens. Current platform Apache configuration
sets `X-Forwarded-Prefix: /unityre-api` globally; HRM must override it for
`/hrm-api/` only, without changing Unityre. These are release blockers, not
optional cosmetic improvements.

## Alternative topology: separate hostname

Example hostname `hrm.example.org` (final DNS/TLS certificate subject to approval):

```text
https://hrm.example.org/
   -> existing Apache, NEW name-based HTTPS vhost
   -> dedicated Vue dist-only DocumentRoot (htdocs/hrm-web)
   -> /api/, /auth/, /sanctum/ reverse proxy to 127.0.0.1:18080
                                      | dedicated managed PHP >= 8.4.1 Laravel runtime
                                      | new PostgreSQL-16 instance on 127.0.0.1:55432
                                      | hrm_db / hrm schema / restricted hrm_app user
                                      | private MinIO bucket over TLS, server-side only
```

A dedicated hostname remains a fallback if secure session/CSRF isolation cannot be demonstrated on the shared platform host. It is not the currently selected rollout route. Frontend subpath build and browser tests are maintained independently in the frontend repo.

The Apache example in `deploy/examples/hrm-vhost.conf.example` is only a template. Add it to a new, separately reviewed include when a dedicated certificate, hostname and backend service are ready; never paste it blindly into a live vhost or restart Apache before `httpd -t`, baseline config backup, staging tests and rollback plan.

## PostgreSQL isolation

Create a NEW cluster/data directory with its own PostgreSQL Windows service (and its own restricted OS identity) and bind to `127.0.0.1:55432` only. Require SCRAM authentication; block remote PostgreSQL ingress at the firewall. In that fresh cluster only:

- `hrm_db` database, separate from every other project.
- `hrm` SQL schema, with no `CREATE` privileges granted to PUBLIC.
- `hrm_owner`: NOLOGIN ownership only.
- `hrm_migrator`: schema DDL for controlled releases; not available to the live app.
- `hrm_app`: LOGIN runtime DML only; no SUPERUSER, CREATEDB, CREATEROLE, schema DDL or cross-database grants.

Follow the **illustrative** fresh-instance SQL in `deploy/examples/postgres-bootstrap.sql.example`. Supply independent passwords interactively using `psql \\password` or a secrets manager, not committed files or command-line flags. Set `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55432`, `DB_DATABASE=hrm_db`, `DB_SCHEMA=hrm` and `DB_USERNAME=hrm_app` for runtime. Use `hrm_migrator` only for release migration jobs, never for request handling. `DB_SCHEMA` defaults to `public` for backwards compatibility.

Never run `migrate:fresh`, seeders, `db:wipe` or manual SQL against the shared or production database. Require a fresh-db migration and rollback rehearsal with **synthetic** data first. Database backups and point-in-time restore must be verified before real HR data is allowed.

## Application/session isolation

- Build Vue from the reviewed Git SHA, with production demo mode disabled. `npm run build`, no live API keys in the browser bundle.
- For the selected platform-host rollout, reverse-proxy `/hrm-api/` to the private Laravel loopback service. Vue calls `/hrm-api/api/v1`, `/hrm-api/auth/`, `/hrm-api/sanctum/`; do not rewrite or share the existing platform's `/api`, `/auth` or Unityre aliases.
- Enable HTTPS, strict dedicated CORS/stateful host allowlist, `SESSION_SECURE_COOKIE=true`, HttpOnly, SameSite and host-only cookies; no wildcard domain and no user tokens in localStorage.
- Laravel `APP_DEBUG=false`, `APP_ENV=production`, unique `APP_KEY`, logs and file permissions isolated by OS account. Prefer `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database` using **only the new hrm_db/hrm schema** (the required cache/jobs/sessions migrations already exist); do not attach HRM to any existing shared Redis service without a separate reviewed namespace/credentials.
- Role grants, CSRF, current-session revocation, guest denial, tenant isolation and all four locale/theme screens must pass against the SAME deployed SHA.
- A dedicated MinIO private bucket is *not* an identity/authorization boundary. Laravel checks user/company/document on every request and audits downloads. Do not enable document writes or migrate local files until actual private-MinIO network tests and policy review.

## Staged release gates (must complete in order)

1. Record existing Apache config checksum, port/process/service inventory and tested restore procedure; take configuration-only backup **without** accessing another app's data.
2. Provision separate folder, service identity, new loopback PHP 8.4 backend runtime, isolated PostgreSQL cluster and private MinIO bucket; verify no port or hostname overlaps.
3. Deploy versioned code to private release directory, a dist-only frontend to a new empty webroot, and per-service secrets outside Git and the webroot.
4. Create fresh HRM DB with restricted roles, run migrations through migrator account, inject synthetic-only fixtures and test read/write/denied cases.
5. Test `/hrm/` plus `/hrm-api/` on a staging hostname: login, 401/403/419, session revoke, cookie coexistence with platform/Unityre, Vue fallback, responsive 375/768/1280/1440, RU/KZ/EN/GE, all workflows, MinIO private download and storage outage.
6. Run Apache configuration test; enable **only** new HRM Alias and proxy rules in the existing platform HTTPS VirtualHost; smoke-test pre-existing public routes and check their status codes after change. Observe CPU/memory/ports/logs.
7. Snapshot and tag a version; if any regression, remove **only** the newly added HRM Alias/proxy rules and stop the private HRM backend service; restore its previous release. No other service restart or database rollback.
8. Only after owner acceptance, security review, off-host restore-tested backups and audit retention should real personnel accounts/writes be enabled.

## Read-only readiness check

`powershell -NoProfile -ExecutionPolicy Bypass -File deploy/scripts/Test-HrmIsolation.ps1`

The check only inspects local paths, listener ports, PHP requirement and named services; it does not create folders, modify firewall, register services, touch databases or change Apache.

## Explicit not-yet-done items

Live PHP 8.4 HTTP runtime provisioning, isolated PostgreSQL cluster creation, DNS/TLS certificate, standalone private MinIO connectivity, administrator account lifecycle review, per-document ACL enhancements, migrations/backup restore rehearsal, complete production browser E2E. Until these gates pass, this plan does **not** signify production readiness.
