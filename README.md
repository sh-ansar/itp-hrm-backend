# ITP HRM — Backend

Separate Laravel 12 API for the ITP HRM platform. PostgreSQL is the canonical database; Redis will be used for auxiliary queues and caching. Vue 3 frontend lives in [itp-hrm-frontend](https://github.com/sh-ansar/itp-hrm-frontend).

## Status

Foundation and read-only company-scoped employee listing are implemented. Preliminary audited role management is available behind a **default-OFF** feature flag. This application is **not ready for production HR data**: final roles, user login/CSRF, employee writes, document security, audit retention, backup/restore and migration procedures are outstanding.

## Development

1. Install PHP 8.3+ and Composer; `composer install`.
2. Copy `.env.example` to `.env`, configure an isolated database, and generate `APP_KEY` using `php artisan key:generate`. Never commit `.env`.
3. Run `php artisan test` (SQLite in-memory test configuration).
4. PostgreSQL 16 integration checks run automatically in GitHub Actions using an ephemeral database service.
5. Do not enable `HRM_ROLE_MANAGEMENT_ENABLED` until the business access matrix and session/CSRF deployment topology have been approved.

See [SPEC](docs/SPEC.md), [checklist](docs/CHECKLIST.md), [access model](docs/ACCESS-MODEL.md), [security](docs/SECURITY.md) and [development workflow](AGENTS.md).

No real employee data or credentials belong in this repository.
