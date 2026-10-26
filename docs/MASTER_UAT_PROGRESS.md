# Master UAT & Module Progress Tracker

**Document Version:** 1.0  
**Current Git Baseline:** `31dfd1b` (`origin/main`)  
**Latest Full Regression Result:** 1,016 passed, 0 failed, 1 skipped, 16,972 assertions (Step 395)  
**Target Environment:** `E:\wamp64\www\frc-accounting-software` (Laravel 13, PHP 8.3)  
**Dedicated UAT Testing URL:** `http://127.0.0.1:8010` (Database: `frc_accounting_software_uat`)  

---

## 1. Status Definitions & Rules

To prevent confusion between code existence, automated tests, manual testing, and business acceptance, the following statuses are strictly separated:

* **Implementation Status:**
  - `Complete`: All defined application features, models, views, and routes are built.
  - `Complete (Blocked)`: Feature is fully built, but real-world execution is locked pending policy or data approval.
  - `Partial`: Core workflow exists, but specific features remain unbuilt.
* **Automated Regression:**
  - `PASS`: Verified by passing automated feature tests under SQLite `:memory:` isolation.
* **Manual Browser UAT:**
  - `PASS (Documented)`: Formally executed in browser with written test evidence in project records.
  - `Progressed (User-Reported)`: Tested in browser during previous sessions, but formal signoff document is pending.
  - `NOT VERIFIED`: Automated tests pass, but formal browser UAT execution record is not yet documented.
* **Client Approval:**
  - `Approved`: Functional specification accepted by stakeholder.
  - `Pending Decision`: Awaiting formal client policy, COA row approval, or data provision.

---

## 2. 15-Phase Master Reconciliation Table

| Phase | Phase Name | Primary Module Scope | Impl. Status | Auto QA Status | Manual Browser UAT | Client Approval | Key Evidence / References |
|:---:|:---|:---|:---:|:---:|:---:|:---:|:---|
| **1** | **Foundation** | Auth, RBAC, Branch Scoping, User Management | Complete | PASS (3 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-01, UAT-13), 670-page smoke |
| **2** | **Accounting Setup** | Fiscal Years, Monthly Periods, COA, Importer | Complete | PASS (5 suites) | **PASS (Synthetic)** | **Pending Decision** | S1–S6 automated, `COA_APPROVAL_SUMMARY.md` (62 rows pending) |
| **3** | **GL Mapping, Cash/Bank & Opening Balances** | GL Mappings, Financial Accounts, Shared Banks, Opening Balances | Complete | PASS (7 suites) | **PASS (Documented)** | **Pending Decision** | `PHASE4_BROWSER_UAT_EVIDENCE.md` (JV-2026-00001/2), Real balances pending |
| **4** | **Journals, Approvals, GL & Trial Balance** | Journal Engine (JV/CV), Maker-Checker, Reversals, GL, Trial Balance | Complete | PASS (5 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-04, UAT-06), Draft editor exclusions |
| **5** | **Receipts, Payments, Expenses & Petty Cash** | Receipts, Unified Payments, Director Threshold, Accrued Obligations, Legacy Expenses | Complete | PASS (8 suites) | **Progressed (Step 385)** | **Pending Decision** | `UAT-07`, Unified Payments UAT passed; Director user appointment pending |
| **6** | **Purchasing** | Vendors, Purchase Orders, Purchase Bills, Payables & Aging | Complete | PASS (5 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-08), Overpayment/overbilling guards |
| **7** | **Donations** | Donation Categories, Receipts, Printable Acknowledgment | Complete | PASS (2 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-09, DR vouchers) |
| **8** | **Bank Reconciliation & Imprest Floats** | Statement CSV Parser, Auto/Manual Matching, Reversal Locks, Imprest Floats | Complete | PASS (3 suites) | **NOT VERIFIED** | Approved | Automated pass (`BankReconciliationTest`, `PettyCashImprestTest`); Browser UAT pending |
| **9** | **Fixed Assets & Depreciation** | Asset Register, Straight-Line Schedule, Policy Block, Reversals | Complete (Blocked) | PASS (2 suites) | **PASS (Documented)** | **Pending Decision** | `UAT-11` (Policy block verified); Depreciation rates pending |
| **10** | **Inventory** | Item Master, Stock In/Out, Returns, Inter-Branch Transfers, Adjustments | Complete | PASS (2 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-10, Double-submit prevention) |
| **11** | **Budgeting** | Multi-version Budgets, Revisions, Budget vs Actual Reporting | Complete | PASS (2 suites) | **PASS (Synthetic)** | Approved | `UAT_TEST_PLAN.md` (B1–B3), `BudgetTest` (65 KB suite) |
| **12** | **Payroll** | Employee Directory, Salary Structures, Advances, Runs, Payslips | Complete (Blocked) | PASS (5 suites) | **PASS (Documented)** | **Pending Decision** | `UAT-11` (Posting block verified), `PayslipTest`; Salary policy & figures pending |
| **13** | **FRC API Integration** | HTTP Sync, Branch/Service Mirror, Payment/Enrollment Mirror, Fee Posting | Complete | PASS (5 suites) | **PASS (Documented)** | **Pending Decision** | `UAT-02`, `UAT-03`, Local activation verified; Production token pending |
| **14** | **Reporting** | I&E, Balance Sheet, Cash Flow, Receipts & Payments, CSV/XLSX/PDF Exports | Complete | PASS (4 suites) | **PASS (Documented)** | **Pending Decision** | `UAT-12`, Step 394 PDF column width fix verified; Cash-flow classifications pending |
| **15** | **Audit, Security & Year-End Closing** | Immutable Audit Log, Director Authority, Year-End Closing to Surplus | Complete | PASS (5 suites) | **PASS (Documented)** | Approved | `UAT-01`, `UAT-13`, Year-end closing tested under concurrency |

---

## 3. Detailed Phase Tracking & Verification Status

### Phase 1: Foundation
* **Scope:** Authentication, Throttling, User Management, Spatie RBAC, Branch Scoping (`BranchAccess`).
* **Automated Evidence:** 100% PASS (`AuthenticationTest`, `AccessManagementTest`, `RoleEditVisibilityTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: Cases A1–A6, UAT-01, UAT-13). 8 roles tested across 670 page visits.
* **Client Approval:** Approved.
* **Outstanding Dependencies:** None.
* **Post-UAT Changes:** None.

### Phase 2: Accounting Setup
* **Scope:** Fiscal Years, Accounting Periods, Chart of Accounts, COA Import Review Engine.
* **Automated Evidence:** 100% PASS (`FiscalYearManagementTest`, `ChartOfAccountsTest`, `AccountImportTest`).
* **Manual Browser UAT:** PASS on synthetic data (`UAT_TEST_PLAN.md` S1–S6).
* **Client Approval:** **PENDING CLIENT DECISION**.
* **Outstanding Dependencies:** Client approval of the 62 flagged rows (27 former School rows + 11 specific questions in `docs/COA_APPROVAL_SUMMARY.md`).

### Phase 3: GL Mapping, Cash & Bank, Opening Balances
* **Scope:** GL Account Mapping, Financial Accounts, Shared Bank Accounts, Opening Balance Batches.
* **Automated Evidence:** 100% PASS (`AccountMappingTest`, `SharedBankAccountTest`, `BranchCashLedgerTest`, `OpeningBalanceTest`).
* **Manual Browser UAT:** PASS (`PHASE4_BROWSER_UAT_EVIDENCE.md`: Scenarios A & B passed on QA server `127.0.0.1:8020` with JV-2026-00001 and JV-2026-00002).
* **Client Approval:** **PENDING CLIENT DECISION** (Real opening balance figures per branch).
* **Post-UAT Changes:** Shared Bank Account scope (`organization`) added. Requires verification under multi-branch UAT.

### Phase 4: Journals, Approvals, General Ledger, Trial Balance
* **Scope:** Journal Engine (JV/CV), Reversal Workflow, Draft Discarding, Maker-Checker, General Ledger, Trial Balance Carry-Forward.
* **Automated Evidence:** 100% PASS (`JournalEngineTest`, `JournalReversalWorkflowTest`, `DraftEditorApprovalTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-04, UAT-06).
* **Client Approval:** Approved.
* **Post-UAT Changes:** Maker-checker hardened to disallow users who edited drafts from approving (`DraftEditorApprovalTest`).

### Phase 5: Receipts, Payments, Expenses, Petty Cash
* **Scope:** Cash/Bank Receipts, Printable Receipts, Unified Payments, Director Threshold (> PKR 150,000), Legacy Paid Expenses, Accrued Payable Obligations.
* **Automated Evidence:** 100% PASS (`OperationalFinanceTest`, `FinancialAuthorizationTest`, `UnifiedPaymentTest`, `UnifiedPaymentSourcesTest`, `PaymentsHistoryTest`, `PaymentsRegisterAuditTest`).
* **Manual Browser UAT:** **Progressed through Step 385**. Historical `UAT-07` passed; Unified Payments manual UAT verified in browser.
* **Client Approval:** **PENDING CLIENT DECISION** (Formal Director appointment via CLI).
* **Post-UAT Changes:** Complete unification of outgoing payments into Unified Payments; legacy Paid Expenses added to history and register; accrued expenses isolated to `PayableObligation`.

### Phase 6: Purchasing
* **Scope:** Encrypted Vendor Directory, Purchase Orders, Purchase Bills, Overbilling Guards, Payables Aging.
* **Automated Evidence:** 100% PASS (`PurchaseOrderTest`, `PurchaseBillMakerCheckerTest`, `VendorPaymentBillSelectionTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-08).
* **Client Approval:** Approved.
* **Post-UAT Changes:** Purchase Bill settlement linked directly to Unified Payments (`payments.create?bill=`).

### Phase 7: Donations
* **Scope:** Donation Categories, Donation Receipts (DR), Printable Acknowledgment Receipts.
* **Automated Evidence:** 100% PASS (`OperationalFinanceTest`, `FundAccountingTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-09).
* **Client Approval:** Approved.
* **Outstanding Dependencies:** Decision on "New FRC Project Donation" ledger mapping.

### Phase 8: Bank Reconciliation & Petty Cash Imprest
* **Scope:** Bank Statement CSV Parser, Automated/Manual Reconciliation, Reversal Lock on Reconciled Journals, Branch Imprest Floats, Replenishment.
* **Automated Evidence:** 100% PASS (`BankReconciliationTest` [44 KB], `ReconciledJournalReversalTest`, `PettyCashImprestTest`).
* **Manual Browser UAT:** **NOT VERIFIED**. Implemented after Milestone 8; automated suite is comprehensive, but formal browser UAT session is pending.
* **Client Approval:** Approved design.

### Phase 9: Fixed Assets & Depreciation
* **Scope:** Asset Register, Straight-Line Depreciation Schedule Preview, Monthly Posting, Policy Guards.
* **Automated Evidence:** 100% PASS (`FixedAssetTest`, `AssetReportTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-11 confirmed posting is blocked without approved policy).
* **Client Approval:** **PENDING CLIENT DECISION** (Depreciation rates and capitalization minimum).

### Phase 10: Inventory
* **Scope:** Item Master, Stock In, Stock Out, Inter-Branch Transfers, Adjustments with Dual Approval (Non-GL quantity only).
* **Automated Evidence:** 100% PASS (`InventoryTest`, `InventoryReportTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-10).
* **Client Approval:** Approved.

### Phase 11: Budgeting
* **Scope:** Budget Creation per Branch/Ledger, Multi-versioning, Revision Workflows, Budget vs Actual Reporting.
* **Automated Evidence:** 100% PASS (`BudgetTest` [65 KB], `BudgetScalabilityTest` [18 KB]).
* **Manual Browser UAT:** PASS on synthetic data (`UAT_TEST_PLAN.md` B1–B3).
* **Client Approval:** Approved.

### Phase 12: Payroll
* **Scope:** Employee Profiles, Salary Structures, Staff Advances & Deductions, Payroll Runs, Individual & Batch Payslips.
* **Automated Evidence:** 100% PASS (`PayrollTest`, `PayrollImportTest`, `PayslipTest`, `StaffAdvanceTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-11 confirmed posting block).
* **Client Approval:** **PENDING CLIENT DECISION** (Salary calculation rules, leave policy, fixed-value workbook).

### Phase 13: FRC API Integration
* **Scope:** HTTP Sync with `frc-software-vs`, Master Data Mirror, Payment/Enrollment Mirror, Fee Settlement Mapping, Fee Posting.
* **Automated Evidence:** 100% PASS (`FrcApiClientTest`, `FrcFinancialSyncTest`, `FeePostingTest`, `FeeSettlementMappingTest`).
* **Manual Browser UAT:** PASS on synthetic data (`UAT_EXECUTION_REPORT.md`: UAT-02, UAT-03, UAT-05).
* **Client Approval:** **PENDING CLIENT DECISION** (Production token deployment).

### Phase 14: Reporting
* **Scope:** I&E, Balance Sheet, Cash Flow, Books, Registers, PDF/Excel/CSV Exports with Formula-Injection Defense.
* **Automated Evidence:** 100% PASS (`FinancialStatementTest`, `CashFlowReportTest`, `LedgerExportTest`, `ReportExporterTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-12). Step 394 resolved General Ledger PDF numeric column truncation.
* **Client Approval:** **PENDING CLIENT DECISION** (Cash-flow classification of ledgers).

### Phase 15: Audit, Security & Year-End Closing
* **Scope:** Immutable Audit Logs, Director Authority Controls, Year-End Closing to Accumulated Surplus.
* **Automated Evidence:** 100% PASS (`AuditLogViewerTest`, `ApprovalControlSecurityTest`, `DirectorSeparationTest`, `YearEndClosingTest`).
* **Manual Browser UAT:** PASS (`UAT_EXECUTION_REPORT.md`: UAT-01, UAT-13).
* **Client Approval:** Approved.

---

## 4. Current Manual UAT Position

Manual browser testing progressed through **Phase 5 (Receipts, Payments, Expenses)** during Steps 375–385. However, formal closure of earlier phases remains blocked by client data and prerequisites:

1. **Phase 2 (Setup):** Blocked from live use until the client approves the 62 flagged accounts.
2. **Phase 3 (Opening Balances):** Blocked from live use until real branch opening balances are provided.
3. **Phase 5 (Payments):** Blocked from live high-value approvals (> PKR 150k) until a real Director user is appointed.
4. **Phase 8 (Bank Reconciliation):** Implemented and automated-tested (100% PASS), but requires formal manual browser UAT walkthrough.

---

## 5. Next Recommended Testing Activity

Conduct a dedicated browser UAT walkthrough for **Phase 8 (Bank Reconciliation & Petty Cash Imprest)** on the synthetic UAT environment (`http://127.0.0.1:8010`) to transition its status from `NOT VERIFIED` to `PASS (Documented)`.
