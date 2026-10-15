# Private MinIO storage for HRM personnel PDFs

Set Laravel document disk is fixed to private minio only on Laravel server.
Required environment values: MINIO_ENDPOINT, MINIO_BUCKET, MINIO_ACCESS_KEY, MINIO_SECRET_KEY, MINIO_REGION (defaults to us-east-1). Never expose secrets to Vue or Vercel.

MinIO bucket is private. No anonymous GetObject/ListBucket, public ACL, frontend direct links or presigned client URLs. Use TLS and a least-privilege service account for the dedicated bucket/prefix. Enable backups, encryption, key rotation and approved retention.

Laravel authorizes each file upload/download using the user role, company and document. Streamed downloads are no-store and audited. Legacy local records require HRM_LEGACY_DOCUMENT_READS_ENABLED=true in an approved migration window; default is deny. New uploads use private minio; no automatic migration.

Writes remain feature-flagged OFF until approved release. Run live private-MinIO integration tests on synthetic data, including revoked sessions, cross-tenant denials, upload rollback, object-store outage and missing objects. Storage::fake tests only prove application logic, not a real MinIO connection.

Scope: existing personnel PDF endpoint only. Private employee photos, identity attachments and future protected leave evidence require separate storage and authorization implementation.
## Real-MinIO release test

The separate GitHub Actions workflow `.github/workflows/private-minio-integration.yml` provisions disposable MinIO with fake credentials and verifies actual S3 upload/read, anonymous object URL denial, cross-company authorization, role revocation and secure Laravel streaming. It never uses employee data or production servers. CI rebuilds a pinned historical upstream community source release with Go and uses it only for synthetic compatibility tests. It is **not** a vetted or supported production deployment. Configure production against a supported and patched MinIO offering, with a restricted application service account and TLS.

A green test confirms the temporary CI setup only. Before go-live also verify a production-equivalent private bucket, authenticated object-store request logs, backups and restore, least-privilege bucket policy, TLS trust, retention policy, and a signed-off migration report reconciling legacy `local` document hashes with MinIO objects. Keep `HRM_DOCUMENT_WRITES_ENABLED=false` and `HRM_LEGACY_DOCUMENT_READS_ENABLED=false` until those gates are met.

The existing document read rule is narrowly scoped to authorized company administrators and HR specialists and checked on **each** request; plain employees and managers have no implicit PDF access. For future employee self-service or sensitive medical evidence, add reviewed document-specific policies instead of opening this existing route to all company members.

## Existing local PDF migration (operator runbook)

The Laravel command `hrm:documents:migrate-to-minio` handles **only** legacy `personnel_documents` PDF records; it cannot migrate photos or hypothetical future medical attachments. It requires one company UUID and an active administrator user ID. No web route is exposed, and every move is audited.

1. Keep `HRM_DOCUMENT_STORAGE_MIGRATION_ENABLED=false` during normal operation. Prepare an isolated backup of PostgreSQL, the private local files and the target MinIO bucket. Verify MinIO TLS and private bucket policy.
2. Dry-run with `php artisan hrm:documents:migrate-to-minio COMPANY_UUID --actor=ADMIN_USER_ID`. This only counts eligible records; it cannot read or change blobs.
3. In an approved maintenance window, set `HRM_DOCUMENT_STORAGE_MIGRATION_ENABLED=true` on Laravel, refresh config, then run `php artisan hrm:documents:migrate-to-minio COMPANY_UUID --actor=ADMIN_USER_ID --execute`. The command checks source SHA-256/size, uploads with a random private key, downloads and hashes the new object, and only then atomically switches the database pointer with an audit event.
4. A failed object/hash/DB operation leaves the source pointer intact. Successful operations **never delete** the old local PDF, and subsequent executions skip already-migrated rows. Inspect failed counts and perform independent inventory reconciliation. Require approval before retiring the original private files.
5. Reset `HRM_DOCUMENT_STORAGE_MIGRATION_ENABLED=false`. Do not remove the private local storage backup until the agreed retention period has elapsed. Never enable public bucket access to make a failed migration work.

This migration is intentionally not triggered by application startup, deploy hooks, or browser access. No existing document has been transferred by creating this code.
