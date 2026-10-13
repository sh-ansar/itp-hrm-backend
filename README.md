# ITP HRM — Backend

Separate Laravel 12 API for the ITP HRM platform. PostgreSQL is the canonical database; Redis will be used for auxiliary queues and caching. Vue 3 frontend lives in [itp-hrm-frontend](https://github.com/sh-ansar/itp-hrm-frontend).

## Status

Foundation and read-only company-scoped employee listing are implemented. Preliminary audited role management and first-party browser session authentication are available behind **separate default-OFF** feature flags. This application is **not ready for production HR data**: final roles, user login/CSRF, employee writes, document security, audit retention, backup/restore and migration procedures are outstanding.

## Development

1. Install PHP 8.3+ and Composer; `composer install`.
2. Copy `.env.example` to `.env`, configure an isolated database, and generate `APP_KEY` using `php artisan key:generate`. Never commit `.env`.
3. Run `php artisan test` (SQLite in-memory test configuration).
4. Browser login endpoints remain disabled (`HRM_BROWSER_LOGIN_ENABLED=false`) until HTTPS, origin, CSRF and provisioning approval. See `docs/BROWSER-SESSION.md`.
5. PostgreSQL 16 integration checks run automatically in GitHub Actions using an ephemeral database service.
6. Do not enable `HRM_ROLE_MANAGEMENT_ENABLED` until the business access matrix and session/CSRF deployment topology have been approved.

See [SPEC](docs/SPEC.md), [checklist](docs/CHECKLIST.md), [access model](docs/ACCESS-MODEL.md), [security](docs/SECURITY.md) and [development workflow](AGENTS.md).

No real employee data or credentials belong in this repository.

## Employee photos

Photos use the existing personnel write gate (off by default) and HR/company-admin scope. Only JPEG/PNG up to 5 MiB are accepted, with a 4096 px / 8 megapixel bound. Images are decoded, EXIF orientation is normalized, and stored as private JPEGs up to 1024 px. Original EXIF, names and uploaded bytes are discarded. The authenticated photo response is private/no-store; list/detail payloads never reveal storage paths. Replacement/removal increment the employee revision and write immutable audit events. PHP requires GD and EXIF; deployment upload_max_filesize must allow 5M and post_max_size at least 6M. The local/public storage link does not expose the private photo directory.
