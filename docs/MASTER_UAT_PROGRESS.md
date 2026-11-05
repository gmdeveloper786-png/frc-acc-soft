# Master UAT & Module Progress Tracker

**Document Version:** 1.1<br>
**Current Git Baseline:** `afa9f19` (`origin/main`)<br>
**Latest Full Regression Result:** 1,042 passed, 0 failed, 1 skipped, 17,134 assertions (1,043 total tests) (Step 59)<br>
**Target Environment:** `E:\wamp64\www\frc-accounting-software` (Laravel 13, PHP 8.3)<br>
**Dedicated UAT Testing URL:** `http://127.0.0.1:8000` (Database: `frc_accounting_software_uat`)

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
| **5** | **Receipts, Payments, Expenses & Petty Cash** | Receipts, Unified Payments, Director Threshold, Accrued Obligations, Legacy Expenses | Complete | PASS (8 suites) | **Progressed (Step 385)** | **Pending Decision** | `UAT-07`, Unified Payments UAT passed through Step 385; Director user appointment pending |
| **6** | **Purchasing** | Vendors, Purchase Orders, Purchase Bills, Payables & Aging, Bill Reversal & Cancellation, Dynamic Line Editing, Vendor Payments, Cancelled PO Controls | Complete | PASS (10 suites) | **PASS (Synthetic Steps 44–58, 6A-19A & 6A-28A Documented)** | **Internally Approved Design (Formal Client Signoff Pending)** | Historical `UAT-08` + Steps 44–58 + 31 Oct 2026 Synthetic Browser UAT: PO-2026-00001 (PKR 1k) approved, excess bill UAT-BILL-PO-001 rejected with PO balance restored, normal bill UAT-BILL-PO-002 (PB-2026-00004 / J#58) posted Dr 53209 / Cr 21101, Vendor Payment #28 (BP-2026-00021 / J#61) posted Dr 21101 / Cr 11203 MCB, Bill #6 outstanding PKR 0, MCB balance PKR 647,500, PO #1 closed (Audit #812); PO-2026-00002 (PKR 400) cancelled (Audit #817), Available for Billing PKR 0.00, Unbilled at Cancellation PKR 400.00 (2 pcs), empty bills notice, HTTP 403 on direct bill create. Real procurement & formal client signoff pending. |
| **7** | **Donations & Funds** | Donation Categories, Receipts, Printable Acknowledgment, Restricted Funds & Allocations | Complete (Guards Active) | PASS (46 tests in 2 suites) | **IN PROGRESS (General Donation PASS — Step 7A-17; Restricted Drafts & Validation PASS — Step 7B-08; Posting Pending Policy Approval)** | **Internally Approved Design (Formal Client Signoff Pending)** | Historical `UAT-09` + Steps 7A-17 & 7B-08 Synthetic Browser UAT: Donation #29 (PKR 5k, `UAT-DON-GEN-001`) posted as DR-2026-00001 (J#62, Dr 11203 / Cr 43101, tagged GEN), MCB balance PKR 652,500, Fund-wise I&E & single-page print PASS; Restricted drafts Donation #30 (Zakat, GL 43102, ZKT Fund, PKR 2k, Draft) & Donation #31 (New FRC Project, GL 43105, DNR Fund, PKR 3k, Draft) verified; Negative validation tests PASS (ZKT/GRT rejected for General); Fund-wise I&E correctly excludes drafts; Default fund UI messaging corrected. Restricted posting, mandatory ZKT attribution, DNR sub-projects & expense ledgers pending client policy approval. |
| **8** | **Bank Reconciliation & Imprest Floats** | Statement CSV Parser, Auto/Manual Matching, Reversal Locks, Imprest Floats | Complete | PASS (28 Bank Rec tests, 15 Shared Bank tests, 6 Imprest tests across 3 suites) | **PASS (Steps 78AR & 79P Synthetic Documented)** | Internally Approved Design (Client Signoff Pending) | Step 78AR & 79P: Synthetic Browser UAT PASS — (1) Bank Rec: MCB Shared (Custodian Shahrah-e-Faisal) Rec #3, Stmt 27 Oct 2026, Stmt PKR 649,400 = Book PKR 649,400 (Diff 0.00), CV-2026-00001 (PKR 500) & BP-2026-00019 (PKR 100) matched, JV-2026-00001 (PKR 650,000) brought forward, obsolete line ignored with reason (Audit #759), approved by TEST Approver 29 Oct 2026 15:29:28 (Audit #763), UI locked. (2) Petty Cash Imprest: Float #1 (Shahrah-e-Faisal, PKR 15k, GL 11104), expense CP-2026-00010 (PKR 500 / J#49), replenishment CV-2026-00001 (PKR 500 / J#50), closing PKR 15,000, Audit #677, J#50 reversal locked by cleared bank line in Rec #3. Commercial statements & client signoff pending. |
| **9** | **Fixed Assets & Depreciation** | Asset Register, Straight-Line Schedule, Policy Block, Reversals | Complete (Blocked) | PASS (2 suites) | **PASS (Documented)** | **Pending Decision** | `UAT-11` (Policy block verified); Depreciation rates pending |
| **10** | **Inventory** | Item Master, Stock In/Out, Returns, Inter-Branch Transfers, Adjustments | Complete | PASS (2 suites) | **PASS (Documented)** | Approved | `UAT_EXECUTION_REPORT.md` (UAT-10, Double-submit prevention) |
| **11** | **Budgeting** | Multi-version Budgets, Revisions, Budget vs Actual Reporting | Complete | PASS (2 suites) | **PASS (Synthetic)** | Approved | `UAT_TEST_PLAN.md` (B1–B3), `BudgetTest` (65 KB suite) |
| **12** | **Payroll** | Employee Directory, Salary Structures, Advances & Recovery, Runs, Payslips, Salary Payments | Complete | PASS (34 tests in PayrollTest, 5 suites) | **PASS (Step 77Z Documented)** | **Pending Decision** | Step 77Z: ADV-000001 (PKR 100), Nov Run #1 (Gross 1k, Net 900, SV-2026-00001 / J#56), Salary BP-2026-00020 (PKR 900 / J#57); Paid status, GL 11401 closed, GL 21201 net 0, Bank 11203 PKR 648,500; Modal filtering fix verified |
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
* **Scope:** Encrypted Vendor Directory, Purchase Orders, Purchase Bills, Overbilling Guards, Payables Aging, Posted Purchase Bill Reversal UI, Reversed Bill Outstanding Correction, Cancel Draft Purchase Bill with Mandatory Reason & Audit Trail, PO Reservation Release on Cancelled Draft, Dynamic Purchase Bill Line Management (Add/Remove), Rejected Bill Balance & Exception Status Integrity, Fully Billed PO Controls, Vendor Payment Posting with Long Narration Column Boundary Safety, Cancelled Purchase Order Billing Authority Lock & UI Display Integrity.
* **Automated Evidence:** 100% PASS (Focused suites passing: `VendorPaymentPostingTest`, `PurchaseBillCancelDraftTest`, `PurchaseBillReversalTest`, `PurchaseBillDebitAccountEligibilityTest`, `PurchaseBillMakerCheckerTest`, `PurchaseBillLineManagementTest`, `PurchaseBillRejectedIntegrityTest`, `PurchaseOrderBillingControlsTest`, `PurchaseOrderCancelledDisplayTest`, `VendorPaymentBillSelectionTest`; 71 passed across 8 related procurement suites, 1,042/1,043 full regression baseline).
* **Manual Browser UAT:** **PASS (Synthetic Browser UAT — Steps 44–58, 6A-19A & 6A-28A Documented)**. Comprehensive synthetic browser UAT verified live across the complete Procurement / Purchase Order / Purchase Bill / Vendor Payment lifecycle:
  - **Historical Bill Reversal & Draft Cancellation (Steps 44–58):**
    - `DEMO-BILL-001` (PKR 12,000, Shahrah-e-Faisal branch): Posted bill partially paid, payment reversed, bill successfully reversed (`PB-2026-00002` and `PB-2026-00003` cross-linked; active outstanding PKR 0 "(Reversed)").
    - `DEMO-CANCEL-001` (PKR 1,000, Shahrah-e-Faisal branch): Successfully cancelled at Draft stage with mandatory reason and audit entry (`purchase_bills.cancelled`); active outstanding PKR 0, zero GL effect, linked PO reservation released.
  - **End-to-End Procurement Lifecycle (31 October 2026, Shahrah-e-Faisal):**
    - **Purchase Order Workflow:** `PO-2026-00001` for `DEMO ABC Stationery Supplier`, PKR 1,000.00 (5 units @ PKR 200.00). Created and submitted by Admin, independently approved by `TEST Approver`, issued by Admin.
    - **Excess Bill Rejection & PO Balance Restoration:** Bill `UAT-BILL-PO-001` (Purchase Bill #5, PKR 1,200.00 against PKR 1,000.00 PO with overbilling exception) returned/rejected; correctly excluded from billed totals, preserving historical exception display while restoring PO remaining value to PKR 1,000.00 (5 units).
    - **Dynamic Line Editing & Purchase Bill Posting:** Bill `UAT-BILL-PO-002` (Purchase Bill #6, PKR 1,000.00). Browser UAT verified dynamic Add/Remove (×) bill lines and row re-indexing; bill approved and posted as `PB-2026-00004` (Journal #58):
      - Debit `53209 — Office Stationery Expense`: PKR 1,000.00
      - Credit `21101 — Trade Creditors / Suppliers`: PKR 1,000.00
    - **Vendor Payment Disbursement & Journal Engine Posting:** Vendor Payment #28 (`UAT-VPAY-PO002-001`, PKR 1,000.00) created and submitted by Admin, approved by `TEST Approver`, marked as disbursed by Admin, and posted to Journal Engine as `BP-2026-00021` (Journal #61):
      - Debit `21101 — Trade Creditors / Suppliers`: PKR 1,000.00
      - Credit `11203 — Bank - Rehab Centre Account` (MCB Bank Account): PKR 1,000.00
      - Source narration (311 characters) 100% preserved on payment source record and journal header; concise line descriptions generated within 255-character database limit with audit traceability (`[Ref: UAT-VPAY-PO002-001]`).
    - **Financial Balances & General Ledger Verification:**
      - Purchase Bill #6 active outstanding balance reduced to PKR 0.00.
      - MCB Bank posted balance reflected disbursement, decreasing from PKR 648,500.00 to PKR 647,500.00.
      - Trade Creditors GL (`21101`) verified: opening balance PKR 76,300.00 Credit, PKR 1,000.00 credit (Bill #6), PKR 1,000.00 debit (Payment #28), closing balance restored to PKR 76,300.00 Credit.
    - **PO Fully Billed State, Closure & Audit Trail:**
      - `PO-2026-00001` automatically transitioned to "Fully Billed" with PKR 0.00 / 0 quantity remaining; bill creation disabled; closed by Admin with Closure Audit Entry #812 (`purchase_orders.closed`, Admin, 31 Oct 2026 13:53:41, billed PKR 1,000.00; approval history records `Issued -> Closed`). UI locked against further workflow actions.
  - **Purchase Order Cancellation & Non-Billable UI Integrity (`PO-2026-00002` — 31 October 2026):**
    - **Order Details & Commercial Terms:** `PO-2026-00002` (ID #2) for `DEMO ABC Stationery Supplier`, Shahrah-e-Faisal branch, original order total PKR 400.00 (2 units @ PKR 200.00).
    - **Workflow Lifecycle:** Draft → Submitted (Admin) → Approved (`TEST Approver`) → Issued (Admin) → Cancelled (Admin with recorded cancellation reason).
    - **Cancellation State & Audit Trail:** Final status permanently marked `Cancelled`. Audit Entry #817 logged (`purchase_orders.cancelled`, 31 October 2026, 14:06:51, Admin); full approval history and cancellation reason preserved.
    - **KPI Cards & Non-Billable Balances:**
      - Order Total: PKR 400.00
      - Billed Amount: PKR 0.00
      - Available for Billing: PKR 0.00 (historical unbilled amounts no longer misleadingly presented as active billing authority)
      - Historical Unbilled at Cancellation: PKR 400.00
    - **Order Lines Table Display:** Column header dynamically labeled `Unbilled Qty at Cancellation`; line 1 clearly displays `2 pcs` with status subtext `Not currently billable`.
    - **Linked Bills & Billing Authority Guard:** Zero purchase bills linked. Empty linked bills section displays: *"No purchase bills were created before cancellation. Billing is unavailable for cancelled purchase orders."*
    - **UI & Controller Enforcement:** "Create bill from PO" button hidden on show page; direct create route URL (`/purchase-bills/create?purchase_order=2`) returns HTTP 403 Forbidden; backend validation rejects unauthorized bill creation.
    - **Data & Model Integrity:** Stored calculations, PO total, billed amount, and remaining amount preserved in database without mutation.
* **Pending Observations & Client Prerequisites:**
  1. **Synthetic vs Live Procurement:** UAT executed successfully on synthetic DEMO data and test vendor; live procurement with commercial supplier terms, actual delivery receipts, and genuine vendor tax invoices remains an operational phase.
  2. **External Bank Transfer Execution:** Vendor Payment #28 posted within the accounting software; actual commercial banking transfer execution (cheque / online portal) remains an external treasury task.
  3. **Formal Client Signoff:** Formal stakeholder acceptance of the procurement authorization matrix and closure rules.
* **Client Approval:** **Internally Approved Design (Formal Client Signoff Pending)**. Synthetic browser UAT complete; live commercial procurement rollout and formal client signoff remain pending.
* **Post-UAT Changes:**
  - Added secure Posted Purchase Bill Reversal UI with period selection, date validation, and payment dependency checks.
  - Fixed reversed purchase bill outstanding calculation and display.
  - Added Cancel Draft Purchase Bill workflow with mandatory reason (5–2000 chars), audit logging, and `purchase_bills.cancel` permission.
  - Updated Purchase Order `activeBills()` and `billedQuantity()` to exclude cancelled and rejected bills.
  - Added dynamic Remove (×) button on Purchase Bill create form with row re-indexing and 1-line minimum constraint.
  - Fixed Rejected Purchase Bill exception badge and PO remaining balance restoration.
  - Implemented Fully Billed badge and action guard on Purchase Orders to prevent duplicate billing.
  - Resolved `journal_lines.description` column boundary bug (HTTP 500) by safely generating 255-char line descriptions with reference traceability while preserving full 311+ char source narration.
  - Corrected Cancelled Purchase Order display to prevent misleading billing authority: added `availableForBillingAmount()` model method returning PKR 0.00 for cancelled status; updated KPI cards to show Available for Billing PKR 0.00 and Historical Unbilled at Cancellation PKR 400.00; updated lines table to display "Unbilled Qty at Cancellation" (Not currently billable); updated empty bills section with cancellation notice; and preserved stored database totals without mutation.

### Phase 7: Donations & Funds
* **Scope:** Donation Categories, Donation Receipts (DR), Printable Acknowledgment Receipts, Fund Accounting & Restricted Fund Enforcement.
* **Automated Evidence:** 100% PASS (14 tests in `OperationalFinanceTest`, 32 tests across `FundAccountingTest` and `TransactionPostButtonVisibilityTest`).
* **Manual Browser UAT:** **IN PROGRESS (General Donation PASS — Step 7A-17; Restricted Drafts & Validation PASS — Step 7B-08; Restricted Posting Pending Policy Approval)**.
  - **Verified General Donation Evidence (Step 7A-17 — 31 October 2026):**
    - **Record & Entity:** Operational Transaction #29, Reference `UAT-DON-GEN-001`, Branch: Shahrah-e-Faisal.
    - **Donor & Category:** Donor: DEMO UAT Donor, Category: General Donation, Income GL: 43101 General Donations.
    - **Fund & Financial Account:** Fund: `GEN — General Fund` (ID 1), Bank: MCB Bank Account (GL 11203).
    - **Amount & Status Progression:** Amount PKR 5,000.00. Created at Draft (zero GL balance impact, `journal_entry_id: null`). Posted directly by Admin as authorized by `donations.post` permission. Final Status: `Posted`.
    - **Journal Entry:** `DR-2026-00001` (Journal #62) generated:
      - Debit: 11203 MCB Bank Account — PKR 5,000.00 (Fund: GEN)
      - Credit: 43101 General Donations — PKR 5,000.00 (Fund: GEN)
      - Total Debit = Total Credit = PKR 5,000.00 (Balanced entry, zero variance).
    - **Bank & Register Verification:** MCB Bank Account posted balance updated to PKR 652,500.00. Entry verified in Bank Book and Donation Register.
    - **Fund-Wise Reporting:** Fund-wise Income & Expenditure report verified for selected date and Shahrah-e-Faisal branch: GEN donation income PKR 5,000.00, expenses PKR 0.00.
    - **Audit Trail:** Immutable Audit Entry #821 recorded for event `donations.posted` with actor, timestamp, branch, and metadata.
    - **Printable Acknowledgment:** `/finance/transaction/29/acknowledgment` verified in Chrome Print Preview — generates a single portrait page with FRC logo, organisation name, receipt title, donor, date, amount, category, branch, mode, reference; internal UI headings ("ACCOUNTING WORKSPACE" and duplicate title) suppressed via `@media print` (`d-print-none`).
  - **Verified Restricted Donation Evidence (Step 7B-08 — 31 October 2026):**
    - **Donation #30 (Zakat Restricted Draft):**
      - Category: Zakat, Income GL: 43102 (Zakat / Charity Donations), Fund: `ZKT — Zakat / Charity Fund` (ID 3), Amount: PKR 2,000.00.
      - Status: `Draft`, Reference: `UAT-DON-ZKT-001`, Bank: MCB Bank Account (GL 11203).
      - No posted journal entry created (`journal_entry_id: null`), zero financial statement impact.
    - **Donation #31 (Project-Specific Restricted Draft):**
      - Category: New FRC Project Donation, Income GL: 43105 (New FRC Project Donations), Fund: `DNR — Donor-Restricted Fund` (ID 2), Amount: PKR 3,000.00.
      - Status: `Draft`, Reference: `UAT-DON-PROJECT-001`, Bank: MCB Bank Account (GL 11203).
      - No posted journal entry created (`journal_entry_id: null`), zero financial statement impact.
    - **Negative Validation Tests (Browser Verified):**
      - Incompatible Fund/Category: General Donation with `ZKT` restricted fund rejected by backend validator with: *"Restricted fund ZKT (Zakat / Charity Fund) can only receive Zakat donations. It cannot be selected for General Donation."*
      - Grant Fund Protection: General Donation with `GRT` contractual grant fund rejected with: *"Grant project fund GRT is reserved for contractual grants. Donations cannot be allocated to grant funds."*
    - **Default Fund UI Messaging (Browser Verified):**
      - Form `/finance/donation/create` displays `Use category default (GEN — General Fund)` when General Donation is selected.
      - Selecting Zakat or New FRC Project Donation dynamically updates option 0 to display `No default fund (select a fund or leave unallocated)`, eliminating misleading messaging and prompting explicit fund selection.
    - **Fund-Wise I&E Verification (Shahrah-e-Faisal, 31 October 2026):**
      - Both `ZKT` and `DNR` display zero posted income and zero expenditure; draft donations #30 and #31 are correctly excluded from posted fund totals.
    - **Restricted Fund Attribution Investigation:**
      - Confirmed that missing fund attribution currently posts under "No fund" on fund-wise statements without increasing restricted fund balances.
      - Confirmed that `Fund` model architecture natively supports hierarchical project sub-funds via `parent_id` (e.g. `DNR-PROJECT-1` under parent `DNR`).
      - Confirmed that no database fund mappings or category defaults were mutated during UAT investigation.
  - **Defects Investigated & Resolved (8 Defect Fixes Verified):**
    1. **Restricted Funds List Display:** Corrected misleading "Any" display under permitted charge ledgers on `/funds` table when no ledgers were configured.
    2. **Restricted Fund Form Guidance:** Reconciled contradictory helper text on `/funds/{id}/edit` with backend deny-by-default enforcement for restricted funds.
    3. **Permitted Charge Ledger Selector Scope:** Filtered UI selector and enforced backend validation on fund update/create requests so that cash, petty cash, bank, receivables, and other non-charge accounts cannot be selected as permitted charge ledgers (only expense and eligible capital asset accounts allowed).
    4. **Category Default Fund Auto-Selection:** Fixed `/finance/donation/create` so category's configured default fund (GEN for General Donation) is visually auto-selected on form load and category selection without overriding manual user adjustments.
    5. **Category / Fund Incompatibility Guards:** Added validation checks to reject inappropriate category/fund combinations (e.g., General Donation with ZKT restricted fund, donations to contractual grant funds).
    6. **Donation Detail Screen Information:** Eager-loaded `donationCategory.incomeAccount` and displayed Donation Category and mapped Income GL (43101) alongside Fund and Bank on `/finance/transaction/{id}` detail view.
    7. **Print Layout Heading Suppression:** Suppressed duplicate internal workspace header and title in print media on `/finance/transaction/{id}/acknowledgment` using layout `@yield('page-header-class')` and `d-print-none`.
    8. **Donation Create Form Default Fund Option Clarification:** Replaced misleading static "Use the donation category default fund" option with dynamic label showing category's configured default fund or `No default fund (select a fund or leave unallocated)`.
  - **Client-Confirmed Policy Decisions (CR-2026-006 Implemented — 31 October 2026):**
    - **Policy 1 — Mandatory ZKT:** Zakat donations must use the ZKT — Zakat / Charity Fund. Enforced server-side at create and post boundaries. Category Zakat preselects ZKT automatically.
    - **Policy 2 — Project-Specific Funds:** Every New FRC Project Donation must use an active child fund under parent DNR. Generic parent DNR alone is rejected server-side at create and post. Finance Manager / users holding `funds.manage` authorized to create project child funds. Historical draft #31 with parent DNR safely preserved and prevented from posting until corrected.
    - **Policy 3 — Restricted Expense Ledger:** GL 52201 (Therapy Materials & Consumables) approved as a temporary synthetic UAT allow-case on dedicated UAT restricted funds; deny-by-default strictly preserved for all other unconfigured restricted charges.
    - **Policy 4 — Donation Workflow:** Retained current Draft → Authorized Posting workflow with `donations.post` permission and branch authorization. No Maker-Checker required for receipts.
    - **Policy 5 — Fund Compatibility:** General Donation → ZKT rejected; General Donation → GRT rejected; General Donation → DNR/project child fund requires attached donor-written restriction; General Donation → GEN unrestricted.
    - **Policy 6 — Default Funds:** General Donation (GL 43101) → GEN; Zakat (GL 43102) → ZKT; New FRC Project Donation (GL 43105) → explicitly selected DNR child fund (no generic parent DNR default).
  - **Historical No Fund PKR 16,000 Investigation (Step 7B-29 — 2 November 2026):**
    - Traced exact source lines contributing to the PKR 16,000 "No fund" expenditure on `/fund-reports/balance-summary`.
    - Confirmed composition: 6 historical UAT general expenses from Phases 2–6 (Journals #2, #14, #19, #49, #56, #58) totaling PKR 16,000, plus 15 netting reversal pairs (PKR 0 net impact).
    - Verified that zero restricted donations or restricted project expenses were lost into No Fund.
    - Verified that report engine calculation is mathematically accurate; No Fund expenditure represents historical transactions recorded prior to fund allocation enforcement.
  - **Option B Implementation & Full Gap Closure (Steps 7B-30 & 7B-30B / CR-2026-008 — 2 November 2026):**
    - **Approved Rule:** Future ordinary unrestricted operating expenses default to `GEN — General Fund` to prevent unintentional accumulation under `No Fund`.
    - **UI Preselection:** Preselected `GEN` on operational expense and petty cash creation form (`/finance/transactions/create?type=expense|petty_cash`), direct payments (`/finance/payments/create`), purchase bills (`/purchase-bills/create`), and clear labeling on manual journals (`/journals/_form`).
    - **Backend Fallback:** Enforced in `OperationalTransactionService::applyFund()` and `post()`, `PurchaseBillService::create()`, `update()`, and `post()`, `PayrollRunService::createDraft()` and `post()`, and `JournalWorkflowService::replaceLines()`.
    - **Remaining Gap Closures (Step 7B-30B):**
      1. *Purchase Bills:* Additive migration `2026_11_02_000001_add_fund_id_to_purchase_bills_and_payroll_runs_tables` added nullable `fund_id` to `purchase_bills` and `purchase_bill_lines`. Form preselects GEN, backend defaults omitted fund to GEN, validates restricted funds, attributes expense debits to GEN, and keeps AP liability unfunded.
      2. *Payroll Runs:* Additive migration added nullable `fund_id` to `payroll_runs`. Newly created runs default to GEN, salary expense debits are attributed to GEN while net salary payable and deduction liabilities retain null fund. Historical Payroll Run #1 (`SV-2026-00001`) preserved completely untouched.
      3. *Manual Journals:* Eligible expense debits without fund auto-default to GEN; balance-sheet lines (assets, liabilities, equity, clearing) and credit lines retain null fund; explicit restricted fund validation enforced.
    - **Restricted Funds Preserved:** Explicit restricted fund selection (e.g. `DNR-UAT1`) is preserved without replacement; permitted GL restrictions are strictly enforced across all modules.
    - **Donation Policy Preserved:** General Donation defaults to GEN; Zakat requires mandatory explicit ZKT; Project Donation requires active DNR child fund; restricted general donation requires written document attachment.
    - **Zero Historical Backfill:** Historical posted journals, historical transactions, and the historical `No Fund` PKR 16,000 balance remain completely untouched.
    - **Automated QA:** 100% PASS (6 tests in `OptionBRemainingGapsTest`, 7 tests in `DefaultGenOperatingExpenseFundTest`, 47 tests in `PurchaseBillTest`, 34 tests in `PayrollTest`, 23 tests in `JournalEngineTest`, 29 tests in `FundAccountingTest`).
* **Client Approval:** **Internally Approved Design for UAT (Formal Client Signoff Pending)**.
* **Outstanding Dependencies:** Manual browser retest of Option B full expense workflows, and formal client signoff on Option B policy. Separate tracking review of Zakat vs General Charity (GL 43102 naming) submitted for client decision.

### Phase 8: Bank Reconciliation & Petty Cash Imprest
* **Scope:** Bank Statement CSV Parser, Automated/Manual Reconciliation, Reversal Lock on Reconciled Journals, Branch Imprest Floats, Replenishment.
* **Automated Evidence:** 100% PASS (28 tests in `BankReconciliationTest`, 15 tests in `SharedBankAccountTest`, 6 tests in `PettyCashImprestTest`).
* **Manual Browser UAT:** **PASS (Synthetic Browser UAT — Steps 78AR & 79P Documented)**. Comprehensive synthetic browser UAT verified live for both Bank Reconciliation and Petty Cash Imprest workflows:
  - **Bank Reconciliation (PASS — Step 78AR Documented):** Comprehensive end-to-end synthetic browser UAT verified live:
    - **Reconciliation Target:** Reconciliation #3 for `MCB Bank Account — All Branches Shared` (Custodian Branch: Shahrah-e-Faisal), imported statement dated 27 October 2026.
    - **Financial Balances & Zero Difference:**
      - Bank Statement Closing Balance: PKR 649,400.00
      - Bank Book Balance: PKR 649,400.00
      - Reconciliation Difference: PKR 0.00 (Zero variance preserved).
    - **Cleared & Matched Entries:**
      - Opening balance journal `JV-2026-00001` (PKR 650,000.00) verified as cleared brought forward with audit justification.
      - Statement withdrawal PKR 500.00 matched to contra voucher `CV-2026-00001`.
      - Statement withdrawal PKR 100.00 matched to bank payment `BP-2026-00019` (advance disbursement).
    - **Statement Ignore Workflow & Audit Trail:**
      - Obsolete synthetic statement line PKR 100 (`P5-UAT-MCB-SHARED-001`, legacy unreversed book match attempt) ignored with mandatory written reason.
      - Audit Entry #759 logged: `bank_reconciliation.line_ignored` with actor (Admin), timestamp, reconciliation ID (#3), statement line ID (1), bank account (MCB Bank Account), reference (`P5-UAT-MCB-SHARED-001`), amount (100.00), description, and verified written justification.
    - **Defects Resolved & Browser Retested:**
      1. *Reversed Journal Matching Candidate Bug:* Excluded reversed journals (e.g. `BR-2026-00003`) and reversal vouchers (`BR-2026-00004`) from auto-match and manual dropdown candidate queries; only currently posted, unreversed book entries appear.
      2. *Statement Date/Balance Auto-Fill Bug:* Selecting an imported statement on `/bank-reconciliation/create` reliably auto-populates the statement period end date and exact closing balance; resets appropriately if bank account changes.
      3. *Shared-Bank TEST Approver 403 Authorization Blocker:* Resolved authorization logic in `ReconciliationService::authorizeAccount()` and `BankReconciliationController::bankAccounts()` so custodian branch approvers (`TEST Approver`) can view, list, and approve shared bank reconciliations while strictly retaining centralized preparation (`branches.view_all`), cross-branch isolation (unauthorized branches receive 403), and maker-checker segregation.
    - **Maker-Checker & Approval Execution:**
      - Prepared and submitted by Admin (Maker).
      - Maker-checker strictly prevented Admin from approving the submitted reconciliation.
      - Independently reviewed, approved, and locked by `TEST Approver` (Checker) on 29 October 2026 at 15:29:28.
      - UI status transitioned to `Approved` and locked against post-approval modifications.
    - **Approval Audit Trail:**
      - Audit Entry #763 logged: `bank_reconciliation.approved` with actor (`TEST Approver`), timestamp, reconciliation ID (#3), and zero-difference financial figures (`statement_balance: 649400.00`, `book_balance: 649400.00`, `difference: 0.00`).
  - **Petty Cash Imprest Floats & Replenishment (PASS — Step 79P Documented):** Comprehensive synthetic browser UAT verified live:
    - **Branch & Float Configuration:** Branch Shahrah-e-Faisal; Imprest Float #1 of PKR 15,000.00 assigned to custodian `P6 UAT Petty Cash Custodian`.
    - **Ledger Mapping:** Linked to GL `11104 — Petty Cash - Rehab Centre` (pending client master-data naming/ownership confirmation for Shahrah-e-Faisal vs Rehab Centre).
    - **Expense Disbursement:** Verified via Unified Payments with Petty Cash source, branch account, and expense GL selection; Expense `CP-2026-00010` posted as Journal #49 (Debit Electricity `53102` PKR 500 / Credit Petty Cash `11104` PKR 500).
    - **Replenishment Workflow & Audit:**
      - Replenishment #1 initiated for PKR 500 covering one voucher; Audit Entry #677 logged: `imprest.replenishment_requested` by Admin for PKR 500, linking Journal ID 50.
      - Generated Contra Voucher `CV-2026-00001` (Journal #50): Debit Petty Cash `11104` PKR 500 / Credit MCB Bank Account `11203` PKR 500.
      - Journal #50 4-eye lifecycle audit verified: Created by Admin (27 Oct 2026 17:00:56), Submitted by Admin (17:04:16), Approved by `TEST Approver` (17:05:01), Posted by `TEST Accountant` (17:08:16).
    - **Imprest Statement & Balance Integrity:** Statement reflects initial float, expenses, historical replenishments and reversal records, with closing balance restored to full float amount of PKR 15,000.00.
    - **Reconciliation & Reversal Protection:** Replenishment Journal #50 bank credit line was matched and cleared in approved Bank Reconciliation #3, establishing end-to-end integration and protecting Journal #50 from direct post-reconciliation reversal.
* **Pending Observations & Client Prerequisites:**
  1. **Commercial Bank Statement Rollout:** Current UAT verified via synthetic CSV statement; real-world live commercial bank statement imports and formats (e.g. MT940 / commercial CSV) require production validation.
  2. **External Bank Settlement Reconciliation:** Verification of live bank clearing timing vs book timing remains an operational client responsibility.
  3. **Master Data Ledger Naming Confirmation:** Shahrah-e-Faisal / Rehab Centre GL account naming and branch ownership confirmation for GL `11104 — Petty Cash - Rehab Centre`.
  4. **Client Signoff:** Formal stakeholder signoff on shared account custodian branch ownership rules and imprest policies.
* **Client Approval:** **Internally Approved Design (Formal Client Signoff Pending)**. Synthetic browser UAT complete; live commercial bank rollout, external settlement verification, and formal client signoff remain pending.
* **Post-UAT Changes:** Resolved reversed journal candidate filtering, create-form statement auto-fill, mandatory ignore reason audit trail, and shared-bank custodian branch approval authorization.

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
* **Scope:** Employee Profiles, Salary Structures, Staff Advances & Deductions, Payroll Runs, Staff Advance Recovery, Individual & Batch Payslips, Salary Payments (Bank/Cash), General Ledger Integration.
* **Automated Evidence:** 100% PASS (34 tests in `PayrollTest`, `PayrollImportTest`, `PayslipTest`, `StaffAdvanceTest`, `PayrollReportTest`). Includes regression tests for Salary Payment modal Bank/Cash account filtering, branch scoping, and backend validation against forged account submissions.
* **Manual Browser UAT:** **PASS (Step 77Z Documented)**. Comprehensive end-to-end lifecycle verified live in browser:
  - **Employee Advance Disbursement (`ADV-000001`):** PKR 100 approved and disbursed via Bank Payment #26 (`BP-2026-00019`), debiting Staff Advances (`11401`) and crediting MCB Bank Account (`11203`).
  - **November 2026 Payroll Run #1:** Run generated with Gross salary PKR 1,000, advance recovery deduction PKR 100, and Net salary payable PKR 900.
  - **Salary Accrual Posting:** Posted as Salary Voucher `SV-2026-00001` (Journal #56), debiting Salary Expense (`51101`) PKR 1,000, crediting Staff Advances (`11401`) PKR 100 (fully settling `ADV-000001`), and crediting Salaries Payable (`21201`) PKR 900.
  - **Salary Bank Payment #27 (`BP-2026-00020`):** Draft payment raised for PKR 900, submitted, approved, disbursed, and posted (Journal #57), debiting Salaries Payable (`21201`) PKR 900 and crediting MCB Bank Account (`11203`) PKR 900.
  - **Automated Lifecycle Update:** Payroll Run #1 status automatically transitioned to `Paid` upon complete settlement of outstanding net salary.
  - **General Ledger & Bank Book Verification:**
    - Staff Advances GL (`11401`): Closing balance returned to PKR 0.0000 after deduction recovery.
    - Salaries Payable GL (`21201`): November accrual (Credit PKR 900) and salary payment (Debit PKR 900) completely offset to zero net variance for this period.
    - Bank Book & GL (`11203`): Salary payment credit PKR 900 confirmed; final bank GL balance reconciled at PKR 648,500.
  - **Modal Account Filtering & Security Guard:** Verified in browser that Bank Payment type displays and accepts only active authorized Bank accounts, Cash Payment type displays only eligible Cash in Hand accounts, Petty Cash accounts are strictly excluded, switching types dynamically resets incompatible accounts, and backend validation blocks forged or mismatched accounts.
* **Pending Observations & Source Reconciliations:**
  1. **Salaries Payable Opening Balance:** Existing Salaries Payable (`21201`) opening credit balance of PKR 55,000 requires source reconciliation.
  2. **Controlled UAT Accounting Date:** Payroll/payment transaction date (30 November 2026) is controlled, future-dated synthetic UAT test data.
  3. **Posted Payment Reversal Date Guards:** Posted payment reversal form's date defaults and backend date guard require separate review.
  4. **Client Signoff:** Actual external bank settlement and final client production signoff are pending live rollout.
* **Client Approval:** **PENDING CLIENT DECISION & PRODUCTION SIGNOFF** (Production salary structures, real attendance/tax rules, bank integration).
* **Post-UAT Changes:** Added strict Bank/Cash account filtering and backend validation in salary payment modal, preventing mismatched account types, inactive/cross-branch accounts, and petty cash selection.

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

Manual browser testing has progressed through **Phase 5 (Receipts, Payments, Expenses)** during Steps 375–385, **Phase 6 (Purchasing & Payables)** during Steps 44–58, **Phase 7 (Donations & Funds)** during Step 7A-17, **Phase 8 (Bank Reconciliation & Petty Cash Imprest)** during Steps 78AR & 79P, and **Phase 12 (Payroll, Advances & Salary Payments)** during Step 77Z. All five phases have documented functional synthetic UAT verification, while formal full-phase closure remains pending final stakeholder signoff and prerequisites:

1. **Phase 2 (Setup):** Blocked from live use until the client approves the 62 flagged accounts.
2. **Phase 3 (Opening Balances):** Blocked from live use until real branch opening balances are provided.
3. **Phase 5 (Payments):** Unified Payments browser UAT verified; live high-value approvals (> PKR 150k) await formal Director appointment.
4. **Phase 6 (Purchasing):** End-to-end synthetic browser UAT verified for Purchase Order (`PO-2026-00001`), excess bill rejection with balance restoration (`UAT-BILL-PO-001`), dynamic bill line editing and posting (`UAT-BILL-PO-002` / `PB-2026-00004`), vendor payment disbursement and posting (`BP-2026-00021`), bill settlement to PKR 0, MCB balance reduction to PKR 647,500, PO fully billed state and closure (Audit #812), alongside Purchase Order cancellation and non-billable UI integrity on `PO-2026-00002` (PKR 400.00, Audit #817, Available for Billing PKR 0.00, HTTP 403 billing lock), and previous bill reversal/draft cancellation; live commercial procurement rollout and formal client signoff pending.
5. **Phase 7 (Donations & Funds):** General Donation end-to-end browser UAT verified for Donation #29 (`DR-2026-00001` / Journal #62, PKR 5,000.00, MCB balance PKR 652,500.00, Fund GEN, Bank Book and Donation Register reconciled, Fund-wise I&E, single-page portrait acknowledgment print PASS, Audit #821); restricted donation drafts Donation #30 (ZKT) & Donation #31 (DNR) verified in Draft with zero GL impact and fund-wise I&E draft exclusion; negative category/fund validations PASS; default fund UI messaging corrected (8 defect fixes verified); Step 7B-15A expense form validation preservation verified (GL 52101 preserved on validation error, unpermitted charge on DNR-UAT1 blocked); Step 7B-15C Operational Transaction Cancel Draft workflow implemented (CR-2026-007, 13 automated tests passed; Expense #34 retained in Draft for manual browser UAT cancellation); Step 7C-07A/B Grant Expense Liability Fund Attribution Consistency verified: accrued expense journal posting in `OperationalTransactionService` aligned with Purchase Bills and Payroll (debit expense gets fund, credit liability gets `null`), CR-2026-009 logged, 17 automated tests PASS, Expense #47 / Journal #70 reversal & re-posting protocol prepared under Maker-Checker governance; restricted donation posting, mandatory ZKT fund attribution, DNR project tracking, and formal client signoff pending.
6. **Phase 8 (Bank Reconciliation & Imprest Floats):** Synthetic browser UAT PASS for both Bank Reconciliation (Reconciliation #3 approved & locked with PKR 0.00 difference, maker-checker enforced, Audit #763) and Petty Cash Imprest (Float #1, CP-2026-00010, replenishment CV-2026-00001 / J#50 locked via bank rec clearance, Audit #677); real commercial bank statement rollout, external settlement verification, and GL 11104 naming confirmation pending.
7. **Phase 12 (Payroll):** Advance disbursement, payroll accrual, advance recovery, and salary payment verified in browser; opening balance reconciliation and client production signoff pending.

---

## 5. Next Recommended Testing Activity

Proceed with browser UAT for the next pending module in the original 15-phase order: **Phase 9 (Fixed Assets & Depreciation)** (asset register, straight-line depreciation schedule preview, monthly posting policy guards) or **Phase 10 (Inventory)** on the dedicated UAT environment (`http://127.0.0.1:8000`).
