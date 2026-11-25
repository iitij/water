# WATER V3 integration manifest

Status: frozen for integration testing only; no production acceptance.

- V2: 40a216bec7ebd540b2d127bea4cdd9180cf0c52a
- P2 product/test head: 1a55c40e0219706d5942aa525c82660e218e1aa8 (I01–I06 independently scoped runtime accepted).
- G07 source: 0583e2161a491445c2e597793cfa91377cb7a87f (four targeted cases per engine accepted).
- Composed product/test head: 87b1b06f478ddb8b5bfb6fac8464d1e89363c124.
- GitHub compare V2→composed: 40 paths, zero behind. P2→composed: exactly four paths, zero behind; no path overlap with P2 changes. Exact copied G07 blobs verified after each commit:

| Path | Git blob |
|---|---|
| app/Services/InvoiceService.php | 639512486b367fda958a2492875df40043f7a66e |
| app/Support/ObservationBillingChain.php | e436b563f51587859a956054b05fa8f5065acb25 |
| tests/Feature/V2StoredObservationBillingTest.php | 674200ead6bd79fe22e249ba8f37189667d90b25 |
| tests/Unit/g07_observation_chain_check.php | 8ec8b1027af298b6a8a8b7baf6bb89b66cf42366 |

Composition is exact content import rather than merge ancestry for G07. Existing source heads remain unchanged. No CI-only workflow commits from I02–I06 included in composed product/test head.

## Scoped runtime evidence

I01: 127 PDO checks; I02: 28 tests/494 assertions; I03: 20/424; I04: 26/404; I05: 24/213; I06: 16/208; G07 fix: 8/680. These are individual source evidence, not proof of combined runtime correctness. Original G07 baseline failures preserved. L01 remains inconclusive, not passed. Main remains c877e9feae9a077d0b8d6e170b5ddeeab20f29f6. No historical data modifications.

## Gates

Integration G02 backend regression on isolated SQLite and MySQL, exact checkout and locked dependencies; retain real failures/skips, do not weaken existing constraints or repeat accepted individual suites absent changed-path necessity. Separate client/Flutter/export, remaining report and closing/returned cheque policy, numbering, historical G07 anchors, L01, hosted/production acceptance remain open. Do not merge main.
