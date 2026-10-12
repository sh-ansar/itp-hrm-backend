# HRM local smoke test — Windows / Ansar

Status: local development only; verified on 2026-10-12. **Not** a production deployment, business acceptance, or completed B01/B02. Primary source remains the GitHub `main` branches.

## Location and constraints
- Frontend: `C:\Users\Admin\source\itp-hrm\frontend`
- Backend: `C:\Users\Admin\source\itp-hrm\backend`
- Services listen on **127.0.0.1 only**; never expose to the LAN or Internet until HTTPS, CSRF and access approval.
- Use a dedicated `database/hrm-local.sqlite` with **no real employees**. Keep `APP_DEBUG=false`, `HRM_BROWSER_LOGIN_ENABLED=false`, `HRM_ROLE_MANAGEMENT_ENABLED=false` by default. No seed of production identities or personal records.
- Never commit `.env`, `php-hrm.local.ini` or local databases.

## Setup — isolated commands
The machine has Node 22, PHP 8.4 and Composer. Windows PHP has the DLLs but may not enable fileinfo/SQLite by default. Use a project-local PHP INI (`php-hrm.local.ini` copied from the installed PHP config), adding the required `extension=php_fileinfo.dll`, `extension=php_pdo_sqlite.dll`, `extension=php_sqlite3.dll`. Pass it with `php -c php-hrm.local.ini`; do **not** change global PHP settings.

```powershell
# backend directory
php -c php-hrm.local.ini C:\ProgramData\ComposerSetup\bin\composer.phar install --no-interaction --no-progress
php -c php-hrm.local.ini artisan migrate --force
php -c php-hrm.local.ini vendor\bin\phpunit --filter OrganizationDirectoryTest
php -c php-hrm.local.ini artisan serve --host=127.0.0.1 --port=8000
```

On this machine npm 10/Windows can fail esbuild's install script with `ERR_INVALID_ARG_TYPE` and `EPERM` even while downloading packages. To repair the installation without disabling build verification:

```powershell
# frontend directory
npm ci --ignore-scripts --no-audit --no-fund
$env:ComSpec='C:\Windows\System32\cmd.exe'
npm rebuild esbuild --script-shell=C:\Windows\System32\cmd.exe
npm run build
node node_modules\vite\bin\vite.js --host 127.0.0.1 --port 5173
```

The PHP application must use a **separate** local database. Verify the local `.env` connection and generated application key before starting the server. Do not copy any CI synthetic identity into a normal persistent environment.

## Verified evidence (2026-10-12)
- Laravel `artisan route:list --path=api/v1` showed seven expected routes.
- PHPUnit `OrganizationDirectoryTest`: **4 tests, 18 assertions, pass** using the project-specific PHP INI.
- Frontend `npm run build`: success, 1625 modules transformed.
- Local `GET http://127.0.0.1:8000/api/v1/health`: HTTP 200.
- Local `GET http://127.0.0.1:5173/organization`: HTTP 200 for SPA entry; **this alone does not validate browser interactions or secure directory contents**.

## Remaining acceptance gates
- Verify real browser layout and route navigation against approved presentation/BNT design at 375, 768, 1440.
- Resolve and merge open organization hierarchy Chromium PR before claiming B02.
- Approve rights matrix and company/department write behavior, then implement cycle-safe atomic mutations.
- Verify staged/production topology (TLS, first-party cookies, CSRF, account provisioning), backup and restore; no production changes authorized.
