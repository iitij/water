# Permanent close — pending payout carry

Main 0681c12f47d6b542c35d6d487a2bb810762bfb16. Accepted held rollforward base d291e10061d8e872aa8bdedce77943913c05a0d2, run 45878572191, 6 tests / 128 assertions.
Candidate c02571bc02fc6ae1e6457cfb2987491a7f37a4a5, release/permanent-close-payout-carry, draft PR #156. Status CANDIDATE / RUNTIME_NOT_VERIFIED. CI run 45878993175 queued.
Adds period opening/closing pending payout reservation, authorized and cancelled amounts, per-assignment pending conservation, checks no pending exceeds identified liability. Approval itself does not discharge liability or move cash; later cancellation excluded from prior month. Same-day payout authorization requirement preserved. Tests cover cross-month cancellation and fresh authorization/payment, exact single cash effect and replay, prior cutoff stability, negative orphan batch approval with no requests, and affected earlier rollforward case. Three tests per engine (6 intended runs), no unrelated suite repeated. No success claimed yet.
Remaining: runtime verification, independent closing evidence and writer protection inventory, actual close proof, Flutter, release. No historical repair, production migration/activation, main merge or deployment.

Accepted exact CI c02571bc: SQLite 3/63 (140113217732), MySQL 3/63 (140113218194), total 6/126; run 45878993175, matching locked dependencies. Remains unmerged.
