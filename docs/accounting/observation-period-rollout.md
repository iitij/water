# WATER-PERIOD-V2 — correctness-first acceptance and deployment

## Engineering priority

Correct business and accounting invariants take precedence over compatibility with old data or old client behavior. Do not retain incorrect algorithms or weaken acceptance tests to accommodate old records. Existing records and a live database are not prerequisites for code acceptance; their remediation or reset is a separate, explicitly controlled deployment operation.

## Contract

- New readings use the actual observation date, not visit schedules. The first interval begins at installation or, if absent, the documented service start. Subsequent intervals use the prior observation date and value.
- The existing historical tariff service prices the stored interval. Never fabricate a tariff, split an unmeasured period or silently rewrite an issued invoice.
- There is one writable interval algorithm: actual observations. Calendar-era records cannot be edited through the normal reading workflow; their existing marker is retained solely to distinguish old records from validated observations. Client-submitted period hints are retained separately for replay identity only, never for computation.
- Protect baseline dates and values, earlier reading anchors, and billed evidence against edits/deletion. Local pending readings with successors must not be silently changed.

## Quantity precision

Meter values and derived consumption use DECIMAL(12,2). The model subtracts integer hundredths, rejects decreasing values, and never accepts client-supplied usage as authoritative. Posting does not recalculate stored quantities. API input permits at most two decimal places; Flutter offline subtraction, administrative reports and spreadsheet exports preserve the fractional units. Historical tariff line rounding remains per line. Tests cover 35.50 units at 600.00, a 0.01-unit difference, zero usage, edits and invalid precision. No historical quantity or financial backfill is part of this schema migration.

## Historical exceptions (#43)

The previously supplied cleaned SQL snapshot contains four readings on meter 95, with recorded dates earlier than the stored period start:
419 (invoice 580), 420 (581), 421 (582), 422 (583).
Their actual_reading_date values are NULL. There is insufficient evidence to infer the true observation date or the reason for these postings. Do not backdate, reprice, delete or reverse automatically. Review the original reading sheets and receipts with the owner. Any necessary adjustment must be explicitly approved and recorded through a supported financial correction or reversal. No production records were modified.

## Code acceptance and separate production deployment gate

1. Require all acceptance jobs on the exact head SHA (Laravel full, MySQL and Flutter full). Do not waive failed tests.
2. For production deployment only: take and verify a backup of the current database. Restore into an isolated MySQL database, never the live schema.
3. Record before/after counts and financial totals for readings, invoices, payments, transactions and invoice_tariff_lines (if present); apply pending migrations to the isolated copy only.
4. Run `php artisan water:audit-observation-intervals` and `php artisan water:audit-historical-tariffs`. New invalid chains and pricing blockers require resolution. Legacy early readings are explicitly reported for review; no automatic correction.
5. Add a synthetic reading on the isolated copy, verify its actual interval, historical price, invoice and ledger balance; verify original financial records are unchanged.
6. Exercise Android/Windows offline delayed sync, latest reading edit, duplicate retry and rejection of a stale/retired meter. Record evidence before production rollout.

A successful fresh-schema CI run is not a substitute for the actual-data migration rehearsal or field-device acceptance.
