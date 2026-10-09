# AGENTS.md — ITP HRM backend

## Mission and continuity
This is a long-lived personnel management system based on the uploaded "Кадры 2026" presentation. When the owner says "давай", "продолжай", or "делай дальше", continue the NEXT UNFINISHED item in docs/EXECUTION-PLAN.md. Do not restart planning, overwrite completed work, or invent business requirements.

## Mandatory workflow
1. Inspect git status, latest commits, task plan, and current tests before edits.
2. Choose a single cohesive vertical slice and state its acceptance criteria.
3. Reuse existing code and domain logic before adding new implementations.
4. Implement code, security controls and automated tests. Never substitute demos for persistent functionality.
5. Run tests and inspect failures; do not claim success without evidence.
6. Update docs/EXECUTION-PLAN.md with completed work, verified checks, and next action.
7. Commit and push only verified changes; never use force push.
8. Report succinctly: done, verification, SHA, blocked, next.

## Guardrails
- Only work in C:\dev\itp-hrm\backend and C:\dev\itp-hrm\frontend on device Ansar. Do not access production or other project services.
- Do not modify existing PostgreSQL instances/databases or services until explicitly verified as project-specific and safe; use SQLite in-memory for tests until a dedicated PostgreSQL database is provisioned.
- Never commit .env, secrets, personal employee records or real payroll data.
- PostgreSQL canonical data, Redis queue/cache only; domain writes are transactional and auditable.
- All authenticated domain endpoints must enforce permissions AND company/department scope.
- Effective-dated assignments preserve history; never overwrite historical personnel events.
- Avoid claiming production readiness without integration, security and backup validation.
- Reference the exact presentation for scope; do not invent payroll rules, legal rates, integrations or legacy data mappings.
- Keep frontend/backend as distinct git repositories.
