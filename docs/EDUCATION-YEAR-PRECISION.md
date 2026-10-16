# Education record year precision (B75)

**Reference:** ZIP / Employee Card / "Кадровый резерв и обучение 3" / "Образование и квалификация": Start `2002`, Finish `2006`. This is **year precision**, not evidence of January 1 or December 31. Persist this accurately.

## Additive schema and compatibility
The existing `employee_education_records` table remains the canonical source for formal education. An additive migration adds nullable unsigned small integer `started_year` and `ended_year`. The optional precise `started_on` and `ended_on` fields remain for valid source dates; old populated records are untouched.

The Laravel FormRequest accepts optional years (1850–2200), rejects end year before start year, and rejects a year that contradicts a supplied precise date. Clients using the original v1 date-only payload continue to work. Their edits do **not** erase previously saved year precision if they omit the new fields.

GET employee detail now includes four date-related fields:
- `started_year: number|null`, `ended_year: number|null` — source can be a year-only record.
- `started_on: string|null`, `ended_on: string|null` — optional verified full dates.
- Client should show year values where set; otherwise it may visually derive year from a verifiable precise date without persisting that derivation.
- Do NOT fabricate `2002-01-01` or `2006-12-31`.

## Scope
The UI also contains organization, education type, description/direction and a diploma reference. The current API stores these as `organization`, `education_type`, `specialization` and `document_reference`. Document reference is **not** proof of uploaded/verified private PDF. The next education/committee workflow remains a separate reviewed product requirement. No automatic calculations of education equivalence, years of experience or reserve eligibility should be inferred from these fields.

## Acceptance
Run the backend SQLite + PostgreSQL suites, plus year-only test with `2002`–`2006`, null full dates and legacy edit preserving year. Frontend Chromium test should enter 4-digit years, save, reload and archive. No real personal data or qualification documents in CI. Feature flag unchanged.
