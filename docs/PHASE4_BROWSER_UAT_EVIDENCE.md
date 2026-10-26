# Phase 4 — Browser UAT Evidence (Opening Balance maker / approver / poster)

Date of UAT: 2026-10-20
Environment: isolated QA browser server `http://127.0.0.1:8020` (PHP built-in server, guarded router `tests/Qa/browser-uat-server.php`)
Database: disposable MySQL 9.1.0 database `frc_acct_qa_mysql_test` (restricted account, no access to development, UAT or Laravel 10 databases)
Data: synthetic only (5 `@qa.test` users, "QA Branch One" / "QA Branch Two", 22 "QA …" accounts, FY 2026)

No passwords, password hashes, application keys or database secrets are recorded in this document.

## Result

| Scenario | Result |
|---|---|
| A — Maker → Administrator approval → independent poster (QA Branch One) | **PASS** — JV-2026-00001 |
| B — Maker → Approver → approver loses branch access → posting blocked → Return to Draft → fresh approval → independent poster (QA Branch Two) | **PASS** — JV-2026-00002 |

Read-only integrity verification after UAT: **13/13 PASS** (each batch posted with exactly one journal; maker, approver and poster are three different users; batches and journals balanced; only JV-2026-00001 and JV-2026-00002 exist; Trial Balance 150,000.00 / 150,000.00; no reversals; no imports; data synthetic).

## Opening balance batches

| Batch | Branch | Lines | Total | Prepared by | Approved by | Posted by | Journal |
|---|---|---|---|---|---|---|---|
| #1 | QA Branch One | Dr 11102 Cash in Hand 100,000.00 / Cr 31101 Capital Fund 100,000.00 | 100,000.00 | qa.maker | qa.admin | qa.poster | #1 JV-2026-00001 |
| #2 | QA Branch Two | Dr 11201 Bank Current Account 50,000.00 / Cr 21101 Trade Creditors 50,000.00 | 50,000.00 | qa.maker | qa.reviewer (fresh approval) | qa.poster | #2 JV-2026-00002 |

Both journals: opening date 2026-01-01, source `opening_balance`, one journal per batch (`source_key` `opening_balance:1` and `opening_balance:2`), lines equal the header totals.

## Trial Balance (posted)

| Account | Debit | Credit |
|---|---|---|
| 11102 QA Cash in Hand - Branch Funds | 100,000.00 | |
| 11201 QA Bank Current Account | 50,000.00 | |
| 21101 QA Trade Creditors / Suppliers | | 50,000.00 |
| 31101 QA Capital Fund | | 100,000.00 |
| **Total** | **150,000.00** | **150,000.00** |

## Audit trail (business events)

| # | Event | Actor | Detail |
|---|---|---|---|
| 27 | opening_balance.draft_saved (Batch 1) | qa.maker | total 100,000.00, no posting |
| 29 | opening_balance.approved (Batch 1) | qa.admin | |
| 32 | journal.posted (Journal 1) | qa.poster | JV-2026-00001 |
| 33 | opening_balance.posted (Batch 1) | qa.poster | approved_by qa.admin |
| 36 | opening_balance.draft_saved (Batch 2) | qa.maker | total 50,000.00, no posting |
| 39 | opening_balance.approved (Batch 2) | qa.approver | |
| 40 | user.access_updated (qa.approver) | qa.admin | branches changed to QA Branch One only (Branch Two removed) |
| 45 | opening_balance.returned (Batch 2) | qa.reviewer | original approver qa.approver, `approval_was_valid=false`, approved → draft, reason "Previous approver lost branch access. Fresh approval required." |
| 46 | opening_balance.approved (Batch 2) | qa.reviewer | fresh approval |
| 49 | journal.posted (Journal 2) | qa.poster | JV-2026-00002 |
| 50 | opening_balance.posted (Batch 2) | qa.poster | approved_by qa.reviewer |

Audit rows 1–26 are fixture setup; the remaining rows are `auth.login` / `auth.logout` for the five QA users. 50 audit rows in total. No application error log (`laravel.log`) or PHP error log was written during UAT.

## Supporting automated evidence

- MySQL regression on `frc_acct_qa_mysql_test`: 130/130 PASS.
- MySQL concurrency harness on `frc_acct_qa_concurrency_test`: 87/87 PASS (5 rounds).
- Final SQLite regression (in-memory, 2026-10-20): 874 tests — 873 passed, 1 skipped (MySQL-only), 0 failed, 15,250 assertions.

## Limitations

1. The blocked posting attempt in Scenario B is shown on screen and enforced in the service, but a refused attempt writes no audit row; the audit shows the invalid approval through the `opening_balance.returned` entry (`approval_was_valid=false`).
2. Forged-request rejection (posting by the approver, self-approval by the maker, repost of a posted batch) is covered by automated tests (OpeningBalanceSeparation, OpeningBalanceReturn, concurrency harness), not separately recorded in this browser session.
3. Branch access was removed by replacing the approver's branch with QA Branch One (the user form keeps at least one branch); the effect on Branch Two is the same.
4. Login throttling was not testable: the QA server uses `CACHE_STORE=array`.
5. Scenario A relies on Administrators holding `opening_balances.approve`; whether Administrators should approve at all is a pending client decision.
6. Synthetic data only; amounts below the PKR 150,000 Director threshold. Opening balances are not subject to that threshold in the current design.
7. The QA database still holds the UAT records as evidence. Re-running the fixture script wipes it.
