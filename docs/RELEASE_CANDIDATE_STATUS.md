# Release Candidate Status

**Verdict: READY FOR USER MANUAL TESTING.** This is not production readiness. Real financial use still needs the client decisions and data listed in §3.

## 1. Definition of done

| # | Condition | Status | Evidence |
|---|---|---|---|
| 1 | All mandatory in-scope modules implemented | Met | `FINAL_FEATURE_COMPLETENESS.md`. Items not built are excluded by decision or wait for policy |
| 2 | No known critical application bug | Met | 14 defects found in this release were fixed and re-tested (`FINAL_AUTOMATED_QA_REPORT.md` §4) |
| 3 | Financial posting invariants pass | Met | Balanced double entry, immutability, closed periods, duplicate prevention, maker-checker, Director thresholds: automated, MySQL and browser tests |
| 4 | Full regression passes | Met | 646 of 646 |
| 5 | MySQL concurrency checks pass | Met | 35 of 35; MySQL migration and feature checks 24 of 24, plus 163 tests |
| 6 | Browser UAT passes for mandatory workflows | Met | 27 cases, 350 checks, 0 failed |
| 7 | No unauthorized branch or payroll data exposed | Met, with one open decision | Branch isolation tested by URL and in reports. Management seeing salaries on screen is the current role design, pending a client decision |
| 8 | Every important sidebar page works | Met | Smoke: 688 visits, 0 errors, 0 broken links |
| 9 | PDF, XLSX and CSV exports work | Met | 11 reports × 3 formats in the browser, plus automated tests |
| 10 | Journal-to-report reconciliation | Met | GL, TB, statements, cash books and FRC reconciliation agree in UAT-04, RC-03, RC-13 and `BranchCashLedgerTest` |
| 11 | FRC integration remains functional | Met | Local real mirror unchanged (7 payments, PKR 18,000); synthetic sync, incremental changes, refunds and posting tested |
| 12 | Isolated manual-testing environment ready | Met | `frc_accounting_software_uat` rebuilt to its synthetic baseline; servers start per `tests/Uat/README.md` |
| 13 | Manual testing guide complete | Met | `MANUAL_TESTING_GUIDE.md` |

## 2. Manual testing

| Item | Value |
|---|---|
| URL | `http://127.0.0.1:8010` after starting the servers (`tests/Uat/README.md`) |
| Database | `frc_accounting_software_uat` (synthetic only) |
| Users | `uat.<role>@uat.test`. The password is in `tests/Uat/README.md` |
| Guide | `MANUAL_TESTING_GUIDE.md` |
| Reset synthetic data | `php tests/Uat/setup.php --fresh` |

The development application (`frc_accounting_software`) is not the test environment. It holds the real FRC mirror and must stay free of test entries.

## 3. Real-world activation prerequisites (not done)

These are decisions and data, not development. None of them was faked.

1. Approve and import the Chart of Accounts (proposal in `COA_IMPORT_READINESS.md`: 135 ledgers + 54 headings; heading 215 kept, 21501 excluded).
2. Appoint the real Director with `php artisan app:director-authority grant`.
3. Real bank accounts, cash/bank records per branch, and settlement accounts per payment method (Easypaisa and JazzCash treatment open).
4. GL mappings, including Accumulated Surplus and the service fee income ledgers:
   - Quran Teaching has no ledger in the workbook.
   - New FRC Project Donation also has no ledger.
   - Salary Expense default is undecided.
5. Fee policy (cash or accrual), depreciation policy and payroll rules, each approved by the Director.
6. Opening balances per branch.
7. A usable payroll workbook.
8. Production deployment of the two FRC endpoints and a production integration token.
9. Production hardening (`PRODUCTION_READINESS_CHECKLIST.md`):
   - HTTPS and APP_DEBUG off.
   - Backups with a restore rehearsal.
   - Penetration test and performance test with real volumes.
10. Client decisions still open:
    - Should Management see salary amounts?
    - Should a fee receipt reversal above the threshold need the Director?
    - Cash flow classification of ledgers.
    - Shared-cost allocation.

## 4. Known limitations

- Not built:
  - Bank reconciliation.
  - Payslips and tax or statutory calculation.
  - Asset disposal, transfer and impairment.
  - Petty cash imprest float screen (use a CV journal).
  - Staff advances workflow.
  - Two-factor login.
- Receipts print through the browser; no PDF receipt file is generated.
- PDF exports are plain text layouts; very large ledgers need more PHP memory.
- No per-branch overdraft check on cash or bank.

## 5. Safety confirmations for this release

- Laravel 10 was not modified.
- No production system was touched.
- No real COA import was done.
- No real Director was appointed.
- No real financial posting was made.
- No integration token was rotated.
- No migration was run on the development database in this release.
- `migrate:fresh` and `db:wipe` were never used on real data.
- Test data exists only in the `*_uat` database and in disposable test databases, which were dropped.
