# ITP Solutions synthetic QA catalogue

`ItpSolutionsQaSeeder` is an idempotent initial fixture catalogue, not a production bootstrap or workflow acceptance test. It refuses production, requires an explicit opt-in marker and exact database identity, and never resets existing test edits or account passwords.

Persistent target: loopback PostgreSQL port 55432, database hrm_qa_synthetic_20261025, user hrm_qa_20261025, APP_ENV=local/testing, HRM_QA_SEED_TARGET=hrm-qa-20261025. Set a private HRM_QA_ACCOUNT_PASSWORD of at least 20 characters. The only alternative is explicitly marked testing SQLite :memory: for automated guard/idempotence checks.

Catalogue: one clearly labelled ITP Solutions (Synthetic QA) company; 15 hierarchical departments; 30 positions; 32 synthetic employees with unique QA personnel numbers and example.test emails; 34 non-overlapping assignments, 30 current employees, transfer/termination/rehire history; three schedules; approved fixture staffing plan with vacancies; four training records; three approved fixture leave bookings; two draft bonuses and a labelled sample October calendar. There are no real IINs, phones, identity documents or payroll rates; the sample calendar is not statutory holiday policy.

Four roles: admin/hr/manager/employee@hrm.example.test. They exist only in isolated QA. The manager has an explicit Engineering scope; accounts are explicitly linked to different employee records. Passwords must never be committed or printed.

Run `php artisan db:seed --class=ItpSolutionsQaSeeder` only with the guarded QA environment. A second run does not duplicate rows. Fixture history is not evidence that a user workflow ran; real hire/transfer/termination and approval E2E remain separate acceptance evidence.

Local tests: guard against production and missing opt-in; run twice; verify entity counts, assignment history, example.test domains and absence of phone numbers. PostgreSQL creation/seed and Vue visibility must be recorded before claiming the persistent QA is ready.
