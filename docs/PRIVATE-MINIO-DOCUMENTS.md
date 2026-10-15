# Private MinIO storage for HRM personnel PDFs

Set Laravel document disk is fixed to private minio only on Laravel server.
Required environment values: MINIO_ENDPOINT, MINIO_BUCKET, MINIO_ACCESS_KEY, MINIO_SECRET_KEY, MINIO_REGION (defaults to us-east-1). Never expose secrets to Vue or Vercel.

MinIO bucket is private. No anonymous GetObject/ListBucket, public ACL, frontend direct links or presigned client URLs. Use TLS and a least-privilege service account for the dedicated bucket/prefix. Enable backups, encryption, key rotation and approved retention.

Laravel authorizes each file upload/download using the user role, company and document. Streamed downloads are no-store and audited. Legacy local records require HRM_LEGACY_DOCUMENT_READS_ENABLED=true in an approved migration window; default is deny. New uploads use private minio; no automatic migration.

Writes remain feature-flagged OFF until approved release. Run live private-MinIO integration tests on synthetic data, including revoked sessions, cross-tenant denials, upload rollback, object-store outage and missing objects. Storage::fake tests only prove application logic, not a real MinIO connection.

Scope: existing personnel PDF endpoint only. Private employee photos, identity attachments and future protected leave evidence require separate storage and authorization implementation.