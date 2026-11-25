# A-C-09-L01 — Round 11 isolated cross-account QA

Authority: coordination/accounting `245e952`; accepted frozen V2 `40a216bec7ebd540b2d127bea4cdd9180cf0c52a`. D concurrence: PR80 comment 7151832923 and QA matrix `ba3ea14`. Separate QA-only branch. Test source `tests/Feature/CrossAccountPendingReviewLockTest.php`, blob `cf34bab9609e6f9e12d8fa58cb5edd93f04f2a28`, code commit `a5ed1b677a8cdbbb261ea93c5ff7bcc98f6ece25`. No product/schema/permission/dependency changes. No tests executed by A; source inspection only, lint and runtime pending D/cloud. No old suite repeats.

## Two new cases only

L01-R: actual LateReceiptReviewService::decide rejects an existing valid pending review for B.
L01-I: actual LateReceiptReviewService::submit records a new pending review for B.
Each case first runs its own independent uncontended control, using normal CashSessionService open/close and LateReceiptReviewService. Both A/B have different subscriber/meter identities; B actor and reviewer distinct and authorized only in the disposable fixture. A has no documents, readings or pending review; A acquires subscriber then meter, calls the real MeterDataPurger::hasPendingReceiptReviewLocked and asserts false, then holds its transaction. B begins the ordinary service after the guard-held marker. No intentional deadlock, no artificial locking of B's review. A rolls back without mutations. Fresh independent empty synthetic schema for EACH method.

## Safety and runner

D reviews exact branch/head first. One authorized cloud runner, sequential. Existing approved published environment, clean exact checkout, locked Composer dependencies; genuine isolated MySQL 8.0 socket-only, database water_test, dedicated empty schema verified separately by runner before each method. Explicit WATER_L01_ISOLATED=1; setup rejects non-testing, non-MySQL, wrong database, missing socket or nonempty schema. Ordinary migrate on EMPTY synthetic schema only; no migrate:fresh, drop, reset, migration replay against existing schema, user device, production, network probe, grants, global settings or changes to runner permissions. Require pcntl/posix. Use existing approved runner to supply isolated connection variables; if it cannot provision separate empty schemas with normal permissions, report BLOCKED. Do not adapt production or use the accepted V2 runtime database.

For each fresh schema, from exact reviewed checkout with its locked dependencies, run lint first (not an old test):
```bash
php -l tests/Feature/CrossAccountPendingReviewLockTest.php
```
Then only the following individual methods (each command receives a DIFFERENT newly provisioned empty synthetic database instance/schema, with existing approved socket-only configuration):
```bash
WATER_L01_ISOLATED=1 timeout 120s php vendor/bin/phpunit tests/Feature/CrossAccountPendingReviewLockTest.php --filter '^.*::test_l01_r_existing_review_on_independent_account$' --fail-on-incomplete --fail-on-skipped --log-junit /workspace/scratch/l01-r.xml
WATER_L01_ISOLATED=1 timeout 120s php vendor/bin/phpunit tests/Feature/CrossAccountPendingReviewLockTest.php --filter '^.*::test_l01_i_new_review_on_independent_account$' --fail-on-incomplete --fail-on-skipped --log-junit /workspace/scratch/l01-i.xml
```
Use supported runner equivalent if required; do not bypass environment restrictions. Verify each filter executes exactly one parent test and one embedded control. No third case, no repetition. Keep raw stdout/stderr, JUnit, exit and worker cleanup; report runner limitations.

## Attribution and bounded lifecycle

Connection-local REPEATABLE READ, independent processes/reconnects and connection IDs; atomic monotonic guard-held, B-start, commit, release and result markers. 5s observation after B starts, 30s shared join deadline (plus bounded 2s forced cleanup if required), 120s outer safety timeout. No sleep-only ordering. Valid control must commit and preserve invariants within observation window. Ordinary SHOW INDEX/EXPLAIN metadata on the test table only; no EXPLAIN ANALYZE, no plan/index changes. B before/after SQL markers omit bindings. Optional one read-only connection-filtered performance_schema data_lock_waits/data_locks observation ONLY when already explicitly pre-authorized and accessible with existing ordinary test connection: WATER_L01_READONLY_DIAGNOSTICS=1. Default off. Never seek extra privileges, use administrator diagnostics, retry a denied query or bypass restrictions. If unavailable, preserve query markers and classify absence of direct causal proof INCONCLUSIVE; no attribution from elapsed time or a generic 1205/1213. Exact-table 1205 while A is held is recorded for D, not alone counted as proven A blocker.

PASS only B commits before A release, with expected one review/decision and corresponding required audit. Compare exact signed subscriber balances and full ordered row snapshots of subscribers, meters, invoices, readings, ledger, receipts, allocations/revisions, sessions, treasury/transfer/counts/bank/claims, prior audits and unrelated reviews. No new financial posting. FAIL (L01) only when actual filtered wait evidence proves B requesting and A blocking on late_receipt_reviews in this schema. Financial invariant failures are independent failures; setup/worker failure, missing evidence, unavailable diagnostics or timeout without causal attribution are BLOCKED/INCONCLUSIVE (never a pass). If B later commits after A releases, retain outcome and timing; do not certify independence. Release/reap both workers in finally; no stress loops/retries. A successful pair proves only tested workload and plan, not global freedom from deadlocks.

## Report

Record exact SHA, blob, actual test and control outcome, parent counts/assertions/exit/errors/incomplete/skips, MySQL version/socket/database isolation, lockfiles, environment revision, actual index/plan, B SQL and marker order, connection IDs, any directly attributable wait, rollback/commit and cleanup. Redact credentials. Preserve existing V2 62/632, G10 3/3 and prior 137/1219; do not add these new tests to prior counts. If no causal wait is established, keep L01 open rather than request a schema change. Any confirmed lock defect needs separate supervisor scope. No main merge.
