# Permanent close journal protection candidate

Base main: 0681c12f47d6b542c35d6d487a2bb810762bfb16.
Branch release/permanent-close-journal-protection; exact head ebdf30b0c7216d690cc7b6a9179e07131faf3645; draft PR #152; run 45876679734. Status CANDIDATE / CI_STARTED / NOT_ACCEPTED.

Database triggers reject UPDATE/DELETE of close commands and snapshots, closed period UPDATE/DELETE, SQLite REPLACE via all unique keys; permit the coordinator's precise FROZEN-to-CLOSED projection after approval snapshot insert. Live trigger metadata and restrictive FK checks fail closed in coordinator. Gate separately refuses OPEN projections with terminal evidence (closure or approve command). New migration installs guards without modifying existing records, downgrade requires reviewed recovery. Two new test methods, directly affected eight existing coordinator tests including deliberately privileged corruption diagnostic (remove only relevant guard on disposable DB, restore before service call). Both engines target same class; prior accepted refund suites not repeated. Existing refund membership and concurrency accepted 14/556 at run 45876152273.

Limits: append-only guard is not proof of INSERT authorization, verified source coverage, production grant restrictions, scope-wide raw write protection, or operational close readiness. No production proof-source binding, no historical reconstruction, no live migration, no production activation, no merge. Next: verify exact logs, fix confirmed failures, verify remaining source and writer coverage; implement trusted prospective close proof without inventing historical balances. 

Verified exact logs both engines: SQLite 10/118, MySQL 8.0 10/108, total 20/226; jobs 140105413213 and 140105412683, matching composer SHA-256 62931ebaf0deb4de748ffefd94264b3de48d15a9e2d1d4a0702f4a1c6cf39335. Targeted acceptance only, PR remains draft and unmerged.
