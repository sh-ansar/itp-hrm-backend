# Private MinIO storage for HRM personnel PDFs

Set Laravel document disk is fixed to private minio only on Laravel server.
Required environment values: MINIO_ENDPOINT, MINIO_BUCKET, MINIO_ACCESS_KEY, MINIO_SECRET_KEY, MINIO_REGION (defaults to us-east-1). Never expose secrets to Vue or Vercel.

MinIO bucket is private. No anonymous GetObject/ListBucket, public ACL, frontend direct links or presigned client URLs. Use TLS and a least-privilege service account for the dedicated bucket/prefix. Enable backups, encryption, key rotation and approved retention.

Laravel authorizes each file upload/download using the user role, company and document. Streamed downloads are no-store and audited. Legacy local records require HRM_LEGACY_DOCUMENT_READS_ENABLED=true in an approved migration window; default is deny. New uploads use private minio; no automatic migration.

Writes remain feature-flagged OFF until approved release. Run live private-MinIO integration tests on synthetic data, including revoked sessions, cross-tenant denials, upload rollback, object-store outage and missing objects. Storage::fake tests only prove application logic, not a real MinIO connection.

Scope: existing personnel PDF endpoint only. Private employee photos, identity attachments and future protected leave evidence require separate storage and authorization implementation.
## Real-MinIO release test

The separate GitHub Actions workflow `.github/workflows/private-minio-integration.yml` provisions disposable MinIO with fake credentials and verifies actual S3 upload/read, anonymous object URL denial, cross-company authorization, role revocation and secure Laravel streaming. It never uses employee data or production servers. Its historical community container image is **only a temporary CI fixture**, not a vetted production build. Configure production against a supported and patched MinIO offering, with a restricted application service account and TLS.

A green test confirms the temporary CI setup only. Before go-live also verify a production-equivalent private bucket, authenticated object-store request logs, backups and restore, least-privilege bucket policy, TLS trust, retention policy, and a signed-off migration report reconciling legacy `local` document hashes with MinIO objects. Keep `HRM_DOCUMENT_WRITES_ENABLED=false` and `HRM_LEGACY_DOCUMENT_READS_ENABLED=false` until those gates are met.

The existing document read rule is narrowly scoped to authorized company administrators and HR specialists and checked on **each** request; plain employees and managers have no implicit PDF access. For future employee self-service or sensitive medical evidence, add reviewed document-specific policies instead of opening this existing route to all company members.
