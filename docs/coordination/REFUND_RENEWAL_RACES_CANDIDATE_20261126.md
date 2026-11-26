# Aggregate renewal concurrency and renewed settlement

Base: 1e975ae77967111c62bae56a769f6deadd30211b.
Branch: release/prospective-refund-renewal-races.
Candidate: 2f5a3b161b325d215cc223d5d80f225307bfa9d5.
Status: TESTS_AND_WORKFLOW_IMPLEMENTED / RUNTIME_UNVERIFIED.

Only two files changed relative to base: test suite and incremental CI. Existing shared race helper gains optional renewal count argument; old paths remain unchanged. Three new independent MySQL scenarios hold the actual financial scope row until both worker connections contend: identical retry (one creation, identical result), competing proposers (one winner), zero-posting competing proposers. Assert one two-member revision group, matching predecessor IDs, group hash, account count, exact variance, unchanged financial snapshots and continued ordinary outflow fence. The extended existing renewal test posts only the posting-required six minor units, verifies exact subscriber and treasury legs, zero nonposting effects, approved source and proposal IDs, one batch, and replay after configured keys are removed.

CI targets the extended scenario on SQLite and MySQL and three race scenarios on MySQL (five intended case/engine combinations). No new product code. GitHub commit workflow listing returned empty (PR-only listing); remote machine offline. Exact logs, syntax lint, and run results unavailable; no claimed passes. Prior unverified renewal, characterization, and mixed settlement race results remain pending and must not be silently treated as accepted.

Next: obtain exact CI evidence, address confirmed failures only, then verify group revision membership at the read/settlement boundary and earlier operative source authenticity policy. Continue remaining writer fences, permanent closure, Flutter compatibility and production readiness. No historical remediation, actual reserve release, main merge, real data or production activation.
