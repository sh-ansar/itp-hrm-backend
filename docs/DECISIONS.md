# Architecture decisions

ADR-001: Separate frontend and backend repositories; stable versioned HTTP API.
ADR-002: Modular Laravel monolith, not microservices initially.
ADR-003: PostgreSQL canonical employee store; Redis never stores canonical personnel records.
ADR-004: Sensitive data private-by-default, scope-based authorization.
ADR-005: Historical effective-date records for personnel movements.
ADR-006: Reuse BNT design language, not BNT runtime/DOM scripts.

ADR-007: First management API is opt-in and OFF by default; even a company admin cannot delegate the admin role through HTTP.
ADR-008: Role grants and revocations plus audit append must use the same database transaction. Sensitive employee-list reads synchronously log minimal metadata; denied requests do not emit false-success audits.
ADR-009: The user-to-company role matrix remains provisional until business and security approval. Admin bootstrapping is an offline controlled operation, never an anonymous API.
