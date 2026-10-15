# Initial ITP companies and administrator bootstrap

This is a one-time bootstrap for a **dedicated HRM database**, not a portal database migration.

## Verified source records (read only, 2026-10-15)

| Portal ID | Portal name | HRM name |
| --- | --- | --- |
| 1 | ITP Mining | ITP Mining |
| 2 | ITP Georgia | ITP Georgia |
| 3 | ITP Solution | ITP Solutions |

Legal and physical addresses and business phone numbers are preserved in
`company_external_references`, linked by `source_system=itp_platform` and
`source_id`. **No portal users, personnel records or passwords are imported.**
The source portal DB was not modified.

## Explicit release procedure

1. Confirm the target is the newly isolated PostgreSQL 16 cluster listening
   **only** on `127.0.0.1:55432`, database `hrm`, schema `hrm`.
   Never use the platform's 5432 or Unityre's 55433.
2. Apply migrations as **hrm_migrator** only. Ensure the runtime **hrm_app**
   account cannot CREATE/DROP tables. Confirm `company_external_references`
   is present before seeding.
3. Enable the one-time flag **in the current process only**:
   `HRM_BOOTSTRAP_COMPANIES_ENABLED=true`. Run
   `php artisan db:seed --class=ItpCompaniesSeeder --force`.
   This command refuses other PostgreSQL hosts/ports/databases, and
   the seeder is not registered in `DatabaseSeeder`.
4. Run it again only if testing idempotence. Existing company IDs must remain
   identical; source-ID conflicts cause a transaction rollback.
5. First administrator uses `php artisan hrm:bootstrap-itp-admin <email>
   --password-file=<private file>` with the short-lived process flag
   `HRM_BOOTSTRAP_ADMIN_ENABLED=true`. Password contents must never be
   passed as command-line arguments or stored in Git. The CLI refuses
   previously existing accounts, missing portal-company mapping, or
   changes outside the isolated HRM database.
6. Verify exactly three `company_admin` memberships are created on the new
   user, that its account is active and that login/session security works.
   Immediately rotate an initial password that was communicated in chat,
   once password change/reset is functional. Delete the private plaintext
   bootstrap password file after its use.

Do **not** run the generic `DatabaseSeeder` on production: it creates
a test user. Do not turn on mail, MinIO, leave/bonus/personnel write feature
flags as part of initial company seeding.

Company data is intentionally a **snapshot**. Cross-platform data syncing
and portal SSO are a separate, explicit integration phase.
