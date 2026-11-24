# WATER-PERIOD-V2 — deployment gate

## Contract

- New readings use the actual observation date, not visit schedules. The first interval begins at installation or, if absent, the documented service start. Subsequent intervals use the prior observation date and value.
- The existing historical tariff service prices the stored interval. Never fabricate a tariff, split an unmeasured period or silently rewrite an issued invoice.
- Legacy calendar dates remain unchanged. Client-submitted calendar hints are retained separately for replay identity.
- Protect baseline dates and values, earlier reading anchors, and billed evidence against edits/deletion. Local pending readings with successors must not be silently changed.

## Historical exceptions (#43)

The previously supplied cleaned SQL snapshot contains four readings on meter 95, with recorded dates earlier than the stored period start:
419 (invoice 580), 420 (581), 421 (582), 422 (583).
Their actual_reading_date values are NULL. There is insufficient evidence to infer the true observation date or the reason for these postings. Do not backdate, reprice, delete or reverse automatically. Review the original reading sheets and receipts with the owner. Any necessary adjustment must be explicitly approved and recorded through a supported financial correction or reversal. No production records were modified.

## Release gate

1. Require all acceptance jobs on the exact head SHA (Laravel full, MySQL and Flutter full). Do not waive failed tests.
2. Take and verify a backup of the current database. Restore into an isolated MySQL database, never the live schema.
3. Record before/after counts and financial totals for readings, invoices, payments, transactions and invoice_tariff_lines (if present); apply pending migrations to the isolated copy only.
4. Run `php artisan water:audit-observation-intervals` and `php artisan water:audit-historical-tariffs`. New invalid chains and pricing blockers require resolution. Legacy early readings are explicitly reported for review; no automatic correction.
5. Add a synthetic reading on the isolated copy, verify its actual interval, historical price, invoice and ledger balance; verify original financial records are unchanged.
6. Exercise Android/Windows offline delayed sync, latest reading edit, duplicate retry and rejection of a stale/retired meter. Record evidence before production rollout.

A successful fresh-schema CI run is not a substitute for the actual-data migration rehearsal or field-device acceptance.
