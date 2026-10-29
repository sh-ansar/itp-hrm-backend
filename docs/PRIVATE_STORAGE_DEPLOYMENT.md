# HRM private local storage across Windows releases

## Rationale
Employee photographs are stored on Laravel's private `local` disk. Before this change, the disk defaulted to `storage/app/private` inside each release, so new release directories did not contain the existing files. Personnel documents continue to use the private MinIO disk and are not affected.

## Production contract
- Isolated HRM host: `WIN-487ME2E68DR`.
- Persistent local root: `C:/deploy/hrm/storage-shared/private`.
- Configure the HRM backend `.env` with `HRM_PRIVATE_STORAGE_ROOT=C:/deploy/hrm/storage-shared/private` in every new release. No public URL is permitted for the local disk.
- Run `ITP-HRM-FastCGI` and `ITP-HRM-HTTP` using the existing HRM service identities. Grant the HRM service identity **Modify** on the shared private folder, with Full Control for Administrators and SYSTEM only.
- This directory must NOT be placed under a web DocumentRoot, `public`, or the static `/hrm` site.
- The current release also has an NTFS directory junction `storage/app/private` pointing to this root to preserve compatibility with code deployed before this change.
- Keep the original folder `storage/app/private-pre-shared-20261030` as a recovery copy until the full next release has passed QA.
- Back up both PostgreSQL and the private photo directory; the PostgreSQL backup contains only photo paths, not the underlying images.
- Do not migrate photo files to `public` or expose direct download URLs. The existing authenticated photo controller remains responsible for access.

## Safe release checks
1. Copy the active HRM environment config into the candidate and verify the effective `filesystems.disks.local.root` points to the persistent location.
2. Verify the service identity can read and write the private root.
3. Check that every non-empty `employees.photo_path` exists via `Storage::disk('local')->exists()` before switching.
4. After the switch, repeat file-existence QA and authenticated photo reads. Verify that each document remains on MinIO.
5. If switching fails, restore HRM-only routing/config and keep the shared photo root unchanged. Never delete the shared directory during rollback.

## Scope
This change affects only HRM's `local` filesystem disk. Public/static assets and the private MinIO disk keep their existing behaviour.
