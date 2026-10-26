# Final Automated QA Report — Release Candidate

Date: 15 October 2026. PHP 8.3.14, MySQL 9.1 (InnoDB), Chrome (headless, DevTools protocol). Every result below comes from a command that was actually run on the final code. Nothing marked as passed was skipped.

## 1. Results

| Check | Command | Result |
|---|---|---|
| Full automated suite (Pest, in-memory SQLite) | `php vendor/bin/pest --compact --parallel` | **646 passed, 0 failed, 13,121 assertions** |
| MySQL migration and feature checks (disposable databases only) | `php tests/MySql/run.php` | **24 of 24 checks**; **163 feature tests passed on MySQL** (2,105 assertions) |
| MySQL concurrency harness (disposable database) | `php tests/Concurrency/run.php --fresh --rounds=3 --drop` | **35 of 35 checks** |
| Browser workflows (isolated UAT database) | `node tests/Uat/browser/workflows.mjs` | **14 of 14 cases, 141 checks, 0 failed, 0 console errors** |
| Browser release cases | `node tests/Uat/browser/release.mjs` | **13 of 13 cases, 209 checks, 0 failed, 0 console errors, 0 defects** |
| Browser smoke (every sidebar page, 8 roles; desktop for all, tablet and phone for two roles) | `node tests/Uat/browser/smoke.mjs` | **688 page visits: 0 HTTP errors, 0 server-error pages, 0 JS errors, 0 horizontal overflow; 1,510 links, 0 broken; 17 of 17 forbidden URLs refused** |
| Laravel Pint | `php vendor/bin/pint --test` | Passed |
| Blade compilation | `php artisan view:cache` | Passed |
| Vite production build | `npm run build` | Passed |
| Routes | `php artisan route:list` | 336 |
| Development database migrations | `php artisan migrate:status` (read-only) | None pending; no migration added in this release |

The disposable MySQL databases (`*_migration_test`, `*_mysql_test`, `*_guard_probe`, `*_concurrency_test`) were dropped after each run. The development database was only read: 0 accounts, 0 journals, 0 fee postings, and 7 payments and 6 enrollments in the FRC mirror, unchanged.

## 2. What the browser tests cover

**Workflows (UAT-01 to UAT-14):**
- Approval controls and their audit.
- FRC sync, totals, filters and scoping.
- Settlement mapping and cash-basis fee posting (School Readiness to its own ledger).
- Ledger impact and fee reversal.
- Manual journal guard.
- Director threshold on payments.
- PO, bill, partial payment and over-payment refusal.
- Donation.
- Inventory issue and duplicate submission.
- Policy blocks.
- Exports.
- Authorization by URL.
- Phone layout.

**Release cases (RC-01 to RC-13):**

| Case | Covers |
|---|---|
| RC-01 | COA import through the UI: upload, review with reasons, parent order, exclusion, confirmation |
| RC-02 | One cash ledger shared by branches; same-branch duplicate refused; cash/bank conflict refused |
| RC-03 | Journal create, approve, post, GL, TB, reversal; contra (CV) journal |
| RC-04 | Bank receipt with printable receipt, expense, petty cash, registers, Cash/Bank Book account filter |
| RC-05 | Inventory receive, issue, return, transfer, adjustment approval, stock balance |
| RC-06 | Asset, depreciation blocked by policy, Director approval (UAT only), posting, double posting refused |
| RC-07 | Budget create, submit, approve, revise, Budget vs Actual |
| RC-08 | Payroll components, employee, Excel import with row exclusion, run, approval, policy block, accrual, payment, over-payment refused, payroll visibility by role |
| RC-09 | FRC incremental sync of a new Quran Teaching payment (stays unposted: no fee income ledger) and a refund of a posted payment (flagged for review, journal untouched) |
| RC-10 | Branch isolation and read-only roles on the newer modules |
| RC-11 | CSV, XLSX and PDF for 11 reports (signatures checked) |
| RC-12 | Phone and tablet layout of the newer pages |
| RC-13 | Year-end closing: blockers, draft discarded through the UI, closing posted for both branches, surplus to the Accumulated Surplus ledger, FY 2027 Trial Balance carrying balances |

## 3. Concurrency (MySQL, 8 parallel processes)

| Area | Result |
|---|---|
| Inventory issues and transfers | Never negative; exact winners |
| Idempotent issue | Recorded once |
| PO and voucher numbering | Distinct and consecutive |
| One journal posted by four users | Posted once |
| Vendor payments and PO billing | Never over the outstanding or approved amount |
| **Payroll salary payments** (new) | Never above unpaid net salary; losers leave no transaction |
| **Depreciation run posting** (new) | Posted once; one journal; no duplicate asset line per period |
| **Depreciation run preparation** (new) | One run per branch and period |
| Unique indexes on MySQL | Present |

## 4. Defects found in this release and fixed

| # | Defect | Fix | Proof |
|---|---|---|---|
| 1 | No year-end closing; the next year's Trial Balance dropped balance-sheet balances | Year-end closing service, screens and permissions; Trial Balance carry-forward; performance reports ignore closing journals | `YearEndClosingTest` (19), RC-13 |
| 2 | Payroll deduction could point at a cash/bank ledger (bank outflow without payment approval) | Refused on save and at posting | `PayrollTest` |
| 3 | Receipts/payments could use a cash/bank ledger as contra (bank credit without approval) | Refused; transfers are CV journals | `OperationalContraLedgerTest` |
| 4 | Maker-checker ignored whoever edited a draft (journals, POs, budgets, payroll lines) | Editors taken from the audit log are treated as makers | `DraftEditorApprovalTest`, `PayrollTest` |
| 5 | Salary amounts in payroll import errors shown to non-sensitive users | Amounts removed from error text | `PayrollImportTest` |
| 6 | Opening-balance import uploader could approve the batch; apply needed only import permission | Uploader is a maker; apply also needs prepare; applied under lock | `OpeningBalanceTest` |
| 7 | User and role administration not limited to the actor's branches or privileges | Scoped; cannot grant what you do not hold; Administrator protected | `AccessManagementTest` |
| 8 | Two GET pages wrote to the database (COA import review/export) | Read-only; classification on POST | `AccountImportReadOnlyTest` |
| 9 | Payroll import "Exclude/Return selected rows" failed ("The action field is required") because the double-submit guard dropped the button value | The guard now carries the button's name and value | RC-08 (UI path) |
| 10 | A draft that can never be submitted blocked the year-end closing permanently | "Discard Draft" with a reason, audited; posted journals cannot be discarded | `JournalEngineTest`, RC-13 |
| 11 | `/fiscal-years` returned 500 (two Blade directives written without a space) | Corrected; same pattern fixed on the journal list | `FiscalYearManagementTest`, smoke |
| 12 | No printable receipt for cash/bank receipts | Receipt page for posted cash and bank receipts, branch-checked | `OperationalFinanceTest`, RC-04 |
| 13 | Profile, password change and password reset not audited | Audited without values | `ProfileTest`, `PasswordResetTest` |
| 14 | Expense and Donation registers not in the sidebar | Links added | smoke |

## 5. Observations, not defects

- **Management sees salary amounts on screen.** The Management role holds `payroll.view_sensitive`; this is an open client decision. Exports and posting are refused for that role.
- **Duplicate partial salary payments.** Two identical *partial* salary payments sent at the same moment both become drafts. The net-salary cap holds, and both still need maker-checker approval.
- **Old payroll import rows.** Rows imported before this release could still contain amounts in their stored error text. There are none in the development or UAT database.

## 6. How to repeat

See `tests/Uat/README.md` for the UAT servers. Then run:

```
php vendor/bin/pest --compact --parallel
php tests/MySql/run.php
php tests/Concurrency/run.php --fresh --rounds=3 --drop
node tests/Uat/browser/workflows.mjs
node tests/Uat/browser/release.mjs
node tests/Uat/browser/smoke.mjs
```

Feature tests refuse any database other than in-memory SQLite or a disposable `*_mysql_test` database (`tests/TestCase.php`).
