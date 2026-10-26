# Milestone 8 QA Report

**Verdict: READY FOR CONTROLLED UAT.** Not production ready: financial posting stays switched off until the client approves the Chart of Accounts, appoints a Director and approves the posting policies (see `CLIENT_POLICY_DECISIONS.md`).

Every result below was produced by a command that was actually run. Nothing marked "not performed" was attempted.

## 1. Verification results

| Check | Result |
|---|---|
| Full automated suite (Pest, PHP 8.3.14, in-memory SQLite) | **497 tests passed, 0 failed, 11,895 assertions** |
| Tests re-run after the last code changes (dashboard masking, sequence locking) | 130 tests in the affected files passed |
| MySQL 9.1 / InnoDB concurrency harness, 8 parallel PHP processes | 23 of 23 checks passed on a fresh database; 6 consecutive runs after the final fix passed |
| Laravel Pint | Passed |
| Blade compilation (`view:cache`) | Passed |
| Vite production build | Passed |
| Routes | 310 registered |
| Migrations on the development MySQL database | 4 new forward-only migrations applied; none pending |
| Browser / device testing | Not performed during Milestone 8. Performed later in the final integration: see `UAT_EXECUTION_REPORT.md` |
| Penetration testing | **Not performed** |

Development data after all work: 0 accounts, 0 journals, 0 transactions, 0 employees, 0 payroll runs, 0 assets, 0 stock movements, 0 budgets, 0 Directors; 6 branches and 9 services unchanged. The disposable concurrency database was dropped.

## 2. Reports delivered

| Area | Reports | Exports |
|---|---|---|
| Statements | Income & Expenditure (with optional comparative period), Balance Sheet (with equation check), Branch-wise Income & Expenditure, Service analysis | CSV, XLSX, PDF |
| Cash | Cash Flow Statement (direct method), Receipts & Payments | CSV, XLSX, PDF (cash flow only when it reconciles) |
| Ledgers | General Ledger, Trial Balance, Cash Book, Bank Book, receipt/payment/expense/donation registers, Vendor Ledger, Payables & Aging | CSV, XLSX, PDF |
| Budgets | Budget vs Actual (version and hierarchy selection), branch and service summaries | CSV, XLSX, PDF |
| Fixed assets | Register, category summary, branch summary, net book value, depreciation schedule, posting history, missed-period exceptions | CSV, XLSX, PDF |
| Inventory | Stock balance, movement register, branch stock, receipts, issues, issued-to, transfers, low stock, adjustment audit | CSV, XLSX, PDF |
| Payroll | Register, employee history, run summary, accrual summary, payment status, deductions, import errors | CSV, XLSX, PDF (needs the sensitive and export permissions) |
| Audit | Read-only audit log viewer with filters and record detail | none (by design) |
| Dashboard | Real cash, bank, income, expense, payables, budget use, pending approvals, recent journals, setup status | — |

## 3. Calculation methodology

- **Source of truth.** Every financial figure comes from posted journal lines. Journals in the posted or reversed state both count, each on its own date, so an original and its reversal net to zero. Draft, submitted and approved-but-unposted journals never count.
- **Exactness.** Sums are taken in SQL over DECIMAL columns and carried in exact decimal arithmetic. No floating-point arithmetic is used for figures.
- **Hierarchy.** Only ledgers carry postings; parent accounts are rollups, so nothing is counted twice.
- **Balance Sheet.** Year-end closing entries are not assumed: a computed "Accumulated surplus/(deficit) not yet closed" line is shown. The equation is checked and any difference is shown, never plugged. A missing opening-balance journal is flagged.
- **Cash Flow.** Direct method. Cash ledgers are those linked to cash and bank accounts plus the petty cash mapping. Each cash movement is attributed to its counter ledgers and classified only by explicit configuration; unclassified ledgers make the statement "not final". Transfers between cash ledgers are excluded. The result is reconciled to the ledger cash balance and any difference is shown.
- **Receipts & Payments.** Cash basis, separate from the accrual statement. Transfers and accrued items are excluded; a reversal reduces the side of its original.
- **Branch and service.** Branch columns sum to the total shown. Service results are direct income and expenses only; lines without a service are shown as "Unallocated / shared" and are not distributed.
- **Budget vs Actual.** Actual uses the same posted rule; purchase order commitments are a separate column and never part of Actual.

## 4. Exports

- PDF files are real PDF documents produced by a small built-in writer (no new dependency). Browser printing is not presented as PDF.
- Every export carries the organisation name, report title, filters, currency, generation time and user, and the same totals as the screen.
- Text cells are protected against spreadsheet formula injection; XLSX text cells are stored as text and figures as numbers.
- Exports are streamed, marked `no-store`, never written to public storage, scoped to the user's branches, and recorded in the audit log.
- Tests read the exported files back and check the figures.

## 5. Defects found and fixed

### Found by the MySQL concurrency harness (the SQLite tests could not show these)

| # | Severity | Defect | Status |
|---|---|---|---|
| M1 | Critical | Five simultaneous payments of 40 against a bill of 100 were all accepted (200 paid against 100). | Fixed and re-verified on MySQL: exactly 2 accepted |
| M2 | High | The same flaw allowed billing above a purchase order. | Fixed and verified: exactly 2 of 5 bills of 40 against 100 accepted |
| M3 | High | Two simultaneous branch transfers crashed with a server error when the destination stock row did not yet exist. | Fixed and verified |
| M4 | Medium | The same stock issue submitted twice at once was recorded twice. | Fixed with an idempotency key; verified |
| M5 | Medium | The first purchase order or voucher of a new sequence, and occasionally later ones, failed for one of several simultaneous users with a database deadlock. | Fixed and verified in 6 consecutive runs |

The root cause of M1 and M2 was reading related totals from a stale database snapshot after taking a lock. All "lock, then add up" sites were reviewed and changed to locking reads, including payroll salary payments and depreciation runs.

### Found by the cross-module code review

| # | Severity | Defect | Status |
|---|---|---|---|
| A1 | High | A rejected payment could be resubmitted after another payment had already settled the same bill or payroll run, paying twice. | Fixed, tested |
| A2 | High | A purchase bill could be reversed while a payment against it was still in progress. | Fixed, tested |
| A3 | High | The journal screen could reverse journals created by payments, bills, payroll or depreciation (leaving the source "posted"), and could reverse a reversal. | Fixed, tested |
| A4 | High | Another branch's draft journal could be edited by submitting an accessible branch. | Fixed, tested |
| A5 | Medium | A journal's submitter could approve it. | Fixed, tested |
| A6 | Medium | The last editor of an opening balance could approve it. | Fixed, tested |
| A7 | Medium | Any payment type could be attached to a bill of any branch. | Fixed, tested |
| A8–A10 | Low | Duplicate audit entry, an error page on the donation category form, slow list pages. | Fixed, tested |
| L1 | Medium | The Payables & Aging page failed with a server error whenever an outstanding bill existed. | Fixed |
| L2 | Medium | A cash or bank account's ledger, branch and type could be changed after it had been used, removing its ledger from the cash controls. | Fixed: frozen once used |
| L3 | Low | Purchase bill submissions and rejections were audited under the permission name, so a rejection looked like an approval. | Fixed: `submitted`, `approved`, `rejected` |
| L4 | Low | Sign-in, sign-out and failed sign-in were not audited. | Fixed |
| L5 | Low | The Chart of Accounts CSV export did not neutralise cells starting with a tab or carriage return. | Fixed |
| L6 | Low | The dashboard showed payroll accrual totals to users without sensitive payroll access. | Fixed |

## 6. Security regression

All earlier controls were re-tested and remain in force: Director-only approval above the threshold for purchase orders, payments, paid expenses and petty cash; Director-only threshold changes; Director appointment only by an existing Director or the console; no combined Administrator and Director account; delegation disabled; maker-checker; branch scoping; the manual cash/bank journal guard; posted-record immutability; closed-period protection; duplicate-posting protection; split-payment warnings; Director approval for bills above their purchase order; payroll privacy; export scoping; audit immutability.

## 7. Known limitations and open points

- **Browser QA** was not part of this milestone. It was executed afterwards in Chrome on synthetic data (`UAT_EXECUTION_REPORT.md`).
- **Management sees payroll amounts on screen** because the Management role holds `payroll.view_sensitive`; it cannot export them. Confirm or remove (client decision).
- **General ledger and journal screens show payroll accrual totals** to anyone allowed to view journals, as any ledger would. Only the dashboard masks them.
- **Concurrency coverage.** Verified on MySQL for stock issues and transfers, idempotent issues, purchase order and voucher numbering, journal double-posting, vendor payments and purchase order billing. Payroll salary payments and depreciation runs use the same locking pattern and their unique constraints were confirmed on MySQL, but they were not raced.
- **Deadlock retries.** Contended writers retry up to three times; under heavier contention than 8 simultaneous users a request can still fail and must be resubmitted. Data stays consistent.
- **PDF layout** is plain text in Helvetica; very wide tables use condensed column sets in PDF, while CSV and XLSX carry every column.
- **Exports are built in memory**; very large ledgers need more PHP memory.
- **Audit gaps remaining:** profile and password changes are not audited.
- Not built: asset disposal, payslips, tax calculation, bank reconciliation, two-factor authentication.

## 8. How to repeat the checks

```
php vendor/bin/pest --compact
php vendor/bin/pint --test
php artisan view:cache
npm run build
php tests/Concurrency/run.php --fresh --rounds=5 --drop
```

The concurrency harness only ever uses a database named `<development database>_concurrency_test`, refuses to run against any other name, and `--drop` removes it at the end.
