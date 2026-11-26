# Aggregate renewal membership integrity

Base: 2f5a3b161b325d215cc223d5d80f225307bfa9d5.
Branch: release/prospective-refund-renewal-membership.
Candidate: 3d86441494dcb6e0888bec8a34f63fa0e3ec16d7.
Status: PRODUCT_AND_TEST_CANDIDATE / RUNTIME_UNVERIFIED.

Reconstruct count-keyed revision membership from immutable member rows, request and predecessor chains, matching actor/date/evidence/classification, sorted IDs and exact aggregate amount/variance; recompute group hash rather than trusting equal stored hashes. Reject omitted members, partial marker removal, all markers removed in multi-revision groups, and mismatched selection. Verify at group renewal (old and new group), single request inspection, both group readiness paths, document registration and historical inspection, settled group reconstruction/replay and single approval boundary. Original proposal and ordinary single revision formats retained. No schema or route changes.

One new targeted synthetic test corrupts equal hashes, one marker, and both markers with temporary removal of the relevant update trigger on disposable fixtures only; restores the authentic trigger before every service call; asserts rejection on inspection, readiness, mixed posting and evidence registration, unchanged financial/audit snapshots, unresolved claims; restores original evidence and verifies successful readiness. CI configured for five targeted/affected tests on each engine and three MySQL races (13 intended case/engine combinations). GitHub workflow listing returned no PR-triggered runs, no exact runtime results available; do not count tests as passed. Earlier unverified runs remain unverified.

Limits: source review and pushed candidate only; exact runtime, migration guard integration, and performance of count-keyed audit scan not verified. No historical remediation, actual reserve release, production activation, main merge, or real data mutation. Next: obtain exact CI evidence, fix confirmed defects, review signed-source requirements on prior operative single and all-posting paths, then close remaining financial entrypoint and direct-write gaps.
