# B74 – Repeatable employee private addresses (ZIP → Laravel)

## Contract and boundaries
Reference: `Зип.zip / Employees Page / Карточка сотрудника / Личные данные_0.png`.
The screenshot illustrates a list of postal addresses with entrance, floor, apartment and a "Основной" marker. The screenshot's text, geography and people are only reference artwork.

The existing fields in `employee_private_profiles` (`residence_address`, `registration_address`, entrance/floor/apartment) are **not migrated or overwritten**. They remain readable as a legacy section; only new user-created rows belong to `employee_private_addresses`. Do not silently copy or merge address data across these two stores. An explicit, consent-aware administrative deduplication workflow can be developed separately.

## Storage
`employee_private_addresses`: UUID record/employee/company, address kind (residence / registration / other), freeform `address_line`, nullable entrance/floor/apartment, `is_primary`, revision, archive timestamp and timestamps. Composite employee/company FK preserves tenant containment, and PostgreSQL + SQLite partial unique index guarantees at most one non-archived primary per employee. No geocoding, external map links, search indexing or exposure in the general employees list.

Sensitive addresses are readable only in HR/admin `GET /companies/{company}/employees/{employee}` under `private_profile.addresses` (up to 100, primary first). `meta.private_addresses_truncated` signals an incomplete list. Department managers and basic employees never receive `private_profile`. Data is not added to user-facing notifications, logs or audit contents.

## API
Existing private-record routes now accept `section=address`:
- `POST .../private-records/address`: full payload, 201, `{data:{id,revision}}`
- `PUT .../private-records/address/{record}`: payload plus required optimistic `revision`
- `DELETE .../private-records/address/{record}`: `{revision}`, soft archive.

Payload:
```json
{"kind":"residence","address_line":"Example synthetic address","entrance":null,"floor":null,"apartment":null,"is_primary":true}
```
Inputs are explicitly validated and unexpected keys are rejected. Only HR/admin members of the employee's company with `HRM_PERSONNEL_WRITES_ENABLED` may mutate; disabled routes remain 404 and stale writes return 409. A parent employee row lock serializes concurrent writes. Setting another record as primary demotes the prior one, increments its revision, and is committed atomically. Archiving a primary **does not implicitly nominate a new one**. Archive keeps the original record for HR retention/audit.

## Release/QA
1. Back up the PostgreSQL HRM database.
2. Deploy approved backend and run standard additive migrations before enabling the new frontend address editor. Do not run migrations on unrelated platform modules.
3. Verify server Feature tests on SQLite and PostgreSQL, then E2E with synthetic addresses under company_admin/hr/manager.
4. Keep personnel write flag off until authorization, privacy retention and back-up requirements are approved.
5. Do not import screenshot values, invent postal validation rules, or automatically geocode employee addresses.
