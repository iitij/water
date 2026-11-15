# Encrypted real-data backup — 2026-11-16

The encrypted backup file `water_project_cleaned_2026-11-16.sql.aes256gcm.json` is a **private customer-data backup**, not a fixture. **Do not distribute it publicly.** The AES-256-GCM recovery key is **not committed** and must be obtained separately from the owner.

## Restore locally

Prerequisite: Python 3 and `pip install cryptography`.

```bash
python database/secure-backups/restore_encrypted_sql.py \
  database/secure-backups/water_project_cleaned_2026-11-16.sql.aes256gcm.json \
  /secure/path/to/water_project_cleaned_2026-11-16.RECOVERY_KEY.txt \
  /secure/path/to/water_project_cleaned.sql
```

The restore tool never replaces an existing output file. It verifies authenticated decryption and SHA-256 before writing SQL.

## Audited changes to the supplied MySQL 8.4 dump

- The dump contains **zero** billed water-tanker amounts, tanker invoices, and service-request records, so no historical receipt or invoice total required modification for this snapshot.
- Removed the service-request table; removed `invoices.tanker_charges`; removed obsolete meter tenant identity columns.
- Removed 19 obsolete settings and 12 ServiceRequest permission records (plus 12 role-permission associations).
- Purged 2 soft-deleted meter rows **only after verifying zero attached invoices, readings, receipts or transactions**.
- Kept 267 subscribers, 351 invoices, 240 invoice items, 194 receipts, 242 readings and 545 financial transactions unchanged.
- Cleared 62 ephemeral cache, failed-job, session and access-token rows for secure restore. Existing API tokens and sessions must be reissued.
- Removed three obsolete migration-history entries, which means the corresponding source migration files **must also be reconciled before importing into the active application**.
- Plaintext SQL SHA-256: `25e3527f44fd6d5077f140ba995daa82b11549a7264a9412727d6ee33bde6c3b`.

## Important deployment blocker

**Do not import this snapshot into production or assume it is compatible with the current `main` branch yet.** The application still contains service-request/tanker classes, API endpoints, Flutter routes, and legacy migration files that must be removed together. An independent legacy-feature source audit is tracked in GitHub Actions and remaining work must pass integration tests before deployment.

This backup is a recoverable, protected data artifact only. No live database was accessed or changed during preparation.
