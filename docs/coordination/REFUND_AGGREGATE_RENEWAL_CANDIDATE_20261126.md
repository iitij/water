# Aggregate refund proposal renewal candidate

Base: 9eb9d40889a0803b3b8da0ebcfb368d6aab7e782.
Branch: release/prospective-refund-aggregate-renewal.
Candidate: 1e975ae77967111c62bae56a769f6deadd30211b.
Status: IMPLEMENTED_CANDIDATE / RUNTIME_NOT_VERIFIED.

Internal staging/testing-only renewResolutionGroup: scope-first transaction, all unresolved members of account, distinct exact predecessor selection, one later accepted observation, same existing classifications, aggregate variance equals negative posting subtotal, prior evidence verification, independent proposer, append-only predecessor hashes and shared group hash, exact retry, no partial individual revision for accounts with competing incidents. No new financial effect, spending authority, or closure. Fresh document and source identities are still required after revision. Existing single revision path extracted into private helper. No migrations or public endpoint.

Targeted CI configuration: three new cases and one changed-path single-revision regression on each engine (8 intended case/engine combinations). Tests include injected second revision audit failure, unchanged snapshots, exact replay, stale IDs and new signed evidence, full-posting and zero-posting groups, wrong count and independence. No runtime or syntax-lint evidence obtained; workflow listing timed out and combined status returned empty. Earlier mixed concurrency and characterization runs remain unverified. Do not equate candidate with accepted implementation.

Limits: same-day and newer accepted count required, classifications unchanged, no direct same-count source replacement, no recovery of already partially revised groups, no new route, no real issuer provisioning. Group revision contention, full writer/entrypoint inventory and authenticity of earlier operative paths remain open. Existing historical remediation exclusion unchanged. No main merge, real data or production activation.

Next: obtain exact CI logs; fix only confirmed failures; add MySQL concurrent competing group renewals and end-to-end renewed evidence posting, then review remaining writer fences and closure.
