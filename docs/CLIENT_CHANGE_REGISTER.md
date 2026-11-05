# Client Change Request (CR) Register

**Project:** FRC Accounting Software<br>
**Target Repository:** `E:\wamp64\www\frc-accounting-software`<br>
**Latest Baseline Commit:** `afa9f19`<br>
**Document Purpose:** Centralized, auditable log of all formal client-requested changes, enhancements, and policy adjustments beyond the original Milestones 1–8 baseline.

---

## 1. Change Request Management Protocol

Every future client request or functional modification must follow this lifecycle before being declared complete:

1. **Intake & Logging:** Assign a sequential `CR-2026-XXX` ID with request date and exact client wording.
2. **Impact & Phase Mapping:** Map the request to the affected software module and the original 15-Phase UAT Plan.
3. **Approval Gate:** Confirm stakeholder approval (Owner, Director, or Accountant) before code modifications.
4. **Implementation & Safety:** Apply minimal targeted code changes without violating core accounting rules (balanced journals, period locks, maker-checker separation, branch scoping).
5. **Automated QA:** Run focused Pest tests and full regression under SQLite `:memory:` isolation.
6. **Browser Retesting:** Perform manual verification on the dedicated UAT instance (`http://127.0.0.1:8000`).
7. **Git Commit Reference:** Record the exact Git commit SHA where the change was preserved.
8. **Closure:** Formally close the CR upon stakeholder acceptance.

---

## 2. Change Request Status Legend

* **Priority:** `Critical` (Blocks operations), `High` (Core accounting requirement), `Medium` (Operational enhancement), `Low` (Cosmetic / Nice-to-have).
* **Approval Status:** `Proposed` | `Under Review` | `Approved` | `Rejected` | `Deferred`.
* **Dev Status:** `Backlog` | `In Progress` | `Completed`.
* **Automated QA:** `Pending` | `Passed` | `Failed`.
* **Browser Retest:** `Required` | `Verified PASS` | `N/A`.
* **Closure:** `Open` | `Closed`.

---

## 3. Master Change Register Table

| CR ID | Date | Client Requirement Description | Affected Module | Original UAT Phase | Priority | Approval Status | Dev Status | Auto QA | Browser Retest | Git Commit | Closure Status |
|:---:|:---:|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **CR-2026-001** | 2026-10-24 | Consolidated One-Window Outgoing Payments workflow replacing separate cash, bank, and vendor payment screens. Includes purpose routing, director threshold approvals (> PKR 150k), and disbursement states. | Unified Payments | Phase 5 | High | Approved | Completed | Passed (8 suites) | Verified PASS (Step 385) | `31dfd1b` | Closed (UAT Verified) |
| **CR-2026-002** | 2026-10-26 | Posted Purchase Bill Reversal UI & Outstanding Display Correction. Added reverse form with period selection; corrected outstanding display to PKR 0 (Reversed); cross-linked original and reversal journals; added active payment guard. | Purchase Bills | Phase 6 | High | Approved | Completed | Passed (`PurchaseBillReversalTest`) | Verified PASS (Steps 44–51; DEMO-BILL-001 reversed, PB-2026-00002/3 linked, PKR 0 outstanding) | `afa9f19` | Closed (UAT Verified) |
| **CR-2026-003** | 2026-10-26 | Cancel Draft Purchase Bill Workflow. Added cancel draft action with mandatory reason (5–2000 chars); preserved record/lines/creator; logged cancellation actor/timestamp/reason in audit log; neutralized active outstanding to PKR 0 with zero GL effect; released linked PO reservations; blocked all downstream workflows. | Purchase Bills | Phase 6 | Medium | Approved | Completed | Passed (`PurchaseBillCancelDraftTest`) | Verified PASS (Steps 52–58; DEMO-CANCEL-001 cancelled at Draft, PKR 0 outstanding, no journal, audit log verified) | `afa9f19` | Closed (UAT Verified) |
| **CR-2026-004** | 2026-10-27 | Salary Payment Account Filtering & Backend Validation. Enforce account type (Bank vs Cash in Hand), exclude Petty Cash imprest float accounts from salary payments, dynamic account reset on payment type change, backend validation against forged requests. | Payroll & Payments | Phase 12 | High | Approved | Completed | Passed (`PayrollTest` 34 tests) | Verified PASS (Step 77Z; Browser UAT confirmed correct dropdown filtering, advance settlement, accrual, and payment) | Pending Commit | Closed (UAT Verified) |
| **CR-2026-005** | 2026-10-29 | Bank Reconciliation Candidate Filtering, Statement Auto-Fill, Mandatory Ignore Reason Audit Trail, and Shared-Bank Custodian Approval Authorization. Excluded reversed journals and reversal vouchers from candidate matching; auto-populated period end date and closing balance from imported statement; enforced mandatory written reason on statement line ignore with Audit #759; authorized custodian branch approvers (`TEST Approver`) to list, view, and approve shared bank reconciliations while enforcing maker-checker and cross-branch isolation (Audit #763). *(Internal development change approval to resolve UAT defect blockers; formal client policy signoff pending).* | Bank Reconciliation | Phase 8 | High | Approved (Internal Dev) | Completed | Passed (`BankReconciliationTest` 28 tests, `SharedBankAccountTest` 15 tests) | Verified PASS (Step 78AR; Reconciliation #3 approved by TEST Approver on 29 Oct 2026 15:29:28, zero difference, maker-checker and cross-branch isolation verified) | Pending Commit | Closed (UAT Verified) |
| **CR-2026-006** | 2026-10-31 | Phase 7 Client-Confirmed Donation Policies & Restricted Fund Controls: (1) Mandatory ZKT fund for Zakat donations at create and post boundaries; (2) New FRC Project Donations require active child fund under DNR (parent DNR alone rejected); (3) GL 52201 temporary synthetic UAT allow-case configured on dedicated UAT fund while preserving deny-by-default; (4) Retained Draft → Authorized Posting donation workflow without Maker-Checker; (5) Fund compatibility guards (General → ZKT/GRT rejected; General → DNR requires attached donor-written restriction; General → GEN allowed); (6) Default funds configured (General → GEN, Zakat → ZKT, Project Donation → explicit selection required). | Donations & Funds | Phase 7 | Critical | Approved (Client Decisions) | Completed | Passed (41 tests: `FundAccountingTest`, `OperationalFinanceTest`) | Required (Manual Browser Retest) | Pending Commit | Open (Awaiting Browser Retest) |
| **CR-2026-007** | 2026-11-01 | Operational Transaction Cancel Draft Workflow (consistent with CR-2026-003 Purchase Bill Cancel Draft). Added cancel draft action with mandatory reason (5–2000 chars); permissions-based authorization (`expenses.cancel`, `donations.cancel`, etc.); branch access guards; preserved records, party, reference, amounts with zero journal/GL impact; auditable tracking in audit log; blocked cancelled transactions from submit/approve/disburse/post; released reserved balances for vendor payments; displayed cancellation details on detail view. *(Approved for implementation during UAT; formal client signoff pending).* | Operational Finance | Phase 7 / 5 | Medium | Approved (UAT Enhancement) | Completed | Passed (`OperationalTransactionCancelDraftTest` 13 tests) | Required (Manual Browser Retest) | Pending Commit | Open (Awaiting Browser Retest) |
| **CR-2026-008** | 2026-11-02 | Default GEN Fund for Future General Operating Expenses (Option B) & Gap Closures. Future ordinary unrestricted operating expenses default to `GEN — General Fund` on creation forms (`transactions/create`, `payments/create`, `purchase-bills/create`, `journals/_form`) and backend services (`OperationalTransactionService`, `PurchaseBillService`, `PayrollRunService`, `JournalWorkflowService`). Step 7B-30B closed all three remaining paths: (1) Purchase Bills: additive migration added `fund_id` to `purchase_bills` and `purchase_bill_lines`, form preselects GEN, backend defaults omitted fund to GEN, validates restricted funds, attributes expense debits to GEN, and keeps AP liability unfunded; (2) Payroll: additive migration added `fund_id` to `payroll_runs`, new runs default to GEN, salary expense debits attributed to GEN while net salary payable and deduction liabilities retain null fund, historical Payroll Run #1 (`SV-2026-00001`) preserved; (3) Manual Journals: eligible expense debits without fund auto-default to GEN, balance-sheet and credit lines retain null fund, restricted validation enforced. Preserves historical `No Fund` PKR 16,000, MCB Bank balance PKR 657,500, and all prior records. *(Approved for UAT implementation; formal client signoff pending).* | Operational Finance, Procurement, Payroll & Funds | Phase 7 | High | Approved for UAT | Completed | Passed (`OptionBRemainingGapsTest` 6 tests, `DefaultGenOperatingExpenseFundTest` 7 tests, 133 regression tests) | Required (Manual Browser Retest) | Pending Commit | Open (Formal Client Signoff Pending) |
| **CR-2026-009** | 2026-11-05 | Accrued Expense Liability Fund Attribution Consistency Fix (Step 7C-07A/B). Aligned accrued expense journal posting in `OperationalTransactionService` with `PurchaseBillService` and `PayrollRunService`: debit expense line carries restricted fund while credit liability line (`21101 Trade Creditors / Suppliers`) receives `fund_id = null`. Resolves fund-wise imbalance upon payable settlement in `UnifiedPaymentService`. Reversal and re-posting protocol documented for historical Journal #70 while preserving audit trail, Maker-Checker segregation of duties, grant budget (PKR 10,000), net utilisation (PKR 1,000 / 10%), and MCB Bank balance (PKR 662,500). *(Approved for UAT implementation; formal client signoff pending).* | Operational Finance & Funds | Phase 7 | High | Approved for UAT | Completed | Passed (`GrantExpenseLiabilityFundAttributionTest` 3 tests, 17 regression tests) | Required (Manual Browser Retest) | Pending Commit | Open (Formal Client Signoff Pending) |

*(Note: Additional client change entries will be appended sequentially starting from `CR-2026-010` upon receipt of formal client instructions).*

---

## 4. Pending Client Policy Items (Awaiting Formal CR / Instructions)

The following documented business decisions (`docs/CLIENT_POLICY_DECISIONS.md`) are currently open and may convert into Change Requests once decided by the client:

1. **COA Row Decisions:** Decisions on 27 School accounts and 11 signal accounts in `COA_APPROVAL_SUMMARY.md`.
2. **Cash Overdraft / Negative Cash:** Policy decision on whether an automatic hard stop should be implemented on payments or cash transfers.
3. **Split Payment Thresholds:** Whether multiple payments to one payee totaling > PKR 150,000 should require Director approval.
4. **Receipt Reversal Authority:** Whether reversing a fee or donation receipt above PKR 150,000 requires Director approval.
5. **Salary Visibility Policy:** Final client decision on whether the Management role should retain sensitive salary viewing access.
6. **Salaries Payable Opening Balance:** Existing Salaries Payable (`21201`) opening credit balance of PKR 55,000 requires source reconciliation.
7. **Payment Reversal Date Guards:** Posted payment reversal form date defaults and backend date guards require separate review.
8. **Shahrah-e-Faisal / Rehab Centre GL Account Naming:** Client confirmation on whether GL `11104` ("Petty Cash - Rehab Centre") should be formally renamed or retained as the Shahrah-e-Faisal branch petty cash account.
9. **Restricted Donations & Fund Policy (Phase 7):** Formal client decisions required on:
   - *Mandatory ZKT Attribution:* Whether to enforce `fund_id` as strictly required for Zakat donations to prevent unallocated posting under "No fund".
   - *Project Donation Tracking & Defaults:* Whether Project Donations require mandatory allocation to `DNR`, default category mapping, and whether individual projects are tracked via hierarchical sub-funds (`parent_id`) under `DNR` or transaction references.
   - *Permitted Expense Ledgers:* Formal stakeholder signoff on permitted charge ledgers for restricted funds (`ZKT`, `DNR`, `CAP`, `GRT`).
   - *Donation Approval Workflow:* Confirmation on whether incoming high-value or restricted donations require a multi-step Maker-Checker approval workflow before posting.
