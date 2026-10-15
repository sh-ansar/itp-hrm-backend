# Employee bonuses: first delivery contract (HRM B71)

This is a **personnel authorization workflow only**, not an accounting, payroll, banking, or actual award-payment implementation. The feature flag `HRM_BONUS_WRITES_ENABLED` is **false by default** in every environment.

## Domain

- `employee_bonuses` is company-scoped and references the employee with a composite `(employee_id, company_id)` FK.
- `bonus_kind`: `one_time`, `performance`, `holiday`, `other`.
- Required employee, date, positive fixed `amount` (DECIMAL 14,2), three-letter ISO currency, reference to a verified employer decision, optional notes.
- State machine: `draft → submitted → approved | rejected`; `draft/submitted → cancelled`. Decisions and cancellations are revision-guarded. Approved awards cannot be changed or cancelled by these endpoints; later reversals require a separate formal process and must preserve audit.
- `company_admin` and `hr_specialist` may prepare/submit; approval restricted to `company_admin` **other than creator or submitter**. No employee or department-manager access to monetary records.
- Create requires `request_key` UUID, unique per company. Identical retried creations reuse the same row; conflicting reuse is `409`.
- Stored audit events for creation, edit, submission, cancellation, approval and rejection; decision rationale never overwrites original notes.

## Endpoints

All paths under `/api/v1/companies/{company}/bonuses`, with Sanctum and active-account middleware:

- GET — company-scoped rows with `?status=`, `?employee_id=`, `?from=`, `?to=`, `?per_page=`.
- POST — create draft.
- PUT `/{bonus}` — edit a draft with `revision`.
- POST `/{bonus}/submit`, `/{bonus}/cancel` — state transition with `revision`.
- POST `/{bonus}/decision` — `revision`, `decision=approved|rejected`, `comment` (required for rejection).

`meta.payroll_posting_enabled=false` is explicit. The API returns no bank data or payment transfer records.

## Release safeguards

Before enabling feature flag: verify salary/bonus legal policies, tax handling, country and currency policy, who is allowed to view financial data, approval role and segregation of duties, and payroll integration contract. Enable only with customer approval and a backup. Never interpret `approved` as `paid`.

Run: `php artisan test tests/Feature/EmployeeBonusWorkflowTest.php`; database schema migrates only after controlled rollout. Keep the legacy SQL audit table immutable.
