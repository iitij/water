# Signed settlement policy candidate

Base: 3d86441494dcb6e0888bec8a34f63fa0e3ec16d7.
Branch: release/prospective-refund-signed-settlement.
Candidate: 31ea3e32a606a6002f759fad3fed180644ef5d5b.
Draft PR: #148, against release/prospective-refund-renewal-membership, never main.
Run: 45875080946. Jobs: SQLite 140100007525; MySQL 140100006960.
Status: PRODUCT_CANDIDATE / EXACT_CI_STARTED / NOT_ACCEPTED.

Signed source planner/writer now handles singleton posting, singleton nonposting and fully posting groups using signed-v2; prior mixed-v1 remains supported. New direct pending approval, unsigned single posting or nonposting approval and unsigned batch settlement fail closed. Existing immutable settlement replay retains previous validation. Explicit legacy unsigned fixture opt-in is accepted only inside testing with disposable SQLite :memory: or MySQL water_test, absent by default, no environment variable, never accepted in staging or production. It preserves tests of older algorithms but is not production authorization and must not be used to count unsigned paths as operationally accepted.

Four new cases and five directly affected regressions per engine (18 intended case/engine combinations). PR-only workflow with repository/head restriction and exact head checkout avoids inaccessible push-only run listing. Run and both jobs observed in progress; no successful tests or assertion counts confirmed yet. Earlier pending evidence remains pending.

Limits: signed settlement service is internal; public signed API/document onboarding and client contract remain open. Current same-day count and trusted signer prerequisites mean unsupported factual disbursements remain unresolved with outflow fenced, rather than automatically posted or discarded. No live issuer keys, main merge, production change, actual reserve release or real data mutation. Historical remediation remains excluded.

Next: inspect exact job logs, fix only confirmed defects, avoid stacking further product changes before runtime acceptance; review authorized staged entrypoints for signed evidence/settlement and unresolved factual-disbursement handling, then remaining writer fences, permanent closure, Flutter and production.

SQLite raw log verified: exact candidate, PHP 8.4.28, PHPUnit 12.5.29, in-memory database, composer.lock SHA-256 62931ebaf0deb4de748ffefd94264b3de48d15a9e2d1d4a0702f4a1c6cf39335, 9 tests / 236 assertions, job success. MySQL last observed running target step; no result yet.

MySQL raw log verified: exact candidate, mysql:8.0 disposable water_test, PHP 8.4.28, PHPUnit 12.5.29, matching composer.lock SHA-256, 9 tests / 235 assertions. Both jobs succeeded: 18 tests / 471 assertions. Accepted only targeted signed policy and selected affected regressions; earlier pending candidates not implicitly accepted. PR remains draft and unmerged.
