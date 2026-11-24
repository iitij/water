# Water hosting backup and recovery

Private backup format: encrypted tar.gz (GPG AES256), with outer SHA-256 and inner SHA-256 manifest.
Includes a transaction-consistent MariaDB dump, deployed application source and dependency locks, application .env including APP_KEY, storage/app uploads, and actual public web root. Excludes development archives, old SQL dumps, vendor dependencies (reinstall from composer.lock), sessions, logs and caches. No backup content or decryption key is uploaded to GitHub.

The baseline drill restores the SQL into a fresh randomly named database under a separate temporary DB user, exports it again, and compares the full deterministic SQL to the original dump; only the temporary DB/user created by that run are removed. It never imports into the live database. The archive is decrypted and internal files verified. Full disaster recovery also requires a separate server rebuild and authenticated application tests.

The recovery key is generated on the server and stored separately in a mode-600 file under a mode-700 directory. This protects the archive from disclosure alone, but does not protect against total hosting account compromise or loss. The owner must securely copy the encrypted backups off-host and retain the key independently (preferably a password manager or offline encrypted medium). Do not send keys in chat, logs, or repository. Do not delete the server key before independent copies are verified.

The first run requires every database object to be an InnoDB base table and rejects new views, routines, triggers or events until backup coverage is explicitly updated. Do not perform schema changes while backing up. The database dump is a single transaction; filesystem archives are collected separately, not an atomic joint snapshot. Plan a quiet/write-paused window for final migration backups when attachments are in use.

Only schedule and prune after successful restore drill. Proposed daily frequency and 30-day retention with a seven-backup minimum, subject to actual account quota. Keep a verified offsite copy before relying on automatic retention. Before importing historical SQL, verify this baseline and recover historical data to an isolated database; never restore over production without explicit approval.
