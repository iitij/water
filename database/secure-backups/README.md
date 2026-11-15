# Encrypted owner database snapshot

The file `water_project_cleaned_2026-11-16.sql.aes256gcm.json` is an encrypted, private customer-data backup, not application source code or a test fixture. Never publish the recovery key. Do not add plaintext database exports to Git.

## Local recovery

Requirements: Python 3 and `pip install cryptography`.

```bash
python database/secure-backups/restore_encrypted_sql.py \
  database/secure-backups/water_project_cleaned_2026-11-16.sql.aes256gcm.json \
  /secure/path/to/water_project_cleaned_2026-11-16.RECOVERY_KEY.txt \
  /secure/path/to/recovered_database.sql
```

The restore tool refuses to overwrite an existing file and checks authenticated decryption plus a stored SHA-256 digest.

This recovery archive was prepared from a snapshot taken before later application schema updates. Before importing into a live environment, use a disposable MySQL database, verify migration compatibility, reconcile historical financial balances and follow the application's deployment acceptance tests. A successful source cleanup alone does not certify that any deployed database or offline device has been migrated.

The original snapshot and any live installation were not modified by the repository cleanup.
