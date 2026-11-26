# WATER accounting candidate V2 — Round 10 integration manifest

**Static G00 composition: PASS. Runtime acceptance awaits the supervisor's freeze.**

Repository `iitij/water`, branch `integration/accounting-candidate-v2`. The frozen V1 seed and initial remote V2 tip were both `f043851ed7d372a1187581e36aa6ac29f3fb8f94`, tree `883f244a538b2c22a0a8d5ad4da84ccfb7a563a7`. The main tip before composition was `c877e9feae9a077d0b8d6e170b5ddeeab20f29f6`. This manifest records the exact V2 composition and its static verification. [Supervisor authorization at 0bb0923](https://github.com/iitij/water/blob/0bb0923def60566211100005666357bd480a237c/docs/coordination/TASK_REGISTER.json) · [Final Team D matrix at c47d9e7](https://github.com/iitij/water/blob/c47d9e794e9ae280117cf43f1b95cfd48bb751dc/docs/accounting/QA_D_ROUND4C_ACCEPTANCE_MATRIX_AR.md)

## Ancestry and resulting composition tree

| Component | Approved source SHA | Merge base with V1 seed | Source commits / paths | V2 merge commit |
| --- | --- | --- | ---: | --- |
| C cumulative obsolete-meter policy, including the Round 9 follow-up | `e79c3b40e764016efb1987160ee9f618b4b9544d` | `f043851ed7d372a1187581e36aa6ac29f3fb8f94` | 2 / 13 | `a928789f3f4e9a1d5b76b88482bea36e037a7ede` |
| B complete P1 corrections | `8f52bdc324eaf266f70bbe3678b3422e48e38e02` | `833cae2d0c97e00604622b365f5efc7a319a8d50` | 5 / 14 | `48dbe588ba9fc38989d5e21ab07569e6acd16d71` |
| Corrected G10 legacy-clean fixture | `123fe5c6935110248c84d0e83e2aa53c886ce066` | `f043851ed7d372a1187581e36aa6ac29f3fb8f94` | 1 / 1 | `c21113f87ab78695385a8a4b6e5cb72dfd5877ef` |

The first-parent order is C, B, G10, then this manifest. Each source appears as the second parent of its merge. The complete new ancestry comprises the eight approved source commits, the three merges, and this documentation commit. ACC10, ACC11, ACC12/13 and V1's D corrections remain in the ancestry.

The composition commit is `c21113f87ab78695385a8a4b6e5cb72dfd5877ef` and its tree is `65ef75a4a33971f1ee2ba354f388887fe885f798`. This manifest is the only file on top of that tree. The enclosing candidate commit and tree are identified by `git rev-parse HEAD HEAD^{tree}` on the verified V2 tip.

## Exact changed paths

The complete tree comparison against V1 found **27 component paths** and **28 paths with this manifest**. The 26 exclusive entries below match the approved Git blobs and modes. Status is relative to V1. The full tree comparison also verified every unaffected entry against V1.

```text
status source  mode   approved blob                            path
M 8f52bdc 100644 a15a640719f295b6b69b44e0b06a0d20177f8a53 app/Services/HistoricalInvoiceCorrectionService.php
M 8f52bdc 100644 48a1eef7928525e2c86caae59d141911e0be8a10 app/Services/PaymentAccountTransferService.php
M 8f52bdc 100644 aa158ae839b99e00fdc7eea540c505f520f1f77e app/Services/PaymentRefundService.php
M 8f52bdc 100644 abed33ef73ed5a90a180b7fc57190c80c2941494 app/Services/PaymentService.php
A 8f52bdc 100644 bcbf8a3efb44aa48422b571716122c7d5322558b app/Support/HistoricalTariffEvidence.php
A 8f52bdc 100644 8d06c32e0c24d33e5f419c1f13963826ddfd2443 app/Support/InvoiceAmounts.php
A 8f52bdc 100644 2c2f5185e7e3d49bac9bb675db51f4b689876a00 app/Support/ReceiptPostingIdentity.php
A 8f52bdc 100644 24ab2de45f377034b048837291ea8df618231b2c docs/accounting/ACC_09_P1_CORRECTIONS_ROUND7_AR.md
A 8f52bdc 100644 864a790fd311561319421abdcfa5b277e41c265b docs/accounting/ACC_09_P1_ROUND5_AR.md
A 8f52bdc 100644 b3b9818cf55be3d5f3b605fcabe370c438e6f404 tests/Feature/Acc09P1ExactMoneyTest.php
M 8f52bdc 100644 e6f60d46eae6d163c62a9f65c9e5b0e6d6a6e13e tests/Feature/FinancialReversalTest.php
A 8f52bdc 100644 835bc32333dc96ffb29f779ff5303628c7e3e44c tests/Unit/acc09_p1_evidence_check.php
A 8f52bdc 100644 26cd4430bba931da85d3c6eae81c5ad4f2c12652 tests/Unit/acc09_p1_invoice_amounts_check.php
M e79c3b4 100644 e842f1de1d046556fa219a51982d8366c8a3dd93 app/Filament/Resources/Meters/Pages/EditMeter.php
M e79c3b4 100644 7acf5dd0484fc0a27b86f990cf17012f87933fc3 app/Http/Controllers/Api/MeterController.php
M e79c3b4 100644 6af2a21e072097119827fcfa1ac8cbfca1ea194f app/Models/Meter.php
M e79c3b4 100644 f8e54384268aab2b558a700a66f8cb5a7893c40e app/Services/FinancialMeterRetirementService.php
M e79c3b4 100644 cfc2e67b6b1ce806c46803458792b2951294140f app/Services/MeterDataPurger.php
M e79c3b4 100644 dfc41728faeecf4403e24351abba7b1c88027bff app/Services/SingleMeterService.php
M e79c3b4 100644 d81d5d0c9788d9e440ab6a28f0f6dca3c3f8feeb database/migrations/2026_11_12_230000_enforce_one_current_meter_per_subscriber.php
M e79c3b4 100644 98269495cab2426fa29962c359eae9bdf9ac6790 database/migrations/2026_11_14_010000_enforce_one_meter_record_per_subscriber.php
A e79c3b4 100644 1940942682b622891cc4b364aa26f6bd5bebf42f tests/Feature/ObsoleteMeterLegacyMigrationTest.php
M e79c3b4 100644 d6b39b31ed975986b47dc224a3ad0ec83fb4a01d tests/Feature/SingleCurrentMeterTest.php
M e79c3b4 100644 3d0e43fe48e8c56dea5fb7bfbbc273ac307f1302 tests/Feature/SingleMeterOfflineReplacementRaceTest.php
M e79c3b4 100644 9cce7cb481ec2a532dc662954ce82bd7df3ffa57 tests/Feature/StrictSingleMeterPurgeTest.php
M 123fe5c 100644 69b3380848b4a98b74553a469d34dd9acb5022fb tests/scripts/mysql_single_meter_smoke.php
M combined 100644 800be9e94911ff2f3b996d11e5eb3a7cab3bbe1e tests/Feature/FinancialConcurrencyTest.php
A manifest 100644 (this file) WATER_INTEGRATION_V2_MANIFEST.md
```

V1's invoice own-debt and UTC status rules, precedence, integer grace and visit settings, scheduler, ACC10 migrations, and ACC12/13 reading-integrity files retain their V1 blobs and modes. The changes to the two existing meter migrations are the approved C cleanup call sites and ordering, with DDL and constraints preserved. G10 changes only its test fixture. V2 adds no schema or API contract changes beyond the approved component scope. The isolated P2 foundation, P3, ACC14 activation, optional closing policy, permanent financial closing, returned-cheque posting, and Team D's latest docs-only delta are excluded.

## Shared concurrency file

Git's merge result was verified as the full final C file, blob `aaaeef6e571d104c050d40f442a371a13dd17611`, with the two exact methods appended from B, blob `af7880994e730b0940e038bbde9048c1a0b5c742`. The V1 baseline blob was `d0742c2c80f124a9eb10a48553ac95683b2caa1e`. The V2 result retains C's setup, isolation guard, worker exit checks, 30-second `WNOHANG` join and cleanup byte-for-byte. The superseded `test_parallel_first_observation_and_replacement_never_erase_committed_usage` is absent. All 20 retained or added methods were compared byte-for-byte against their respective sources, including their assertions.

```text
V1 test_parallel_observations_on_same_date_commit_only_once
V1 test_concurrent_identical_mobile_reading_retries_return_one_observation
V1 test_concurrent_mobile_readings_with_same_uuid_and_different_values_conflict
V1 test_parallel_mobile_readings_on_different_meters_cannot_share_uuid
C test_parallel_first_observation_and_explicit_replacement_discard_only_unbilled_old_usage
C test_parallel_invoice_and_explicit_replacement_preserve_any_committed_financial_document
C test_round9_pending_review_committed_after_snapshot_blocks_meter_deletion
V1 test_parallel_observation_and_invoice_preserve_quantity_and_ledger
V1 test_two_concurrent_invoice_requests_bill_the_reading_once
V1 test_same_sync_uuid_concurrent_replay_creates_one_receipt_and_one_ledger_entry
V1 test_parallel_payments_for_different_subscribers_cannot_share_uuid
V1 test_parallel_distinct_payments_get_unique_receipt_numbers_and_ledger_entries
V1 test_invoice_and_collection_in_parallel_conserve_the_balance
V1 test_parallel_overlapping_tariff_approvals_only_commit_once
V1 test_parallel_invoice_and_tariff_repricing_preserve_pricing_evidence
V1 test_parallel_cash_collection_and_close_preserve_finalized_cash_snapshot
V1 test_concurrent_late_receipt_approvals_cannot_allocate_same_counted_surplus
V1 test_parallel_cash_open_and_stale_close_are_rejected
B test_p1_corrections_concurrent_refund_and_void_cannot_both_commit
B test_p1_corrections_reciprocal_transfers_conserve_both_accounts
```

## Static G00 verification

The isolated checkout was clean on the published private `water` configuration `cecfgver_6b064f42e904819c9ea48827e6603eb6`, observed spec revision **31**, with restricted networking enforced. Git **2.56.1** was used for ancestry and tree verification. PHP **8.4.28** passed `php -l` on all **25** changed PHP files. The diff whitespace check identified one inherited trailing space on line 7 of `docs/accounting/ACC_09_P1_ROUND5_AR.md`; the approved blob was retained exactly, and the check passed for all other paths.

```bash
seed=f043851ed7d372a1187581e36aa6ac29f3fb8f94
git status --porcelain
git log --first-parent --format='%H %T %P %s' "$seed..HEAD"
git diff --name-status "$seed" HEAD
git diff --raw --no-abbrev "$seed" HEAD
git diff --check "$seed" HEAD -- . ':(exclude)docs/accounting/ACC_09_P1_ROUND5_AR.md'
git diff --name-only -z "$seed" HEAD -- '*.php' |
  xargs -0 -n 1 /workspace/scratch/water-runtime/bin/php -l
```

All eight dependency manifests and locks matched V1 by Git blob, mode and SHA-256.

```text
910e320d51f5466a6e22030e2d59d3592496cb8272505313cb9d67be028d8ecd  composer.json
eddd4997b74fdb1d9ca8177cbb320a2c9c97c7ecbf9d809bdbd9a0584503fc0c  composer.lock
1fe487ad38dcb6d977b4fa11aad22d3aa8edd6c8e467b2652634d6b1a5fb314d  mobile app/pubspec.lock
147aa577d6c08d033cb6f66ddffc2ab2bf650f6541f62be6c2d4145a7ec8d8d5  mobile app/pubspec.yaml
8cb5f7d1d2e9d5d5a3a09d64cb0bfbad810c0a799401a7230dbdbfc63b567e58  mobile app/tool/package-lock.json
86714b96a8d93f4102a82c577d6b71aee229c26c7854e6b5687173e401654808  mobile app/tool/package.json
e080253726c42bc8c4fbfe728b9b09419e42650d9dd3e4987ddd2d2b1608a40a  package-lock.json
6806d10f2d2ea5dd0c12b78a19a2b945857a9f092d1f559acdc5ea0eb09abddd  package.json
```

## Accepted prior evidence and remaining gates

| Scoped prior runtime evidence | Tested SHA | Passed | Assertions |
| --- | --- | ---: | ---: |
| Round 8 P1 | `8f52bdc324eaf266f70bbe3678b3422e48e38e02` | 40 | 525 |
| Round 8 meter policy | `3572bf4f9968821651c7621cbce725e7da542788` | 84 | 570 |
| Round 9 meter follow-up | `e79c3b40e764016efb1987160ee9f618b4b9544d` | 13 | 124 |
| **Total** | | **137** | **1,219** |

The accepted scopes had zero failures, errors or skips. The earlier corrected `legacy-clean` scenario recorded its PASS marker and exit 0 on `123fe5c6935110248c84d0e83e2aa53c886ce066`; the original V1 `legacy-clean` failure remains historical evidence. These outcomes belong to their recorded source SHAs. This composition ran no application suites or migrations and used no database or production data.

| Gate | Remaining decision or evidence |
| --- | --- |
| G00 | Static composition checks passed. Independent Team D review and frozen-SHA environment verification remain pending. |
| G01, G03, G05, G06 | After freeze, run only supervisor-approved integration-specific checks justified by composition. Preserve prior scoped evidence and keep restricted checks excluded. |
| G07 | Stored billing ambiguity remains open. Preserve prior reading-integrity evidence and route any new targeted scope through the supervisor. |
| A-C-09-L01 | Operational lock breadth and cross-account contention remain open. The accepted same-account race covers its tested interleaving. |
| G10 | Integrated scenario runs await approval on the frozen V2 SHA. If authorized, use one isolated synthetic MySQL schema per scenario and record its PASS marker and exit code. |
| G02 and G11 | Full Laravel/MySQL regression, Flutter and client acceptance remain held. |
| G04, G08 and G09 | P2/P3 and ACC14, closing, and returned-cheque work require separate authorization. |
| G12 | Hosted CI and hosting evidence remain open, including the unexplained historical CI startup failures. |
| Production | Production acceptance, already-applied migration handling, live cleanup, main merge and deployment remain outside this QA candidate. |

Await the supervisor's freeze of the exact V2 tip before any runtime acceptance. Any later authorized run must record its SHA, environment revision, commands, isolated databases, result counts, assertions, and exits.
