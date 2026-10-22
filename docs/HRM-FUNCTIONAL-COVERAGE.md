# HRM functional coverage register — 22 Oct 2026

This register distinguishes code existence, automated proof, isolated browser proof and operational readiness. Never infer implementation from a tab name. Keep it in sync with the execution plan and change tickets.

| Domain / user action | Isolated browser evidence | Main known gap / release acceptance |
|---|---|---|
| Company-scoped org tree, parent/child department | Created two QA departments, reloaded and saw 4 tenant-visible departments | Repeat responsive ZIP comparison and manager role restrictions |
| Position directory | Created QA position; persisted on reload | Permissions, reference validation, bulk/archival actions |
| Employee create/profile tabs | Created synthetic employee; all 8 tabs navigated | Per-section save/reload, sensitive masking, locale audit |
| Initial assignment (hire) | Created active assignment dated 2026-10-01 | Verify HR personnel action log and correct legal order linking |
| Transfer | Moved QA employee effective 2026-10-15, reloaded | Retroactive/overlap/duplicate safeguards in E2E and docs |
| Termination | Process dated 2026-10-22 in HR journal | Define archive semantics; terminated != automatically archived |
| Annual leave ordinary | Type, draft, submit, admin approve, calendar 4 days confirmed | Manager -> HR staged review flagged OFF until linked E2E |
| Maternity / childcare / medical leave | Category API/UI found in code | Verify policy, evidence privacy, entitlement, locale and employer calendar |
| Manager leave recommendation | Backend PR #115: 7 test cases / 82 assertions; frontend PR #189 created | Mandatory paired Chrome + PostgreSQL test: manager scoped role, negative cases, final HR, audit, notifications |
| Leave balances | Backend test of booking after final HR only | Full entitlement policy/accrual, reversal and payroll tie-in |
| HR document drafts | QA draft order number persisted | Private PDF download/upload negatives, state machine, actual signing/issuance not implemented |
| Bonus / incentives | Modules and automated tests exist | End-to-end create/approve/history and salary integration not verified |
| Payroll, taxes, calculation fields | UI fields exist | No claim of full payroll engine; policy, providers and reconciliation required |
| Development, training and talent pool | Employee tabs exist | Full create/update history/authorization and reporting |
| Schedule/calendar/timesheets | Code and tests exist | Holiday color and statutory logic, closed periods, multi-role E2E |
| Security / company segregation | Isolated tests, role/tenant CI, backend scoped manager tests | Final approved four-role matrix; forbidden endpoint and private file direct URL proof |
| Localizations RU/KZ/EN/GE and dark mode | Smoke/visual tests | ZIP parity across all screens, validation text and mobile forms |
| Data migration | PostgreSQL CI clean/upgrade tests | Production backup and exact deployed-version migration rehearsal |
| SMTP/notifications | Infrastructure configured elsewhere | Delivery, retries, event mapping and message data minimization |
| Recovery / rollback | Runbook added in QA-RELEASE-RECOVERY-GATES.md | Actual restore drill, timings, owner sign-off |

Release gate: P0 scope must be accepted by product, security and operations. This register is not an authorization to activate live employee storage.
