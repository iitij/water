# Water Round 5 accounting candidate

This is the composition record for the commit carrying this manifest on `integration/accounting-candidate-v1`. The supervisor must freeze the exact branch tip before candidate test execution. Release and main remain on hold.

The frozen ACC09 seed is `833cae2d0c97e00604622b365f5efc7a319a8d50`. The supervisor accepted its scoped results of **196 passed, 0 failed, 0 skipped, 22,005 assertions**, plus **30,048 standalone checks**. Those results apply to the seed. ACC12/13 has independently reviewed scoped evidence, and D recorded three isolated smoke scenario passes. These prior results apply to their recorded source SHAs. [Supervisor register at 7de9049](https://github.com/iitij/water/blob/7de904901779b1c09ee3598c240ed4142acbb9e7/docs/coordination/TASK_REGISTER.json) · [Approved D acceptance matrix](docs/accounting/QA_D_ROUND4C_ACCEPTANCE_MATRIX_AR.md)

## Component ancestry

| Component | Exact source SHA | Merge base with seed | Paths | New source commits |
| --- | --- | --- | ---: | ---: |
| ACC12/13 reading integrity | `2f17b8f2484531e8c48ee2c71cf988762741c7ae` | `539c4ae1f8bba3a6843fc635096d44d9cb76ef06` | 8 | 15 |
| ACC10 user identity | `76eac62805247dcc31a993da307333d8f8ab5b1b` | `c877e9feae9a077d0b8d6e170b5ddeeab20f29f6` | 15 | 2 |
| ACC11 invoice overdue policy | `e940764c5e480d6d3aeb262585171a6b4d945fc4` | `c877e9feae9a077d0b8d6e170b5ddeeab20f29f6` | 9 | 2 |
| D test-only smoke and documentation | `6ba50dda7253e499f95719d19712bb8ceac32cf7` | `c877e9feae9a077d0b8d6e170b5ddeeab20f29f6` | 3 | 6 |

The four ordered two-parent merges are `c133d0c06e55c8cc3d36d792a3845bd4d83452fa`, `88e635f94fc410f8b57406c3efcf7b590f027599`, `325f2bf113d12b7c5e4b90364e8aac6586d894d6`, and `1b9de6c4cec32982d5187e25d0b54e376411ee1d`. Their combined tree before this manifest is `f5a30d4a806472fc369c50188fcaf82201190076`. The ancestry beyond the seed contains the exact 25 approved source commits and these four merge commits, followed by the manifest commit. The seed already inherits `539c4ae` and `a0448f9`. No inherited commits were replayed. The candidate contains no additions from the separate P1/P2/P3, ACC14, closing-policy or return-posting branches.

## Overlap and source review

- `ReadingSettings.php` merged automatically. The result is byte-for-byte the seed's page with the approved ACC11 `integer()`, `minValue(0)`, and `maxValue(365)` rules substituted for `numeric()` on `grace_period`. This retains the Arabic visit labels, help and date semantics, and grace default, requirement and persistence.
- Four C paths also occur in the seed's main-to-seed diff through common ancestry. The seed matches `539c4ae` on those paths, and the final blobs match C on `AuditObservationIntervals.php`, `Reading.php`, `CurrentMeterReadingGuard.php` and `ReadingService.php`. The approved C change checks duplicate latest and immediate predecessor dates and retains the read-only whole-chain audit.
- `Invoice.php`, the Flutter accounting code, and the refresh command match the approved ACC11 blobs. Invoice status uses own debt with precedence paid, overdue, partially paid, issued. Draft, cancelled and soft-deleted invoices are excluded. The grace deadline includes its last UTC calendar day, with the strict latest-invoice-ID option preserved. The refresh command holds the subscriber lock before the invoice lock and changes status only. The daily UTC midnight schedule retains `withoutOverlapping()` and the prior console commands remain present. Runtime scheduler, database mutex and hosting checks are pending.
- The only new migrations are ACC10's `2026_11_25_010000_protect_user_identity.php` followed by `2026_11_25_010100_harden_user_identity_guards.php`. Their order follows the seed's `2026_11_25_000600` migration. Both match the approved blobs. D changes the smoke script, workflow and acceptance documentation. Existing financial constraints are retained.
- `docs/v2/agents/backlog.json` parses and includes one ACC11 entry with its existing verification and owner-acceptance status. The supervisor register remains on its own branch.

## Exact tree and static checks

The seed-to-composition diff contains exactly the union of the **35 approved component paths**, with the manifest as the 36th path on the candidate. Source blobs and file modes match the corresponding approved commits on all component paths except the verified combined settings page. Verify with `git diff --name-status 833cae2d0c97e00604622b365f5efc7a319a8d50 HEAD` and `git log --first-parent --format='%H %P %s'`. All changed PHP files passed `php -l`, the backlog JSON and three-scenario workflow matrix parsed, and the diff whitespace check passed outside five Markdown hard-break lines retained from C's exact documentation blob. Static checks did not run the application, migrations or tests.

Dependency manifests and lockfiles are byte-for-byte the seed's. SHA-256 hashes are:

```text
eddd4997b74fdb1d9ca8177cbb320a2c9c97c7ecbf9d809bdbd9a0584503fc0c  composer.lock
e080253726c42bc8c4fbfe728b9b09419e42650d9dd3e4987ddd2d2b1608a40a  package-lock.json
1fe487ad38dcb6d977b4fa11aad22d3aa8edd6c8e467b2652634d6b1a5fb314d  mobile app/pubspec.lock
```

The exact changed paths follow. The status letters are relative to the frozen seed.

```text
M	.github/workflows/mysql-single-meter-check.yml
M	app/Console/Commands/AuditObservationIntervals.php
A	app/Console/Commands/RefreshInvoiceStatuses.php
M	app/Filament/Pages/ReadingSettings.php
M	app/Filament/Resources/Users/Pages/EditUser.php
M	app/Filament/Resources/Users/Tables/UsersTable.php
M	app/Filament/Resources/Users/UserResource.php
M	app/Http/Controllers/Api/AuthController.php
M	app/Http/Controllers/Api/UserController.php
M	app/Models/Invoice.php
M	app/Models/Reading.php
M	app/Models/User.php
M	app/Services/CurrentMeterReadingGuard.php
M	app/Services/ReadingService.php
A	app/Support/ReadingQuantity.php
A	database/migrations/2026_11_25_010000_protect_user_identity.php
A	database/migrations/2026_11_25_010100_harden_user_identity_guards.php
A	docs/accounting/ACC_12_13_ROUND4A_AR.md
A	docs/accounting/QA_D_ROUND4C_ACCEPTANCE_MATRIX_AR.md
M	docs/v2/agents/backlog.json
A	docs/v2/qa/ACC_11_OVERDUE_POLICY_AR.md
M	mobile app/lib/features/invoices/domain/accounting/invoice_accounting.dart
M	mobile app/lib/features/users_roles/data/models/rbac_models.dart
M	mobile app/lib/features/users_roles/data/repositories/rbac_repository_impl.dart
M	mobile app/lib/features/users_roles/domain/entities/rbac_entities.dart
M	mobile app/lib/features/users_roles/presentation/cubit/users_cubit.dart
M	mobile app/lib/features/users_roles/presentation/pages/users_management_screen.dart
M	mobile app/test/accounting/invoice_accounting_test.dart
A	mobile app/test/users_roles/deleted_record_integrity_test.dart
M	routes/console.php
A	tests/Feature/Acc12Acc13ReadingIntegrityTest.php
A	tests/Feature/DeletedRecordIntegrityTest.php
A	tests/Feature/InvoiceOverduePolicyTest.php
A	tests/Unit/ReadingQuantityTest.php
M	tests/scripts/mysql_single_meter_smoke.php
A	WATER_INTEGRATION_MANIFEST.md
```

## G00–G12 acceptance requirements

Every candidate run must record the frozen SHA, environment version, exact command, engine, exit code, counts and raw evidence. Use independent synthetic databases and run engine suites sequentially. Run bounded concurrency by itself and Flutter with one worker. The approved detailed cases and execution order are in D's matrix.

| Gate | Required candidate evidence | Disposition at composition |
| --- | --- | --- |
| G00 environment and ancestry | Verify frozen SHA, clean tree, approved ancestry and paths in a fresh published environment. Verify tool versions, locked installs, platform checks and lock hashes. | Static composition checks complete. Fresh frozen-SHA run pending. |
| G01 baseline QA-D-001/002 | Run inventory and retired billing schema checks and Flutter dashboard debt regression. Confirm retired paths remain absent and the expected debt is 800. | Candidate runtime pending. |
| G02 ACC01–08 | Run full ordinary Laravel SQLite and MySQL sequentially on separate synthetic databases, then bounded financial concurrency separately. Verify no doubled debt or receipts and preserved accounting history, with explicit counts and skips. | Pending freeze. |
| G03 ACC09 canonical | Run the five canonical cases plus exact money contracts, historical pricing, quick receipt, allocation, reversal, subscriber account and payment API checks. Verify cent conservation, bounds and atomic rollback, UUID replay and historical pricing. | Scoped seed acceptance recorded. Candidate runtime pending. |
| G04 remaining ACC09 | Verify P1/P2/P3 exact-money paths, historical correction, refund/transfer/void, cash and treasury bounds, wide sums, API compatibility, Flutter and offline behavior after approved integration. | Deferred and excluded from this candidate. |
| G05 ACC10 identity | Run ordinary integrity, API, Filament, financial authorization and sorting checks for actor deactivation, roles, tokens and sessions. Document engine-specific outcomes and separate permitted hosting evidence. | Scoped prior evidence only. Restricted probes stay out of this run. |
| G06 ACC11 aging | Run focused PHP and Flutter status tests at the same instant across time zones. Verify visit settings, integer 0 and 365 boundaries and rejection of fractions and out-of-range values, UTC midnight, exemptions, partial overdue, no monetary writes and scheduler mutex on a supported database store. | Scheduler and hosting evidence open. Candidate runtime pending. |
| G07 ACC12/13 chronology and quantity | Verify final reading fixtures on SQLite and MySQL, including duplicate latest and predecessor dates, zero/max/negative/sub-cent quantities, no partial writes, immutable audit, bounded concurrency, offline behavior and financial/reading boundaries. Track cross-row database guarantees separately. | Scoped C12-R4C-01 acceptance. Final candidate matrix pending. |
| G08 ACC14 issuance | Require approved all-writer N01–N12 inventory, atomic cutover, high-water and rollback plan, UTC semantics and race/replay/restore evidence. | Deferred. No activation. |
| G09 closing and return posting | Require separately approved closing policy and durable close guards, immutable snapshots, bank and ledger evidence, audit and revision. Review A01-10 through A01-13 before any real return posting. | Deferred. Optional policy excluded and posting inactive. |
| G10 MySQL 1553 smoke fixture | Run `fresh`, `legacy-clean` and `legacy-financial` on three separate synthetic MySQL schemas. Require scenario-specific PASS markers and exit 0. Verify final FK and unique constraints and financial rejection and rollback. | Prior three scenario passes recorded by D. New script needs an explicit scenario and `WATER_SINGLE_METER_TEST_DB=water_test`. Cloud's existing `water-qa mysql-smoke` expects the earlier invocation and marker. Use the approved workflow or equivalent isolated scenario execution after freeze. |
| G11 client and API | Run Flutter analysis and locked tests with concurrency 1, device/web/offline checks and Vite. Verify API values and client compatibility. | Pending freeze. Device evidence open. |
| G12 CI and hosting | Inspect exact-SHA CI logs and artifacts. Establish cron, cache and permitted database privilege evidence via authorized procedures. Report skipped checks and separate any specialized security acceptance. | Earlier CI startup failure lacks an annotation-level diagnosis. Hosting and release gates remain open. |

Start with G00, then G01 and G10, followed by focused G03/G07/G05/G06, the G02 engine suites and separate concurrency, G11, and G12. G04, G08 and G09 await separate approval. No full regression suite or restricted probe was launched during composition. No production data or deployment was involved.
