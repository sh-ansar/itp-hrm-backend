# Ansar: update isolated HRM demo to revision-checked personnel editing

Status: **NOT EXECUTED**. On 12 Oct 2026 Ansar remote device was offline when local sync was attempted. Do not claim the local editor is installed until these checks pass. GitHub backend merge: `c367b7d`, frontend merge: `755b3df`.

## Safety prerequisites

- Use only the authorized **Ansar** PC. Never use WIN-TMDJDQ8MEPD or another production/reserve device for HRM demonstration changes.
- Backend path: `C:\Users\Admin\source\itp-hrm\backend`. Frontend: sibling `frontend`.
- Confirm `APP_ENV=local`, `DB_CONNECTION=sqlite`, `DB_DATABASE=.../database/hrm-demo.sqlite`, `HRM_ROLE_MANAGEMENT_ENABLED=false`. Do not display or transmit the local APP_KEY/password.
- Confirm frontend and backend server bind **127.0.0.1** only; leave `HRM_PERSONNEL_WRITES_ENABLED=false` unless actively testing with the synthetic demo account.
- Local demo database holds **fictional data**; do not mix real employee details. Existing counts before upgrade: 80 staff / 35 experience / 35 training / 12 reserve.

## Upgrade after reconnecting

1. Verify no uncommitted tracked source changes: run `git status --short` in both repos, inspect any changes; never discard them automatically.
2. Refresh exact reviewed `main` via `git pull --ff-only origin main` in both repos. Confirm backend includes `2026_10_12_000004_add_profile_record_revision.php` and frontend includes the new edit controls.
3. **Before migration**, pause demo writes and make a dated backup copy of `backend/database/hrm-demo.sqlite` outside the Git repository. Ensure the SQLite connection is idle while copying. Check that the copy can be opened.
4. Run `php -c php-hrm.local.ini artisan migrate:status` to confirm DB target and pending migration; then `php -c php-hrm.local.ini artisan migrate --force`. Verify revision migration appears as **Ran**, and existing record counts remain unchanged.
5. Run targeted local tests with the same PHP ini: `php -c php-hrm.local.ini vendor\bin\phpunit --filter EmployeeProfileUpdateTest`. Run frontend `npm run build`; neither step should change demo data.
6. Run with writes still OFF: confirm login works, read-only card loads, edit buttons are absent, and PUT returns 404 for an authenticated HR user.
7. **Optional controlled demo only:** if the local DB, synthetic account and loopback-bound servers are confirmed, set **only the local, ignored `.env`** `HRM_PERSONNEL_WRITES_ENABLED=true`, clear Laravel config cache and restart the local backend. Confirm the UI shows edit actions and try one synthetic record: read revision=1, edit to revision=2, stale revision=1 PUT returns 409, audit event created.
8. Restore write flag to **false** after demo if not needed. Do not commit `.env`, `.env.local`, database files, user credentials, SQLite backups or synthetic scripts. Mark LOCAL-03 complete only with observed HTTP/browser results.

## Rollback

Keep the pre-migration SQLite backup. A tested code rollback must be coordinated with its database schema; don't blindly run `migrate:rollback` while the new frontend and backend are running. If a test modifies fictional data, restore only the isolated demo database from the validated backup after stopping local processes.

## Production

No deployment, permanent enablement of writes, user onboarding or approval of the provisional HR role matrix is authorized by this local procedure. Staging HTTPS, dedicated PostgreSQL, backed-up restore and full form parity remain OPEN.
