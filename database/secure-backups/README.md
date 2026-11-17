# Private database recovery utilities

The repository includes a generic offline SQL-backup decryption utility, **not customer database snapshots**.

Keep production and historical database backups outside Git, protected by an owner-controlled encryption key. Never commit SQL dumps, decryption keys, or backups containing customer information.

## Offline recovery from an owner-controlled archive

Use `restore_encrypted_sql.py` only with a verified encrypted archive and the corresponding key stored securely on the operator's computer.

```bash
python database/secure-backups/restore_encrypted_sql.py \
  /secure/path/to/owner-backup.aes256gcm.json \
  /secure/path/to/RECOVERY_KEY.txt \
  /secure/path/to/recovered_database.sql
```

The utility refuses to overwrite an existing output file and authenticates its decrypted contents.

**Important:** Removing an archive from the current Git tree does not erase copies reachable in historical commits, mirrors, caches, or local clones. Git-history/privacy retention requires a separately approved history-cleaning operation. Never perform history rewriting during normal application deployment.

Do not import historical SQL into production before an isolated restore, schema migration, financial reconciliation, and device/UI acceptance tests.
