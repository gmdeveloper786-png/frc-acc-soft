# Chart of Accounts — Import Readiness

**Status (14 October 2026): ready to import once an authorised reviewer records the decisions; nothing imported.** The development database holds 0 accounts and 0 journals. The engineering changes this review asked for are implemented and tested (§4.3, §5.2, §10). The branch-scoped ledger migration was verified on MySQL and **applied to the local development database on 14 October 2026** with the owner's approval; it is not deployed to production. Every selection below is a **proposal** built from the workbook, the project scope and the existing software. None is a client approval, and nothing here changes a workbook code, name or hierarchy.

Sources: `docs/Chart-of-Accounts.xlsx` (sheet "Chart of Accounts"), `COA_APPROVAL_SUMMARY.md`, `CLIENT_POLICY_DECISIONS.md`, `PROJECT_SCOPE.md`, the importer (`AccountImportService`), cash/bank, journal, fee posting and GL mapping code, and the local FRC mirror.

Business model applied throughout: one organisation (Faizan Rehabilitation Centre), six branches, one shared Chart of Accounts. School Readiness Program is a normal FRC service, not a separate entity.

## 1. Workbook verification

| Check | Result |
|---|---|
| Rows | 226 |
| Parent headings | 59 (5 class, 14 group, 40 sub-group) |
| Ledgers (postable) | 167: 47 asset, 19 liability, 10 fund/equity, 35 income, 56 expense |
| Entity "Both" ledgers | 115 |
| Entity "Rehab Centre" ledgers | 25 |
| Entity "School" ledgers | 27 |
| Flagged signal rows | 11 (below) |
| Structure | 0 issues: every parent exists, is one level up, has the same account type and financial statement, and prefixes the child code; only ledgers are postable; no duplicate codes |

Contra ledgers (accumulated depreciation 12501–12506, fee concessions 41301–41304, client concession 42201) carry a Cr or Dr balance opposite to their heading. The importer checks type and statement against the parent, not the normal balance, so they import as written.

### The 62 flagged rows and the 11 questions

62 = 52 legacy-entity ledgers (27 School + 25 Rehab Centre) + 11 signal rows − 1 row in both groups (42107). The 11 signal rows are the 11 questions of `COA_APPROVAL_SUMMARY.md`:

| Signal | Rows |
|---|---|
| `[Confirm]` note | 11601, 21202, 21203, 31202, 42107, 43102 |
| Owner / private | 31303, 31304 |
| Inter-unit | 11602, 21501, heading 215 |

The 25 Rehab Centre ledgers are flagged only because of their legacy entity label; under the one-organisation model they are ordinary FRC ledgers.

## 2. Proposed selection

### 2.1 Straightforward candidates — 130 ledgers

**Shared, no signal (106):** 11201 11204 11205 11206 11304 11305 11401 11402 11403 11404 11405 11501 11504 11603 12101 12102 12103 12201 12202 12203 12206 12207 12301 12401 12501 12502 12503 12505 12506 12601 21101 21102 21103 21201 21204 21303 21304 21401 21402 21502 21503 22101 22102 22201 31101 31102 31201 31203 31204 31301 31302 43101 43103 43104 43201 43202 43203 43301 44101 44102 44103 44104 44105 51103 51104 51105 51201 51202 51203 51204 51205 51206 51207 53101 53102 53103 53104 53105 53106 53107 53108 53201 53202 53203 53204 53205 53206 53207 53208 53301 53302 53303 53304 54101 54102 54103 54104 54105 54106 54107 55101 55102 55103 55105 55106 55107

**Rehab Centre, no signal (24):** 11102 11104 11203 11302 11303 11503 11505 12205 12504 21302 42101 42102 42103 42104 42105 42106 42201 51102 52201 52202 52203 52204 52205 55104. Names are imported exactly as written (for example "Cash in Hand - Rehab Centre"); the legacy label does not restrict use by any branch.

### 2.2 Required parent headings — 54

All headings except the five whose ledgers are all former-School: 1 11 111 112 113 114 115 116 12 121 122 123 124 125 126 2 21 211 212 213 214 **215** 22 221 222 3 31 311 312 313 4 42 421 422 43 431 432 433 44 441 5 51 511 512 52 522 53 531 532 533 54 541 55 551.

School-only headings 41, 411, 412, 413 and 521 are needed only if one of their ledgers is approved. Mixed headings 111, 52 and 2-level parents stay because they have shared or Rehab children.

### 2.3 Legacy former-School ledgers — 27, proposed exclude

11101 11103 11202 11301 11502 12204 21301 41101 41102 41103 41104 41105 41106 41107 41201 41202 41203 41301 41302 41303 41304 51101 52101 52102 52103 52104 52105.

These are ledgers of the former separate School unit (school cash and bank, student receivables and advances, tuition/admission/exam/transport fees, uniform and canteen income, sibling and staff-child concessions, teaching staff salaries, school programme costs). No FRC function in the current software uses them: School Readiness fees arrive as ordinary FRC payments and are recorded by service. Excluding them is reversible (an excluded row can be imported later from the same workbook); importing and posting to them is not. The reviewer may approve any of them with a documented reason if FRC still uses it.

### 2.4 Ambiguous rows — proposals with evidence

| Code | Account | Proposal | Evidence / reason |
|---|---|---|---|
| 11601 | Withholding Tax Recoverable | Import, do not post until tax treatment is confirmed | An unused ledger has no effect; tax treatment is not decided here |
| 21202 | Income Tax Withheld from Staff Payable | Import, do not post until statutory rules are confirmed | Payroll posting is already blocked by policy |
| 21203 | AKKF Fund | Import, do not post until scheme applicability is confirmed | As above |
| 31202 | Zakat / Charity Fund | Import | A "Zakat" donation category exists in the software and the FRC software records zakat eligibility, so Zakat is in use |
| 43102 | Zakat / Charity Donations | Import | Same evidence |
| 42107 | Skills & Vocational Training Fees | Exclude | None of the 9 FRC services is vocational training |
| 31303 | Owner's / Trustees' Capital | Exclude | Workbook: "use only if privately owned"; FRC operates as a donation- and Zakat-receiving centre |
| 31304 | Owner's Drawings | Exclude | Same |
| 11602 | Inter-Unit Receivable | Exclude | One organisation; no second unit to balance against |
| 21501 | Inter-Unit Payable | Exclude | Same |
| 215 | Inter-Unit & Other (heading) | **Import** (see §3) | Required parent of 21502 and 21503 |

### 2.5 Summary of the proposal

| | Ledgers | Headings |
|---|---:|---:|
| Import (130 straightforward + 11601, 21202, 21203, 31202, 43102) | 135 | 54 |
| Exclude (27 School + 42107, 31303, 31304, 11602, 21501) | 32 | 5 |
| **Total** | **167** | **59** |

## 3. Heading 215 — recommendation

| Code | Account | Treatment |
|---|---|---|
| 215 | Inter-Unit & Other (sub-group, not postable) | Import unchanged |
| 21501 | Inter-Unit Payable | Exclude (inter-unit, conflicts with the one-organisation model) |
| 21502 | Deferred Grant Income | Import (grant funds received in advance; valid for FRC) |
| 21503 | Other Payables | Import (general payable; valid for FRC) |

A ledger cannot be imported without its parent, so excluding 215 would also remove 21502 and 21503. Importing the heading under its workbook name is safe: a heading cannot be posted to, so it cannot carry inter-unit balances. The only effect is that reports show the subtotal caption "Inter-Unit & Other"; renaming it is a separate, audited master-data change that the accountant may make later.

Two places propose excluding 215 and should be corrected:
- `COA_APPROVAL_SUMMARY.md`, "Rows that need your decision".
- The importer's automatic proposal (`AccountImportService::classifyRows`), which marks any row whose name contains "inter-unit" as *exclude*, including the heading. The reviewer can override the proposal row by row, so this is not a blocker.

## 4. Branch cash architecture

### 4.1 How the software works

- `financial_accounts` (a physical cash box or bank account) has `branch_id` (required) and `account_id` (its GL ledger). The original schema made **`account_id` unique** across all branches (`2026_10_13_100000_create_operational_finance_tables.php:15`), so one ledger could back only one cash or bank record. Migration `2026_10_18_000001_scope_financial_account_ledger_to_branch` replaces this with `unique(account_id, branch_id)` (§4.3); it is applied on the local development database.
- Branch lives on the journal entry: `journal_entries.branch_id` is required. Journal lines carry `account_id` and `service_id` but no branch.
- General Ledger, Trial Balance, cash movement, statements, Cash Book and Bank Book all filter by `journal_entries.branch_id`.
- Cash and bank receipts/payments check that the cash/bank record belongs to the transaction's branch, and fee posting resolves the settlement record by the payment's branch.
- The manual-journal cash/bank guard identifies cash/bank ledgers by membership of `financial_accounts.account_id`.
- Opening balances are per (fiscal year, branch) batch and per account within a batch.

**Why it mattered:** the workbook has two cash-in-hand ledgers (11101 former School, 11102 Rehab Centre) and five usable bank ledgers. Under the original constraint only one branch could have a cash record on 11102, and the other five branches could not have cash without new ledger codes.

### 4.2 Options

| Option | Accuracy | Maintenance | Workbook fit | Verdict |
|---|---|---|---|---|
| A. One shared cash ledger, branch-wise balances from `journal_entries.branch_id` | Correct per branch; balances already reported per branch | Simplest chart | Uses 11102 as written | Sound, but on its own it gives branches no physical cash record to select |
| B. One GL ledger per branch | Correct | Chart grows with every branch; adding a branch needs a new code | Requires 4–5 new codes the workbook does not contain | Not possible without inventing codes |
| C. One physical cash record per branch, all linked to the shared organisational ledger | Correct, and each branch has its own cash box | One ledger, six records; new branch = new record only | Uses 11102 as written | **Recommended** (it implements A) |

**Recommendation: C.** Create one cash record per branch on 11102 (and, for banks, one record per branch on the bank ledger that branch deposits into). Branch cash balances, Cash Book and branch statements come from the journal's branch, which every report already uses.

### 4.3 Implementation of C (done; migration applied on local development)

| Change | Where |
|---|---|
| `unique(account_id)` replaced by `unique(account_id, branch_id)`; the composite index leads with `account_id`, so it also serves the foreign key. `down()` refuses to run once a ledger is shared | `database/migrations/2026_10_18_000001_scope_financial_account_ledger_to_branch.php` |
| A branch links a ledger at most once; cash and bank records never share a ledger | `StoreFinancialAccountRequest` |
| An account is "used" (ledger, branch and type frozen) only by postings in its own branch | `FinancialAccountController::update` |
| Each cash/bank account shows its own posted balance: its ledger's lines in its own branch | `FinancialAccountController::index`, `financial-accounts/index` view |
| Cash Book and Bank Book match each line to the account of its journal's branch, filter to one account, and name the account on screen and in exports | `OperationalReportController`, `LedgerReportTables::book`, `reports/book` view |
| Receipts & Payments names a ledger after the account only when one account is in scope, otherwise after the ledger | `ReceiptsPaymentsService` |

The migration filename keeps its date (18 October). It sorts after every migration applied before it (the latest was `2026_10_17_000001`), so renaming it to an earlier date would only have made its position inconsistent with the applied history.

**Local development application (14 October 2026).** Preflight:
- Laravel 13 project, MySQL database `frc_accounting_software`, environment local, PHP 8.3.14.
- This was the only pending migration, and `financial_accounts` held 0 records.
- A fresh dump (76 tables, verified complete) was taken into `E:\wamp64\backups\frc_branch_cash_migration_20261014_220851\`. The folder was restricted to the owner, Administrators and SYSTEM before the dump was written.

Only this migration was run (`migrate --path=…`). Afterwards:
- The old unique index is gone and `unique(account_id, branch_id)` is active.
- The foreign keys to accounts, branches and users are unchanged.
- Table checksums before and after differ only for `migrations` (one row added); the other 75 tables are unchanged.
- The FRC mirror still holds 6 branches, 9 services, 6 enrollments and 7 payments.
- Accounts, journals, journal lines, transactions, fee postings, GL mappings and Directors are all still 0.

**Verification on MySQL 9.1 / InnoDB** (`php tests/MySql/run.php`, disposable databases only, dropped afterwards): 25 of 25 checks passed.
- The pre-migration schema is identical to the development database's (read-only comparison).
- Migrate and roll back succeed while no ledger is shared.
- The old unique index is removed and the new composite unique index is present.
- The foreign keys to accounts, branches and users remain.
- Existing records are byte-identical afterwards, including encrypted account numbers.
- The same ledger is accepted in a second branch, but rejected twice in one branch (1062).
- A null branch is rejected (1048), and an unknown ledger is rejected by the foreign key (1452).
- Validation rejects a cash/bank conflict and a same-branch duplicate.
- Rollback is refused while a ledger is shared and leaves the schema unchanged, then succeeds once nothing is shared.
- 162 feature tests (cash/bank, fee posting and settlement, COA import, statements, cash flow, exports, mappings, reconciliation, journal guard) pass on MySQL.

Shared-ledger reporting is covered by `tests/Feature/BranchCashLedgerTest.php`, on SQLite and MySQL. Two branches on one cash ledger (1,000 and 250) give exact branch and consolidated figures (1,250, never 2,500) in:
- the posted-balance column and the Cash Book (per branch, per account, consolidated, CSV export totals);
- the General Ledger and the Trial Balance;
- the Cash Flow Statement and the Receipts & Payments Statement;
- the Balance Sheet and branch-wise Income & Expenditure.

The tests also cover the fee settlement per branch, the manual-journal guard and cross-branch access.

The test suite now refuses to start against any database other than in-memory SQLite or a disposable `*_mysql_test` MySQL database (`tests/TestCase.php`), so a misconfigured run cannot reset the development database.

Remaining risks:
- There is no per-branch overdraft check today, with or without C.
- A transfer of cash between branches on the same ledger needs two entries (one per branch) or a clearing ledger.
- Branch figures depend entirely on the journal's branch being right. Every posting service already sets it server-side.

All 7 synchronised payments belong to one branch (Shahrah-e-Faisal), which needs only one cash record on 11102 and one bank record on 11201; the other branches can now use the same ledgers, one record each.

## 5. GL mapping proposals

Only classification-compatible postable ledgers are proposed; the software enforces this on save. Journal lines carry the service, so a shared income ledger keeps service-level reporting (service analysis report).

### 5.1 Control mappings (GL Mapping screen)

| Mapping | Proposed ledger | Note |
|---|---|---|
| Accounts Receivable (required) | 11302 Client Service Fees Receivable | Used by the accrual basis; required by setup readiness |
| Accounts Payable (required) | 21101 Trade Creditors / Suppliers | |
| Purchase Bill control | Leave blank | Accounts Payable is used |
| General Fee Income (required) | 42102 Therapy Session Fees | Used only by manually entered fee receipts whose ledger is left blank; never by synchronised FRC payments (§5.2) |
| Default Expense | Leave blank | Forces an explicit expense ledger on each payment |
| Petty Cash | 11104 Petty Cash - Rehab Centre | |
| Salary Payable | 21201 Salaries Payable | Payroll posting stays blocked by policy |
| Salary Expense | **Unresolved**: 51102 Therapist & Care Staff, 51103 Administrative & Support, 51104 Management or 51105 Daily Wages | One default; components can carry their own ledger |

### 5.2 Fee income by service

| FRC service | Proposed ledger | Confidence |
|---|---|---|
| Speech, Remedial, Occupational, Physio, Behavioral, Group Therapy | 42102 Therapy Session Fees | High |
| Parental Counselling | 42104 Counselling & Support Fees | High |
| School Readiness Program | 42105 Day Programme Fees | Medium. Closest workbook ledger. Former-School fee ledgers (411xx) are not proposed, because the service is a normal FRC service |
| Quran Teaching | **Unresolved; left unconfigured.** No matching ledger in the workbook | — |

42101 Assessment & Registration, 42103 Residential, 42106 Package Fees: available, no current mapping.

**Synchronised FRC payments use only the service's own ledger.** Fee posting requires an explicit, active, postable income ledger on the source service; it no longer falls back to General Fee Income (`AccountMappingService::serviceFeeIncome`, used by `FeePostingEligibility`).
- A payment of an unmapped service (for example Quran Teaching) stays synchronised and unposted.
- The preview shows the reason: "Service Quran Teaching has no fee income ledger. Set it under GL Mapping → Service-specific fee income …".
- A service mapped to an expense, inactive or heading account is blocked in the same way.

Manually entered fee receipts keep the documented fallback to General Fee Income when their ledger is left blank. The GL Mapping service selector says so.

These rules are tested in `FeePostingTest`:
- an unmapped Quran Teaching payment stays unposted;
- School Readiness posts to its own ledger with branch and service, once;
- invalid ledger classifications are blocked;
- the mixed batch posts with no General Fee Income credit and a balanced Trial Balance;
- missing settlement mappings are blocked, and the existing tests for duplicate and concurrent posting still pass.

No service mapping has been configured.

### 5.3 Donations

| Category | Proposed ledger | Note |
|---|---|---|
| General Donation | 43101 General Donations | |
| Zakat | 43102 Zakat / Charity Donations | Restricted use; fund 31202 available |
| New FRC Project Donation | **Unresolved. No ledger in the workbook.** | Options: share 43101 (the donation category on each receipt preserves the detail) or a new ledger code from the accountant. A project donation may be donor-restricted (fund codes DNR/CAP), which is a policy question, not settled here |

### 5.4 Cash, bank and settlement

| Item | Proposal |
|---|---|
| Cash, Shahrah-e-Faisal | Record "Cash – Shahrah-e-Faisal" on 11102 (possible now) |
| Cash, other five branches | One record per branch on 11102 (supported now that the migration is applied) |
| Bank | 11201 Main Operating Account. 11203, 11204 (donation), 11205 (payroll) and 11206 (grant) are used only when the matching real bank accounts exist. A bank record requires the bank name (title and number optional, number stored encrypted); these must come from the client and are never invented |
| Settlement, method cash | The branch's cash record |
| Settlement, methods card / bank_transfer / easypaisa / jazzcash / other | The branch's bank record on 11201. **Unresolved:** whether Easypaisa and JazzCash wallets are separate real accounts (`CLIENT_POLICY_DECISIONS.md` row 20); if so they need ledgers the workbook does not contain |

### 5.5 Other balances

| Area | Ledgers |
|---|---|
| Receivables | 11302 client fees; 11303 sponsor; 11304 pledges; 11305 grants; 11603 other |
| Payables | 21101 suppliers; 21102 accrued; 21103 utilities; 21304 fee refunds; 21503 other |
| Advances | 11401–11405 |
| Inventory (quantity-only; no GL posting) | 11501, 11503, 11504, 11505 |

### 5.6 Fixed asset categories (asset / accumulated depreciation / depreciation expense)

| Category | Ledgers |
|---|---|
| Buildings | 12102 / 12501 / 55101 |
| Building improvements | 12103 / 12501 / 55101 |
| Furniture & fixtures | 12201 / 12502 / 55102 |
| Office equipment | 12202 / 12503 / 55103 |
| Computers & IT | 12203 / 12503 / 55103 |
| Therapy equipment | 12205 / 12504 / 55104 |
| Vehicles | 12301 / 12505 / 55105 |
| Software | 12601 / 12506 / 55106 |
| Electrical, generator & solar (12206); partition work (12207) | **Unresolved**: the workbook has no matching accumulated-depreciation pair; 12503/55103 or 12502/55102 are the nearest |
| Land (12101); capital work in progress (12401) | Not depreciated. A category requires depreciation ledgers, so record these through journals, not as depreciable assets |

Useful lives and the depreciation policy are not proposed here; depreciation posting stays blocked until the policy is approved.

## 6. FRC payment integration requirements

Local mirror (14 October 2026): 6 enrollments, 7 paid payments, all Shahrah-e-Faisal: cash 11,000; card 2,000; bank transfer 3,000; Easypaisa 2,000. By service: Physiotherapy 3,000, Speech 4,000, Occupational 2,000, Behavioral 2,000, Remedial 2,000, School Readiness 5,000.

Before authorised fee posting:

1. The ledgers above are imported: 11102, 11201, 42102, 42105, 42104 and 11302.
2. A cash record (11102) and a bank record (11201, with real bank details) exist for Shahrah-e-Faisal.
3. Settlement mappings for that branch: cash → cash record; card, bank_transfer, easypaisa → bank record.
4. Each paying service has its own fee income ledger (§5.2): 42102 for the five therapies paid so far and 42105 for School Readiness. General Fee Income is not used for these payments.
5. An open fiscal year and period covering 12–13 October 2026.
6. A Director appointed. The Director approves the fee policy (`fees.accounting_policy_approved`, basis cash as recommended).
7. Preview, then post by an authorised user.

Expected result, for checking the preview only (nothing is created):

| Line | Amount |
|---|---|
| Dr 11102 Cash in Hand | 11,000 |
| Dr 11201 Bank – Main Operating | 7,000 |
| Cr 42102 Therapy Session Fees | 13,000 |
| Cr 42105 Day Programme Fees | 5,000 |

These are seven separate FR journals, one per payment, each carrying its service and the Shahrah-e-Faisal branch.

## 7. Safe import procedure

1. Back up the Accounting database and restrict the backup folder (as in `PRODUCTION_READINESS_CHECKLIST.md` §6).
2. Accounting Setup → Chart of Accounts import: upload the workbook. This creates a private preview only.
3. Reviewer (accountant) records each decision on the review screen with a reason for every flagged row (the screen requires it). Use §2 as the starting proposal.
4. Download the approval summary CSV and have the owner confirm it.
5. Confirm the import of approved rows only. Parents import before children, and excluded rows are refused.
6. Run the verification checks in §8.
7. Then, each as a separate authorised step: cash/bank records, GL mappings, service income, donation categories, settlement mappings and asset categories.

Do not import rows that are pending or excluded, and do not edit codes or names during import.

## 8. Verification checks after import

| Check | Expected |
|---|---|
| Accounts created | Exactly the approved rows (189 under the full proposal: 135 ledgers + 54 headings) |
| Every ledger has its parent; no orphan | 0 orphans |
| Postable flag | Ledgers only |
| Codes and names | Identical to the workbook |
| Excluded rows | Absent |
| Setup readiness screen | Chart present; remaining items list only mappings and policies |
| Audit log | One import entry and the review decisions with reviewer and reason |
| Trial Balance | Empty (no balances invented) |
| Journals | 0 |

## 9. Exact remaining setup steps

1. ~~Apply the branch-scoped ledger migration~~ — done on local development, 14 October 2026 (§4.3).
2. Owner/accountant confirm the selection in §2 (or amend it) on the review screen.
3. Import the approved rows (§7) and verify them (§8).
4. Decide: Salary Expense default, Quran Teaching income, New FRC Project Donation ledger, Easypaisa/JazzCash settlement, and depreciation ledgers for 12206 and 12207.
5. Create cash/bank records with real bank details, the GL mappings, service fee income ledgers and the settlement mappings.
6. Appoint the Director; the Director approves the fee policy.
7. Post the 7 synchronised payments after a preview, and compare with §6.
8. Opening balances, when the client supplies them.

## 10. Issues requiring implementation changes

| # | Issue | Status |
|---|---|---|
| 1 | One ledger could back only one branch's cash/bank record | **Fixed** (§4.3); migration verified on MySQL and applied on local development |
| 2 | Automatic proposal marked heading 215 *exclude* although valid children depend on it | **Fixed**: 215 is proposed *include* (manual review) while 21502/21503 depend on it; 21501 stays *exclude*. `COA_APPROVAL_SUMMARY.md` updated. Tested in `AccountImportTest` |
| 3 | Synchronised FRC payments of an unmapped service fell back to General Fee Income | **Fixed** (§5.2) |
| 4 | Non-depreciable assets (land, work in progress) cannot be asset categories because depreciation ledgers are mandatory | Open; acceptable if land is recorded by journal |
