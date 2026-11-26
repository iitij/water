# Permanent close source coverage candidate

Main: 0681c12f47d6b542c35d6d487a2bb810762bfb16.
Accepted journal protection base: ebdf30b0c7216d690cc7b6a9179e07131faf3645, exact CI 20/226.
Candidate: 7623373c855b59f90fd95eec2ca1dde882a3ebae, release/permanent-close-source-coverage, draft PR #153 against main. Status CANDIDATE / RUNTIME_NOT_VERIFIED.

FinancialCloseEvidenceDiagnostic unifies actual document/registry and settlement checks under one scope-first transaction, authenticates a locked super_admin, inventories scope-wide unresolved factual refund claims by account and unposted refunds without netting, checks current subscriber balances only in current month, and compares structural continuity only with an existing prepared/closed snapshot. Explicit blockers for missing writer, independent opening, end-of-period and carry evidence. No synthetic proof binding, no reconstruction, no closure authorization, no API activation. Unsupported registry legs now reported rather than treated as matching verified sources. Tests target two new evidence cases plus five affected existing source cases per engine (14 intended executions). No repeated journal/refund race suites. 
Remaining: exact runtime acceptance, source snapshots based on prospective recorded events and independent opening facts, complete writer fences and true close proof; no historical data repair. No production migration/deployment, no main merge. 

Exact run 45877315088 at 7623373: both engines 7 tests, 80 assertions, 1 error each (6 passed each). Same failure test_round140_detects_correctly_hashed_wrong_date_and_split_refund_operation, gated_refund_writer_required. The fixture incorrectly creates a legacy refund after registering its original receipt. Corrected fixture ordering only at e2179d96761cfd14f1e675d42096653588d8e456: deliberately create legacy refund and treasury posting before registering the original receipt, then retain negative wrong-date and split-operation proof. No product guard weakened. Synchronize CI run 45877495476 in progress; acceptance pending. No additional product scope stacked until verification.

Corrected run SQLite exact raw log verified: e2179d96761cfd14f1e675d42096653588d8e456, matching composer SHA-256, isolated in-memory DB, 7 tests / 88 assertions, job 140108170002 success. MySQL job 140108169373 pending. The full seven-case filter automatically reran on PR synchronization; no prior unrelated suite was repeated.

Corrected MySQL exact log verified: 7 tests / 88 assertions, job 140108169373, same candidate and composer hash. Both engines accepted targeted 14/176. Remains unmerged. No closure authorization.
