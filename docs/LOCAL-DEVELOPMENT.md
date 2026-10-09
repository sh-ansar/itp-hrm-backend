# Local development (Windows)

Projects: `C:\dev\itp-hrm\frontend` and `C:\dev\itp-hrm\backend`.

## PHP

This workstation has PHP extensions available but disabled in the global `php.ini`. A project-only `php.local.ini` was prepared by copying the existing PHP configuration and enabling `fileinfo`, `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`. This file is intentionally ignored by Git.

In PowerShell, set `$env:PHPRC = 'C:\dev\itp-hrm\backend\php.local.ini'` before Composer, Artisan or PHPUnit commands. This ensures subprocesses use the same extension configuration.

## Checks

- Backend: `php artisan test` (SQLite in-memory isolated tests)
- API routes: `php artisan route:list --path=api`
- Frontend: `npm run build`

PostgreSQL is required for the production data store. SQLite is exclusively for fast unit/integration tests; PostgreSQL-specific behavior needs dedicated PostgreSQL integration tests.

Do not reuse existing databases or modify running PostgreSQL services without inventory and an approved project-specific instance/database. Docker Desktop engine was not running when checked.

## Data

Do not use real employee data in local seeds. Never publish local `.env`, credentials or HR exports.
