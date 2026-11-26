# Signed cash refund API transport

Base: 31ea3e32a606a6002f759fad3fed180644ef5d5b, verified targeted 18 tests / 471 assertions (run 45875080946), not merged.
Branch: release/prospective-refund-signed-api.
Candidate: a149a58dfa8e7150d1705bb06411af7cff1aa899.
Draft PR: #149 against the signed settlement candidate, never main.
Status: IMPLEMENTED_CANDIDATE / CI_NOT_VERIFIED.

Six authenticated staging/testing-only JSON endpoints for document record, independent byte review, source attestation, signed readiness, settlement and group renewal. Strict top-level and member allowlists, positive JSON integers, canonical base64 up to 5 MiB decoded, unique UUID/proposal/attestation selections, no query or file fields, actor derived from Sanctum, super_admin authorization and existing service independence/gates. No new writer, schema, or financial amount overrides. Signed document bytes processed for hash; no deliberate storage of originals in audit. No source private keys accepted. Existing unsigned routes retain prior fail-closed policy.

Two new end-to-end feature tests on each engine (4 intended case/engine combinations): binary document, independence, permission and payload rejection, source revocation, exact replay, staging-only restriction, group renewal and stale proof rejection. Exact head PR-only CI, no repeat of accepted base tests. At initial check no run ID yet. No passed assertions claimed for this candidate.

Limitations: UI/Flutter integration, source key provisioning, original document custody/retention and request logging/body-size hardening need independent review. Existing same-day and newer count constraints preserved. Earlier unverified group race and membership corruption tests remain pending. No historical remediation, real data changes, main merge or production activation.

Next: obtain exact CI results; fix confirmed failures; run outstanding changed-path tests on a dedicated traceable PR; review remaining writer fences and permanent closure, then Flutter/production.

Exact PR CI observed in progress: run 45875654169, SQLite job 140101939644, MySQL job 140101939964. No outcome claimed yet.
