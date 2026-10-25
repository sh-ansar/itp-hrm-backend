# Durable workflow notifications

`HRM_WORKFLOW_NOTIFICATIONS_ENABLED` is OFF by default. Apply migration 2026_10_23_000003 before enabling it. Existing password recovery is unchanged while OFF.

Audited leave submission/recommendation/decision/reversal, personnel order review, personnel action completion and timesheet review create recipient-specific outbox records in the same transaction as audit/domain writes. Read/list audit events never send mail. A unique audit-event/recipient constraint prevents duplicate publication. HTTP transactions perform no SMTP calls.

Managers receive only submissions for employees in their current explicit department scope when staged review is enabled; HR/admin receive subsequent review events. Linked subjects and request authors receive leave decisions. Other employee/company accounts are excluded. Membership, active account, subject scope and opt-out preferences are checked again before sending. Email contains only a generic translated update, the HRM URL and a correlation ID, never leave type, health evidence, employee name, request note, amount or rejection text.

Use a dedicated Redis queue/cache for deployment, scoped to HRM. Start `php artisan queue:work redis --queue=hrm-notifications --tries=8 --timeout=60`; run Laravel scheduler each minute. `hrm:notifications:dispatch --limit=100` discovers due durable records, including expired worker leases, and refuses sync/null queues. Production dispatch requires SMTP. Configure MAIL_TIMEOUT below the worker timeout (default 20 seconds). No queue or scheduler service is installed automatically by the migration.

States: pending, sending, accepted, failed, cancelled. Accepted means the configured mail transport accepted the message, not that the recipient mailbox delivered it. Attempts and safe exception class codes are recorded in hrm_notification_deliveries; raw SMTP messages/secrets are never copied into the journal. Eight attempts and bounded backoff prevent endless sending. Worker leases prevent parallel claims. Stable Message-ID and correlation headers aid provider deduplication; SMTP has at-least-once semantics after a crash between provider acceptance and DB acknowledgment, so exact-once delivery cannot be promised.

Password reset uses Laravel's existing one-time broker and an encrypted queued notification with five retries. Expired, superseded or inactive-account tokens are checked immediately before sending. Its normal Laravel queue/failed-job journal is separate from workflow delivery history. Never log/decrypt reset job payloads.

RU/KK/EN/KA templates are provided. Optional locale on user preferences defaults to RU and old clients may omit it without clearing a saved language. Notification channel/rule opt-outs are honored at creation and delivery. Authenticated GET /api/v1/me/notifications returns only the caller's generic events in companies where membership remains valid. PUT /me/notifications/{id}/read is owner-scoped and idempotent.

Before activation: verified dedicated Redis worker/scheduler, SMTP test with a safe capture transport, exact PostgreSQL CI, restored-schema migration rehearsal, permissions on the new tables, and current membership/department acceptance. Do not enable the flag with the default synchronous worker or claim accepted messages are mailbox delivery confirmations.
