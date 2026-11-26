# Main integration — 2026-11-26

User explicitly authorized merging completed work so far into main.
Previous main: c877e9feae9a077d0b8d6e170b5ddeeab20f29f6.
Backup branch: backup/main-before-accounting-integration-20261126 (previous main).
Integrated exact candidate: a149a58dfa8e7150d1705bb06411af7cff1aa899 from release/prospective-refund-signed-api.
PR #150 merged successfully; verified main now 0681c12f47d6b542c35d6d487a2bb810762bfb16. Comparison candidate -> main: ahead 1, behind 0, changed files 0. Integration preserved 738 commits, 482 changed files, +51313/-5471. No coordination branch merge.

Verified exact raw CI evidence: signed policy run 45875080946, SQLite 9/236, MySQL 9/235; signed API run 45875654169, SQLite 2/69 (job 140101939644), MySQL 2/69 (job 140101939964). Total for these two targeted runs: 22 tests, 609 assertions. Locked composer SHA-256 62931ebaf0deb4de748ffefd94264b3de48d15a9e2d1d4a0702f4a1c6cf39335. All four jobs successful. Prior unverified membership/renewal race and full integrated regressions are not implicitly accepted.

Code-only integration at user's direction, not production readiness certification. Candidate switches default false; no production deployment or migration executed by this work. Hosting auto-deploy status not independently verified. Do not activate candidate financial features until all writer, closure, client, runtime privileges and production acceptance gates are satisfied. Next development branch must start from verified main head (or later main after rechecking coordination), not obsolete release/prospective-void-retry. Continue outstanding focused evidence, source custody/transport hardening, writer fences, permanent closure, Flutter compatibility and production checks. No historical remediation requested.
