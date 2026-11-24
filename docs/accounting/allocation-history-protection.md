# Allocation history protection

The application writes allocation revisions and rows only through PaymentAllocationHistory in the subscriber-locked transaction. Fingerprints and totals are checked before replay; damaged latest revisions block financial writes and are flagged by reconciliation. This is integrity detection, not a cryptographic proof against a privileged attacker who can rewrite the hashes, delete complete revisions, or change the database schema.

SQLite migrations install four UPDATE/DELETE triggers. MySQL/MariaDB shared-hosting migrations do not require trigger privileges. Run `php artisan accounting:protect-allocations` to check protection, and only with adequate privileges run `php artisan accounting:protect-allocations --install`. The command does not change global server configuration or grant privileges. Partial installation is reported as incomplete; existing triggers are retained. Restrict direct database access and maintain independent backups. Raw SQL writes cannot be prevented without database-level privileges/triggers.

No historical backfill is included. Do not run the optional install command or migrations against production until predeployment verification.
