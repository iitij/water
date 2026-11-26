# Mixed refund renewal gap — source review and characterization

Base: 9eb9d40889a0803b3b8da0ebcfb368d6aab7e782.
QA branch: qa/refund-mixed-renewal-gap.
Exact candidate: a3fd91ae3b9bc188e8858e6d3b05997bb08977e5.

Status: SOURCE_GAP_IDENTIFIED / TARGETED_CI_UNVERIFIED. Earlier mixed concurrency CI is also unverified: the available commit workflow listing filters PR-triggered runs; no run ID recovered, and the remote device is offline. Do not infer failure or success. Do not repeat accepted baseline.

Source findings:
- CompletedCashRefundRequest::recordResolutionDocument forbids replacing a document for the same proposal, and attestResolutionSource forbids replacing the attestation for that document. Pending revocation is fail-closed.
- proposeResolution revisions require a later accepted count and variance of exactly the individual claim (negative claimed amount if posting; otherwise zero). A common mixed group count with shortage of six minor units cannot revise a nonposting member expecting zero. Mixed readiness requires a common current count and exact aggregate variance.
- Test-only characterization checks these blockers, unchanged financial/audit snapshots, no refund or revision, two unresolved claims totalling ten minor units, and ordinary outflow blocked. Two isolated engine jobs configured; raw logs not yet available. Passing characterization proves current rejection, not that renewal is implemented.

Next implementation: atomic scope-first group revision with complete exact current member selection, common fresh accepted count, aggregate expected variance equal to negative posting subtotal, explicit predecessor hashes, independent reviewers, no partial member revisions on failure, and renewed unique documents and source attestations bound to new proposals. Preserve historical evidence; current trust only for pending use. Validate zero-posting, mixed and all-posting semantics separately; test stale IDs, duplicate and omitted members, count mismatch, failure rollback, replay, competing revisions and stale source selection. Never authorize spending or posting by revision alone.

No product code, main, real data or production changes. Historical remediation remains excluded.
