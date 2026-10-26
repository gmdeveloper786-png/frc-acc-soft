# Manual Testing Guide — FRC Accounting (Release Candidate)

This guide is for one complete manual test cycle on the **isolated UAT environment**. Everything below uses synthetic data. Never test on the development or a production database. Never enter real salaries, bank details or client data.

Mark each check **Pass** or **Fail**. For every Fail, follow §5.

## 1. The UAT environment

| Item | Value |
|---|---|
| Application | `http://127.0.0.1:8010` |
| Database | `<DB_DATABASE>_uat` (for this project `frc_accounting_software_uat`). The scripts refuse any other name |
| FRC source | Synthetic FRC API on `http://127.0.0.1:8011` with invented branches, services, enrollments and payments. The real FRC software is not used |
| Users | `uat.admin`, `uat.accountant`, `uat.cashier`, `uat.approver`, `uat.director`, `uat.board` (Management), `uat.auditor`, `uat.branchb` (Accountant, Branch B only), all `@uat.test`. The password is in `tests/Uat/README.md`. It is not repeated here |
| Synthetic Director | `uat.director` holds Director authority **in the UAT database only** |
| Synthetic chart | Codes 1–51106 named "UAT …" (including 11204 petty cash, 11205 staff advances, 31102 accumulated surplus, 41103 day programme fees); cash/bank accounts UAT Cash A/B, UAT Bank A/B; mappings set |

### Start, reset and stop

Follow `tests/Uat/README.md`:

1. Build the UAT database from scratch (synthetic only): `php tests/Uat/setup.php --fresh`.
2. Start the two servers with the PowerShell block in the README. The environment variables are set only in that window.
3. Step 1 already loads synthetic master data for every module (see `tests/Uat/README.md`), for example:
   - funds GEN/DNR/ZKT/GRT/CAP and GRT-01;
   - a petty cash float for Branch A;
   - payroll components and three employees;
   - inventory items with opening stock;
   - an active asset;
   - a draft budget;
   - draft transactions.

   The ledger has no posted entries and all posting policies are unapproved. For the bank reconciliation, run `php tests/Uat/make-fixtures.php` to create the sample statement in `storage/uat/fixtures/`. Do not run the browser scripts before manual testing.
4. Stop: close both `php -S` processes.

**Reset only synthetic data:** run step 1 again. It drops and recreates `*_uat` only and never touches the development database.

### Logging in and switching roles

- Open `http://127.0.0.1:8010/login` and sign in as one role.
- To switch roles, use **Sign out** from the user menu.
- To keep two roles open at the same time (for example maker and checker), use a second browser profile or a private window.

## 2. Roles at a glance

| Role | Typical rights |
|---|---|
| Administrator (`uat.admin`) | Setup, users, roles, settings. Not a Director |
| Accountant (`uat.accountant`) | Prepare and post entries, mappings, imports, fee posting |
| Cashier (`uat.cashier`) | Create receipts and payments; cannot approve; no setup, payroll or journals |
| Approver (`uat.approver`) | Approves at or below the threshold (PKR 150,000) |
| Director (`uat.director`, synthetic) | Approves above the threshold; changes approval controls and policies |
| Management (`uat.board`) | Read-only reports; sees payroll amounts on screen (current role design), cannot export payroll |
| Auditor (`uat.auditor`) | Read-only, including the audit log |
| Branch B Accountant (`uat.branchb`) | Same as Accountant but only UAT Branch B |

## 3. Module checks

Each block lists the menu, the role to use, the steps with sample values, and what you should see.

### 3.1 Authentication and profile

| | |
|---|---|
| Menu | Login page; user menu → Profile |
| Role | any |
| Steps | 1. Sign in with a wrong password three times. 2. Sign in correctly. 3. Profile: change the display name. 4. Change the password (then change it back). |
| Expected | Wrong password is refused; correct password opens the Dashboard; profile and password changes show a success message. Audit Log (as auditor) lists `profile.updated` and `profile.password_changed` with no password values |

- [ ] Pass  - [ ] Fail

### 3.2 Dashboard

| | |
|---|---|
| Menu | Dashboard |
| Role | admin, then board, then cashier |
| Expected | Figures come from posted journals only. Payroll figures are masked for users without sensitive payroll access. No error on an empty ledger |

- [ ] Pass  - [ ] Fail

### 3.3 Users, roles and branch access

| | |
|---|---|
| Menu | Administration → Users; Roles & Permissions |
| Role | admin |
| Steps | 1. Create user `uat.test1@uat.test`, role Cashier, branch UAT Branch A. 2. Edit and deactivate the user. 3. Try to give a user the Director role from this screen. |
| Expected | User saved and audited; an inactive user cannot sign in. Director cannot be granted here (only by an existing Director or the console). Administrator and Director cannot be held by the same account |

- [ ] Pass  - [ ] Fail

### 3.4 Approval controls and policies

| | |
|---|---|
| Menu | Administration → Approval Controls |
| Role | admin (expect 403), then director |
| Steps | 1. As admin open the page. 2. As director change "Payment Director Approval Threshold" from 150,000 to 160,000 with reason "UAT threshold test". 3. Change it back. |
| Expected | Admin: 403. Director: saved with an audit entry showing old and new values and the reason. A reason shorter than 10 characters is refused |

- [ ] Pass  - [ ] Fail

### 3.5 Fiscal years, periods and year-end closing

| | |
|---|---|
| Menu | Accounting Setup → Fiscal Years; Accounting Periods |
| Role | accountant / admin |
| Steps | 1. FY 2026 is open with 12 open periods. 2. Close January 2026, then try to post a journal dated 15 Jan 2026. 3. Reopen January. 4. Year-end closing (FY 2026 → Year-end closing): read the preview per branch. Post it only at the end of the test cycle, with a reason. 5. Create FY 2027 and open its Trial Balance. |
| Expected | Posting into a closed period is refused. The closing preview lists income and expense ledgers per branch and the net to "UAT Accumulated Surplus". After posting, income and expense for 2026 are zero and FY 2027's Trial Balance shows the carried-forward balance-sheet balances and balances |

- [ ] Pass  - [ ] Fail

### 3.6 Chart of Accounts and COA import

| | |
|---|---|
| Menu | Accounting Setup → Chart of Accounts; Chart of Accounts → Import |
| Role | admin |
| Steps | 1. View the synthetic chart and its hierarchy. 2. Import a small test workbook (synthetic codes, same columns as the client workbook). 3. On the review screen, approve two rows with a reason, exclude an inter-unit row, and try to approve a ledger before its parent. 4. Confirm the import. |
| Expected | Preview creates no accounts. Approving a child before its parent is refused. Excluded rows are never imported. A flagged row cannot be approved without a reason. After confirmation the approved accounts appear with exactly the workbook codes and names |

Do **not** import the real client workbook into the UAT database for approval decisions; real approval is a separate, client-authorised step.

- [ ] Pass  - [ ] Fail

### 3.7 GL Mapping and Setup Readiness

| | |
|---|---|
| Menu | Accounting Setup → GL Mapping; Setup Readiness |
| Role | admin |
| Steps | 1. Check every control mapping: Accounts Receivable, Accounts Payable, General Fee Income, Petty Cash, Salary Expense, Salary Payable, Accumulated Surplus. 2. Service-specific fee income: Speech Therapy → 41102, School Readiness → 41103, Quran Teaching → *not set*. 3. Try to map Fee Income to a liability ledger. |
| Expected | Mappings save with an audit entry. A wrong account type is refused. The Quran Teaching selector says "Not set: manual fee receipts use General Fee Income; FRC payments stay unposted". Setup Readiness lists what is still missing |

- [ ] Pass  - [ ] Fail

### 3.8 Cash & Bank accounts (shared organisational ledger)

| | |
|---|---|
| Menu | Finance → Cash & Bank |
| Role | admin |
| Steps | 1. Add a cash account "UAT Cash A2" for **UAT Branch B** on ledger 11101 (already used by UAT Cash A). 2. Add another cash account for Branch B on 11101. 3. Add a bank account on ledger 11101. |
| Expected | Step 1 saved (one ledger, one account per branch). Step 2 refused: "This branch already has a cash or bank account on this ledger…". Step 3 refused: "already used by cash accounts". Each account shows its own **Posted balance** |

- [ ] Pass  - [ ] Fail

### 3.9 Journal vouchers: create → approve → post → GL → TB → reverse

| | |
|---|---|
| Menu | Journals → Journal Entries; General Ledger; Trial Balance |
| Role | accountant (maker), approver (checker), accountant (post) |
| Steps | 1. New journal, UAT Branch A, date today: Dr 51104 UAT Utilities 1,000.00 / Cr 21101 UAT Trade Creditors 1,000.00. Save, submit. 2. As accountant try to approve (refused). 3. As approver approve. 4. As accountant post. 5. General Ledger for 51104. 6. Trial Balance. 7. Reverse the journal with a reason. 8. Try a journal Dr 51104 / Cr 11101 (cash). 9. Try a contra journal Dr 11201 Bank / Cr 11101 Cash, 500.00, voucher type CV. 10. Create another draft and use **Discard Draft** with a reason. |
| Expected | Voucher number JV-2026-…; GL shows the line with running balance; TB debit = credit. Reversal creates a linked opposite journal; both stay visible. Step 8 is refused (manual journals cannot pay out of cash/bank). Step 9 is allowed (transfer between cash and bank). Step 10 removes the draft and records `journal.discarded` in the Audit Log; posted journals have no Discard button |

- [ ] Pass  - [ ] Fail

### 3.10 Cash and bank receipts

| | |
|---|---|
| Menu | Finance → Cash Receipts; Bank Receipts |
| Role | cashier (create), accountant (post) |
| Steps | 1. Cash receipt: Branch A, UAT Cash A, 2,500.00, from "Synthetic Client", income 41101. 2. Post. 3. Print receipt. 4. Try a receipt whose contra ledger is a bank ledger. |
| Expected | CR voucher posted; Cash Book shows it; "Print receipt" shows the voucher number and amount. Step 4 is refused (cash↔bank transfers are CV journals) |

- [ ] Pass  - [ ] Fail

### 3.11 Cash and bank payments, high-value approval

| | |
|---|---|
| Menu | Finance → Cash Payments; Bank Payments |
| Role | accountant, approver, director |
| Steps | 1. Cash payment 150,000.01 from UAT Cash A to 51101 UAT Supplies; submit. 2. Approver and admin try to approve. 3. Director approves. 4. Accountant confirms disbursement and posts. 5. Payment of exactly 150,000.00 approved by the approver. |
| Expected | Above the threshold only the Director can approve ("Awaiting Director approval"); at the threshold the Approver can. CP voucher posted after disbursement |

- [ ] Pass  - [ ] Fail

### 3.12 Expenses and petty cash

| | |
|---|---|
| Menu | Finance → Expenses; Petty Cash; Reports → Expense Register |
| Role | cashier / accountant, approver |
| Steps | 1. Expense 3,000.00 to 51104, submit, approve, post. 2. Petty cash 800.00 (no vendor needed), approve, post. |
| Expected | Both appear in the Expense Register; petty cash credits the petty cash ledger 11204 |

- [ ] Pass  - [ ] Fail

### 3.13 Vendors, purchase orders, bills, vendor payments, payables

| | |
|---|---|
| Menu | Purchasing → Vendors; Purchase Orders; Purchase Bills; Vendor Payments; Reports → Payables & Aging |
| Role | accountant, approver, director |
| Steps | 1. PO to UAT Medical Supplies Co, Branch A, 1 × 200,000.00 (51101); submit. 2. Approver tries to approve; Director approves. 3. Create bill from the PO; approve; post. 4. Vendor payment 50,000.00; post. 5. Try paying 150,000.01. 6. Try a second bill above the PO's remaining value. |
| Expected | PO above threshold needs the Director. Bill outstanding becomes 150,000.00 after the partial payment. Over-payment and over-billing are refused with the reason shown. Payables & Aging lists the outstanding bill |

- [ ] Pass  - [ ] Fail

### 3.14 Donations

| | |
|---|---|
| Menu | Finance → Donation Categories; Donations; Reports → Donation Register |
| Role | accountant |
| Steps | 1. Check categories General, Zakat, New FRC Project Donation each have a ledger (UAT only). 2. Donation 5,000.00, General, UAT Cash A; post. 3. Print acknowledgment. |
| Expected | DR voucher; acknowledgment page; listed in the Donation Register and in Income & Expenditure under 41201 |

- [ ] Pass  - [ ] Fail

### 3.15 Inventory

| | |
|---|---|
| Menu | Inventory → Items; Receive Stock; Issue Stock; Stock Returns; Branch Transfer; Stock Adjustments; Stock Balances |
| Role | accountant / admin |
| Steps | 1. Use the seeded item UAT-INV-001 "UAT Gloves" (50 on hand in UAT Store A), or create a new item. 2. Receive 10. 3. Issue 4. 4. Return 1. 5. Transfer 2 to Branch B. 6. Issue 20 (more than on hand). 7. Adjustment −1 with approval by another user. |
| Expected | For UAT-INV-001: A = 50 + 10 − 4 + 1 − 2 − 1 = 54, B = 2 (for a new item: A = 4, B = 2). Over-issue refused with "Insufficient stock". Inventory never posts to the General Ledger |

- [ ] Pass  - [ ] Fail

### 3.16 Fixed assets and depreciation

| | |
|---|---|
| Menu | Fixed Assets; Asset Categories; Depreciation Runs; Asset Register; Fixed Asset Reports |
| Role | accountant, approver, director |
| Steps | 1. Category: asset 12101, accumulated depreciation 12102, expense 51103, life 60 months. 2. Asset 120,000.00, in service 01 Jan 2026; activate. 3. Depreciation run October 2026, Branch A: prepare, submit, approve, try to post. 4. Director approves "Depreciation Policy Approved" in Approval Controls (UAT only). 5. Post; try to post again. |
| Expected | Posting blocked while the policy is unapproved. After approval, one JV is posted; the asset register shows accumulated depreciation; a second post is refused |

- [ ] Pass  - [ ] Fail

### 3.17 Budgets and Budget vs Actual

| | |
|---|---|
| Menu | Budgets; Budget vs Actual; Branch/Service Budget Summary; Budget Utilisation |
| Role | accountant (maker), approver (checker) |
| Steps | 1. Budget FY 2026, Branch A: 51104 Utilities 12 × 1,000.00, 51101 Supplies 12 × 5,000.00. 2. Submit; approver approves. 3. Revise (new version), change one month; submit, approve. 4. Budget vs Actual. |
| Expected | The maker cannot approve their own budget. The revision creates version 2 and keeps version 1. Actuals come from posted journals only |

- [ ] Pass  - [ ] Fail

### 3.18 Employees, payroll import and payroll runs

| | |
|---|---|
| Menu | Payroll → Employees; Payroll Components; Payroll Import; Payroll Runs; Payroll Reports |
| Role | accountant, approver, director; then cashier and board |
| Steps | 1. Component "Basic" (earning). 2. Employee UAT-E-001, Branch A, basic 50,000.00 (synthetic). 3. Payroll Import: upload a synthetic workbook, map columns, review errors, confirm. 4. Payroll run October 2026: validate, submit, approve. 5. Try to post. 6. Director approves "Payroll Calculation Policy Approved" (UAT only). 7. Post (accrual), then Pay from UAT Bank A. 8. Try to pay more than the net. 9. Try mapping a deduction component to a cash or bank ledger. |
| Expected | Posting blocked until the policy is approved. Accrual Dr 51102 / Cr 21102; payment clears Salary Payable. Over-payment and a cash/bank deduction ledger are refused. Cashier: no payroll access (403). Management sees amounts on screen but cannot export. Import error messages show no salary amounts to users without sensitive payroll access |

- [ ] Pass  - [ ] Fail

### 3.19 Opening balances

| | |
|---|---|
| Menu | Accounting Setup → Opening Balances |
| Role | accountant (prepare), approver (approve), accountant (post) |
| Steps | Synthetic batch for Branch A: Dr 11101 10,000.00 / Cr 31101 10,000.00. Prepare, approve with a second user, post. |
| Expected | Unbalanced batches are refused; the preparer cannot approve; posting creates one journal. **Real opening balances are never entered here** |

- [ ] Pass  - [ ] Fail

### 3.20 Books, registers and statements

| | |
|---|---|
| Menu | Reports → Cash Book; Bank Book; registers; Statements → Income & Expenditure; Balance Sheet; Branch-wise I&E; Service Analysis; Cash Flow Statement; Receipts & Payments |
| Role | accountant, then board, then uat.branchb |
| Steps | 1. Cash Book: filter by one cash account. 2. Balance Sheet for Branch A, then consolidated. 3. Branch-wise I&E. 4. Service Analysis. 5. Repeat 1–2 as `uat.branchb`. |
| Expected | Branch figures add up to the consolidated figure, with no double counting when two branches share a cash ledger. Balance Sheet shows the equation check. Branch B user sees only Branch B |

- [ ] Pass  - [ ] Fail

### 3.21 Exports

| | |
|---|---|
| Role | accountant |
| Steps | On Trial Balance, General Ledger, Income & Expenditure, Balance Sheet, Cash Flow, Payables & Aging, Budget vs Actual, Asset Register, Stock Balances and FRC Reconciliation, download CSV, Excel and PDF |
| Expected | Each opens in its program, matches the screen totals and contains only branches you may see. Cells starting with `=`, `+`, `-` or `@` are neutralised |

- [ ] Pass  - [ ] Fail

### 3.22 FRC integration and fee posting

| | |
|---|---|
| Menu | FRC Integration → Financial Integration; Source Payments; Unmapped Payments; Sync History; Sync Exceptions; Fee Reconciliation; Fee Settlement Mapping; Fee Posting Preview; Posted Fee Receipts |
| Role | accountant; director for the policy |
| Steps | 1. Manual full sync (confirm the dialog). 2. Source Payments: paid 18,000.00, cash 11,000.00, online/bank 7,000.00, pending verification 1,500.00. 3. Run the sync again: no duplicates. 4. Settlement mapping Branch A: cash → UAT Cash A, card/bank transfer/Easypaisa → UAT Bank A. 5. Director approves the fee policy (UAT only). 6. Fee Posting Preview; post the eligible payments. 7. Reconciliation. 8. Reverse one posted receipt with a reason. |
| Expected | Pending, rejected and cancelled-enrollment payments are never posted. A Quran Teaching payment (if present in the synthetic source) stays unposted with "has no fee income ledger". School Readiness credits 41103. Each payment posts once (FR voucher). Reconciliation reports "Reconciled" when every eligible payment is posted once |

- [ ] Pass  - [ ] Fail

### 3.23 Audit log and settings

| | |
|---|---|
| Menu | Audit Log; Administration → Settings |
| Role | auditor; admin |
| Expected | Every action above appears with actor, time and branch; no passwords or tokens appear; entries cannot be edited. Settings save with an audit entry; protected approval settings are not editable here |

- [ ] Pass  - [ ] Fail

### 3.24 Authorization and branch isolation (direct URLs)

| | |
|---|---|
| Role | cashier, board, `uat.branchb` |
| Steps | Paste URLs copied from the admin session for: a Branch A journal, a Branch A asset, a Branch A payroll run, Approval Controls, `/users`, a payroll export |
| Expected | 403 or 404, never the record |

- [ ] Pass  - [ ] Fail

### 3.25 Phone and tablet

Repeat a few screens (Dashboard, Cash Receipts list, a journal, Fee Posting Preview, Budget) at phone width (390 px) and tablet width (768 px) using the browser's device mode.

**Expected:** no sideways scrolling of the page, the menu opens and closes, and buttons are reachable.

- [ ] Pass  - [ ] Fail

### 3.26 Funds, grants and the restricted-fund rule

| | |
|---|---|
| Menu | Funds & Grants → Funds; Fund-wise I&E; Fund Balances; Grant Utilisation |
| Role | accountant; auditor for read-only |
| Steps | 1. Open Funds: GEN, DNR, ZKT, GRT, CAP and GRT-01 exist; the rule card says **Always enforced**. 2. Expense 1,000.00 to 51101, fund GRT-01, post. 3. Expense to 51102 Salaries with fund GRT-01. 4. Expense to 51101 with fund ZKT. 5. Journal Dr 51101 / Cr 21101 with fund GRT-01 on the debit line. 6. Donation to the Zakat category. 7. Fund-wise I&E and Grant Utilisation for FY 2026. |
| Expected | Step 2 posts. Step 3 is refused ("not a permitted charge for restricted fund GRT-01"). Step 4 is refused ("Restricted fund ZKT has no permitted ledgers configured…"). Step 5 follows the same rule. Step 6 records the donation in ZKT (receiving money is not a charge). Fund-wise totals add up to the plain Income & Expenditure; grant utilisation shows amount, spent and remaining for GRT-01. There is no way to switch the rule off |

- [ ] Pass  - [ ] Fail

### 3.27 Petty cash imprest

| | |
|---|---|
| Menu | Petty Cash → Petty Cash Imprest |
| Role | cashier (vouchers), accountant (replenish), approver (journal approval) |
| Steps | 1. Open the Branch A float (20,000.00). 2. Post petty cash vouchers totalling 3,500.00. 3. Back on the float: balance and "replenishment due" 3,500.00. 4. Request a replenishment. 5. Request a second one. 6. Submit, approve and post the replenishment journal. 7. Imprest statement and its exports. |
| Expected | The replenishment is a **draft CV journal** (Dr petty cash / Cr UAT Bank A) that goes through normal maker-checker; nothing posts by itself. A second open replenishment is refused. After posting, the float balance is back to 20,000.00 |

- [ ] Pass  - [ ] Fail

### 3.28 Bank reconciliation

| | |
|---|---|
| Menu | Bank Reconciliation → Bank Statements; Bank Reconciliation |
| Role | accountant (prepare), approver (approve) |
| Steps | 1. Post a few bank entries on UAT Bank A (a bank receipt and a bank payment). 2. Import a statement for UAT Bank A (CSV or Excel). Map the columns. Use the sample `uat-bank-statement-sample.csv` or your own lines. 3. Import the same file again. 4. New reconciliation at the statement date: auto-match, then match the rest manually. Ignore or clear the remaining items, or record bank charges through a normal bank payment. 5. Submit. 6. Approve as the same user, then as the approver. 7. Try to change a match after approval. 8. Print/export the reconciliation statement. |
| Expected | The duplicate file is refused. The screen shows: statement balance + uncleared deposits − unpresented payments = book balance. Submit is allowed only at a difference of exactly 0.00. The preparer cannot approve. An approved reconciliation is locked |

- [ ] Pass  - [ ] Fail

### 3.29 Payslips

| | |
|---|---|
| Menu | Payroll → Payroll Runs → (approved run) |
| Role | accountant (sensitive payroll access); cashier and board for access checks |
| Steps | After 3.18 (run approved or posted): open a line's payslip; then "Print all payslips". |
| Expected | Each payslip shows organisation, period, employee, every earning and deduction, gross, deductions, net and payment status; one page per employee when printing. Users without sensitive payroll access, or from another branch, get 403. A draft run offers no payslips |

- [ ] Pass  - [ ] Fail

### 3.30 Staff advances and loans

| | |
|---|---|
| Menu | Payroll → Staff Advances |
| Role | accountant (maker), approver (checker) |
| Steps | 1. New advance for UAT-E-001: 30,000.00, 3 instalments, first recovery October 2026, recovery component "Staff Advance Recovery". 2. Submit; approve as the approver (the maker cannot). 3. Pay it through the normal cash/bank payment (Dr 11205 UAT Staff Advances); link that posted payment to the advance. 4. In the October payroll run use "Enter due recoveries", then validate, approve and post (policy approval needed, see 3.18). 5. Open the advance and the Staff Advances Outstanding report. |
| Expected | The schedule totals exactly 30,000.00 (10,000 × 3). A component whose ledger is cash or bank is refused. After the posted run, the first instalment shows recovered and the outstanding balance is 20,000.00. The module itself never posts a journal |

- [ ] Pass  - [ ] Fail

## 4. Recommended testing order

1. 3.1–3.4 access, roles, approval controls (as Director, approve only the policies you need for the step).
2. 3.5–3.8 fiscal year, chart, mappings, cash and bank accounts.
3. 3.9–3.14 journals, receipts, payments, expenses, purchasing, donations.
4. 3.26–3.28 funds, petty cash imprest, bank reconciliation.
5. 3.15–3.17 inventory, assets, budgets.
6. 3.18, 3.29, 3.30 payroll, payslips, staff advances.
7. 3.19–3.25 opening balances, reports, exports, FRC, audit, authorization, phone and tablet.
8. §6 journal-to-report check.
9. Year-end closing (3.5 step 4) last, because it zeroes the year's income and expenditure.

## 5. Reporting a bug

For each Fail record:

1. Module and check number (for example 3.13 step 5).
2. Role and branch used.
3. Exact steps and values.
4. Expected and actual result.
5. A screenshot.
6. The time, which is needed to find the matching audit entry.

Do not paste passwords, tokens or real personal data into a bug report.

## 6. Journal-to-report check (end of cycle)

1. Note the Trial Balance totals for FY 2026, consolidated.
2. Confirm Income & Expenditure "Total income − total expenditure" equals the Balance Sheet "unclosed surplus" line (before year-end closing).
3. Confirm the Cash Book closing for UAT Cash A equals the "Posted balance" on Cash & Bank for UAT Cash A.
4. Confirm the Fee Reconciliation "Posted amount" equals the fee income lines in the General Ledger for 41102 + 41103.

Every figure must match to the paisa.
