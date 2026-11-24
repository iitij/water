# WATER-PERIOD-V2 — correctness-first acceptance and deployment

## Engineering priority

Correct business and accounting invariants take precedence over compatibility with old data or old client behavior. Do not retain incorrect algorithms or weaken acceptance tests to accommodate old records. Existing records and a live database are not prerequisites for code acceptance; their remediation or reset is a separate, explicitly controlled deployment operation.

## Contract

- New readings use the actual observation date, not visit schedules. The first interval begins at installation or, if absent, the documented service start. Subsequent intervals use the prior observation date and value.
- The existing historical tariff service prices the stored interval. Never fabricate a tariff, split an unmeasured period or silently rewrite an issued invoice.
- There is one writable interval algorithm: actual observations. Calendar-era records cannot be edited through the normal reading workflow; their existing marker is retained solely to distinguish old records from validated observations. Client-submitted period hints are retained separately for replay identity only, never for computation.
- Protect baseline dates and values, earlier reading anchors, and billed evidence against edits/deletion. Local pending readings with successors must not be silently changed.

## Quantity precision

Meter values, installation baseline and derived consumption use DECIMAL(12,2). The model subtracts integer hundredths, rejects decreasing values, and never accepts client-supplied usage as authoritative. Posting does not recalculate stored quantities. API input permits at most two decimal places; Flutter offline subtraction, administrative reports and spreadsheet exports preserve the fractional units. Historical tariff line rounding remains per line. Tests cover 35.50 units at 600.00, a 0.01-unit difference, zero usage, edits and invalid precision. The baseline is captured during meter creation/replacement and the replacement has its own installation date, regardless of financial history. API and mobile cache select the latest reading by observed date rather than insertion order or calendar forecast. No historical quantity or financial backfill is part of these schema migrations.

## Write-path and database safeguards

Normal database setup never imports historical subscriber/reading rows. The old archive seeder is retired; optional demo initialization uses `SyntheticObservationSeeder` in local/testing environments only, without truncation. The former screenshot seeders delegate to that same path. Existing backups remain independent of application initialization.

MySQL 8.0.16+ uses an enforced row CHECK for observed intervals and exact quantities, without SUPER or trigger privileges. SQLite uses insert/update triggers. The historical marker is excluded from that row constraint, so privileged raw MySQL writes can still assign it; normal model creation rejects that marker and updates cannot change it. Do not grant unrestricted SQL write access to clients. Row constraints do not prove cross-row continuity; subscriber/meter locks, current-reading locking queries and the model enforce that contract. Deleted observations cannot be restored into the chain through the model. MySQL CI additionally exercises independent-process races for same-date reading insertion and concurrent invoice issuance.

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

## Physical cash settlement

New and currently open sessions use opening cash plus active cash receipts, excluding bank transfers and checks. All payment methods still contribute to subscriber balances; treasury refunds are separate from collector cash. Closed historical snapshots are not recalculated and carry `legacy_recorded` in the API if their settlement basis was not recorded. A new closure uses `physical_cash` and preserves the original snapshot even after a documented correction. API exposes cash, non-cash and total receipt collections separately. A new offline payment arriving after close still requires explicit session-identity and exception workflow; do not interpret the concurrent close test as complete offline reconciliation.

## Deferred payment session identity

Flutter freezes the known cash-session ID and payment date before the first send, then retains both in its persistent queue. New deferred payments require a known original open session owned by the authenticated collector and a stable UUID/date. Exact existing receipts may be replayed after closure. Unposted late or legacy unidentified operations receive HTTP 409 for human reconciliation; no automatic transfer to a newer till or mutation of closed totals. An independently reviewed late-receipt posting workflow and cross-device close reconciliation remain required before field acceptance.

## Late receipt review (separate handover only)

Authenticated collector submits an immutable late receipt claim for their own closed till. Global issuance lock reserves its UUID; duplicate claims replay, differing claims conflict. An independent supervisor must verify an approved original till and documented separate treasury cash handover or non-cash receipt confirmation, with unique evidence reference. Approval posts one normal subscriber receipt through PaymentService without assigning it to any collector till; the review links original till, payment and approval evidence. Reject requires evidence; no financial posting. Already-included cash, disputed receipt identity, unidentified legacy till and missing treasury evidence must remain unresolved until independently reconciled; this is not a treasury ledger or automatic reconciliation. Closed snapshots remain intact. API is separate from standard posting. On deferred payment HTTP 409, the Flutter queue submits a claim when it knows the original till; the server revalidates all conditions. The local operation remains in needsAttention until a successful retry returns its actual posted receipt. The Filament cash-session table provides an independent supervisor decision action. Real-device and end-to-end acceptance are still required.

## Already-counted cash allocation

For approved physical-cash settlements only, the positive original counted-minus-expected variance (validated against the frozen snapshot) is a conservative maximum. Each approved already_counted claim consumes its exact amount under the same global issuance and cash-session locks. The original cash variance is never rewritten; API/PDF disclose allocated and unallocated surplus separately. Legacy snapshots, negative/zero variance, and amounts exceeding available surplus cannot be allocated. Later bookkeeping corrections do not increase this capacity. No additional cash movement is created by posting a reviewed receipt; the subscriber subledger is credited once. Ordinary voiding of approved exceptional receipts is prohibited; independently evidenced refunds or account transfers are separate operations. This is not a complete treasury general ledger; cash already counted but offset by a shortage requires separate investigation, not automatic allocation.

## Original ledger posting integrity

The service validates persisted document type, owner, meter provenance, exact signed amount and valid original state before writing. Multiple or deleted original postings are rejected, never guessed. Reversals check the original reference including retired meter identity. `ledger:migrate` is now a read-only original-invoice/payment audit, not a rebuild; the old temporary destructive script is disabled. This limited audit does not yet certify all refunds, transfers, reversals or general ledger constraints; further reconciliation and database enforcement remain required.

## Read-only document reconciliation

`php artisan ledger:audit [--subscriber=ID] [--json]` inspects non-deleted subscriber accounts independently under their account lock. It checks original and required reversal postings, refund and transfer identities, unexpected postings, net ledger versus independently calculated document balances, and latest allocation revision hash, per-invoice and per-source totals. The existing allocation reconciliation endpoint exposes `document_reconciliation` and cannot report a match for offsetting posting errors. No repair/replay is performed. Unsupported legacy postings are reported, not silently accepted. This is a diagnostic account-subledger audit, not a complete treasury audit; cross-account checks are not a single global snapshot, and database-level posting uniqueness/immutability is a further step.\n
## Database ledger protection

A unique index on (reference_type, reference_id, type) prevents duplicate referenced postings even through raw SQL. Pre-existing duplicate keys stop migration without deleting or merging financial history. Unreferenced legacy rows remain auditable; they are not asserted valid. MySQL/MariaDB replace the ledger subscriber cascade and meter SET NULL foreign keys with RESTRICT; SQLite uses parent-delete triggers to avoid rebuilding a table referenced by financial corrections. SQLite installs append-only triggers automatically. MySQL/MariaDB require an explicit privileged `php artisan ledger:protect --install` followed by `php artisan ledger:protect --check` as a production acceptance gate (shared hosting may deny CREATE TRIGGER). The check returns failure when a trigger or unique index is absent; successful check verifies named guards are present, not that a privileged administrator cannot remove them. No new arbitrary SQL inserts are certified valid by this migration; the service and ledger audit still validate document identity. Meter retirement alone may move a current meter ID to matching retired provenance with all monetary fields unchanged. Historical correction no longer redundantly updates the new ledger entry. Retired ad hoc maintenance scripts now exit without bootstrapping or modifying data. Corruption tests deliberately disable/reinstall the SQLite update trigger solely in their isolated test fixtures. DDL and backup/restore permissions must be restricted; triggers cannot protect against privileged DDL, TRUNCATE, or restoring altered backups. No production data was modified.

SQLite's INSERT OR REPLACE can bypass a delete trigger with recursive triggers disabled; a separate BEFORE INSERT guard blocks replacement by primary key or by referenced posting key. The MySQL CI job explicitly enables trigger creation in its disposable MySQL container only, then runs a dedicated non-transactional migration/install/guard test. No hosting configuration is changed. For an existing SQLite development database whose ledger migration already ran, run `php artisan ledger:protect --install` and verify `--check` to install the additional guard.
