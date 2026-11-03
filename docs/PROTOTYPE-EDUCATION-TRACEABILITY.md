# Education / qualification prototype coverage and next domain boundary

Source screenshot: `Зип.zip` → `Employees Page — копия/Карточки сострудников/Employees Page/Desktop_Employees_Карточка сотрудника_Кадровый резерв и обучение 3.png`.

## Direct mapping (implemented B74+B75, frontend F125+F126)

| Prototype control | Canonical field | Notes |
| --- | --- | --- |
| Вид | employee_education_records.education_type | Free-text initially; real organizational/jurisdiction directory later |
| Организация | organization | Linked verified institution directory is future work; never infer by display label |
| Начало | started_year | 4-digit nullable year; no invented Jan 1 |
| Окончание | ended_year | 4-digit nullable year; must not precede start |
| Описание / направление | specialization | Free-text description; reviewed catalog optional |
| Диплом.pdf | document_reference | Metadata only; **actual file upload not implemented** |

Additional `started_on`/`ended_on` remain nullable *precise* dates for imported records with verified day and month. Versioned updates, archived rows and company/employee FK protect history and tenant boundaries.

## Other visible block: assessment and committee decisions

The same screenshot includes `Оценка соответствия и решения комиссии`: a dated evaluation, multiple criteria, requirements, observed facts, percentage scores, recommendations, document export and actions. These are **not** the same as degree records. Do not derive a fictional "78% fit" or generate fake committee decisions from education alone.

Proposed normalized future model, pending approved workflow and real scoring methods:

- `assessment_cycles`: UUID, company, type, as-of date, status, lock/decision timestamps, revision, created/decided actors.
- `assessment_requirements`: cycle, criterion code/version, target position/department, qualitative requirement, weighting, effective-period and approved rule revision.
- `employee_assessment_facts`: assessment, employee, criterion, evidence reference and authorized observed value; never copy unrelated private PII into scores.
- `assessment_scores`: assessment+criterion, explicit score/weight calculation rule version, evidence, reviewer, revision. Percentages are derived only from owner-approved formula.
- `committee_decisions`: assessment, company, reviewer signatures/roles, outcome, action basis, date and immutable audit/event sequence; correction/reversal instead of overwriting approved decisions.
- Private supporting evidence and meeting minutes require tenant-scoped attachment handling, malware screening and retention policy.

Design acceptance BEFORE implementation: jurisdiction/tenant role matrix, meeting quorum/delegation, score thresholds, appeal/revision workflow, export privacy limits, historical restatement rules and consent/retention. Until specified, prototype committee table is UI only and must not be presented as real approved assessment.

## Presentation/QA

Education form should use reusable Vue inputs and modal/drawer, consistent dark/light tokens and responsive layout. It must preserve four-digit years on reload, expose missing private attachments honestly and avoid fake academic documents in connected mode. Unapproved committee evaluation belongs in the open worklist, not production-grade readiness metrics.
