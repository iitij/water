# Permanent close source coverage candidate

Main: 0681c12f47d6b542c35d6d487a2bb810762bfb16.
Accepted journal protection base: ebdf30b0c7216d690cc7b6a9179e07131faf3645, exact CI 20/226.
Candidate: 7623373c855b59f90fd95eec2ca1dde882a3ebae, release/permanent-close-source-coverage, draft PR #153 against main. Status CANDIDATE / RUNTIME_NOT_VERIFIED.

FinancialCloseEvidenceDiagnostic unifies actual document/registry and settlement checks under one scope-first transaction, authenticates a locked super_admin, inventories scope-wide unresolved factual refund claims by account and unposted refunds without netting, checks current subscriber balances only in current month, and compares structural continuity only with an existing prepared/closed snapshot. Explicit blockers for missing writer, independent opening, end-of-period and carry evidence. No synthetic proof binding, no reconstruction, no closure authorization, no API activation. Unsupported registry legs now reported rather than treated as matching verified sources. Tests target two new evidence cases plus five affected existing source cases per engine (14 intended executions). No repeated journal/refund race suites. 
Remaining: exact runtime acceptance, source snapshots based on prospective recorded events and independent opening facts, complete writer fences and true close proof; no historical data repair. No production migration/deployment, no main merge. 
