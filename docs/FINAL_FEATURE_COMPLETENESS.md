# Final Feature Completeness

Status of every module against the confirmed scope (`PROJECT_SCOPE.md`: one FRC organisation with branches; the FRC Management Software owns branches, services, clients, enrollments and operational payments; Accounting owns everything financial). The original requirements document describes two separate entities and school fee management. Where it conflicts with the confirmed scope, the confirmed scope applies.

Status labels:
- **COMPLETE** — built and tested.
- **COMPLETE (posting blocked by policy/data)** — built and tested, but real use waits for a client decision or real data.
- **PARTIAL** — part of the requirement is built; the rest is listed.
- **OUT OF SCOPE** — excluded by the confirmed scope or by an earlier decision.

## 1. Modules

| # | Module | Status | Notes |
|---|---|---|---|
| 1 | Authentication | COMPLETE | Rate-limited login; inactive users refused; password reset. No two-factor login (optional in the original document) |
| 2 | Dashboard | COMPLETE | Figures from posted journals; payroll masked without sensitive access; ignores year-end closing journals |
| 3 | User management | COMPLETE | Users limited to the actor's branches; roles and permissions can be granted only by someone who holds them; Administrator protected |
| 4 | Roles and permissions | COMPLETE | Database-driven; protected roles |
| 5 | Branch access | COMPLETE | Server-side branch scoping on every record, report and export |
| 6 | FRC integration (branches, services) | COMPLETE (local) | Production deployment of the FRC endpoints not done |
| 7 | Fiscal years and periods | COMPLETE | Open, close and reopen; period locking; **year-end closing added in this release** |
| 8 | Chart of Accounts | COMPLETE (data) | The real chart is not imported; it waits for the client's row decisions |
| 9 | COA import and approval | COMPLETE | Preview, row decisions with reasons, parent checks, heading 215 kept for 21502/21503, import of approved rows only |
| 10 | GL mappings | COMPLETE (data) | Includes the new Accumulated Surplus key. FRC payments need a service-specific income ledger |
| 11 | Cash and bank accounts | COMPLETE | One ledger may serve one account per branch; balance per account |
| 12 | Journal vouchers | COMPLETE | Includes CV contra journals between cash and bank; **discarding drafts added in this release** |
| 13 | Approval and posting | COMPLETE | Maker-checker, now also excluding whoever edited the draft |
| 14 | Journal reversals | COMPLETE | Linked opposite journal; both stay visible |
| 15 | General Ledger | COMPLETE | Branch and service filters; exports |
| 16 | Trial Balance | COMPLETE | **Balance-sheet balances now carry forward into the next fiscal year** |
| 17 | Cash receipts | COMPLETE | **Printable receipt added**. No PDF receipt; the browser prints to PDF |
| 18 | Bank receipts | COMPLETE | Same as 17 |
| 19 | Cash payments | COMPLETE | Director above threshold; disbursement before posting |
| 20 | Bank payments | COMPLETE | Same as 19 |
| 21 | Expense management | COMPLETE | Paid and accrued; expense heads are ledgers; Expense Register now linked in the sidebar |
| 22 | Vendors | COMPLETE | Bank details encrypted |
| 23 | Purchase orders | COMPLETE | Director above threshold; editor cannot approve |
| 24 | Purchase bills | COMPLETE | From PO; over-billing blocked |
| 25 | Accounts payable | COMPLETE | Payables & Aging; vendor ledger |
| 26 | Vendor payments | COMPLETE | Partial payments; over-payment blocked |
| 27 | Petty cash | PARTIAL | Petty cash vouchers with approval. No imprest float or replenishment screen; replenish with a CV journal |
| 28 | Donations | COMPLETE | Categories, receipts, acknowledgment; Donation Register now in the sidebar. No donor master (not required) |
| 29 | Fixed assets | PARTIAL | Register, documents, retire. Disposal gain/loss, transfer and impairment are not built (excluded since Milestone 7) |
| 30 | Depreciation | COMPLETE (posting blocked by policy) | Preview, approval, posting, reversal; duplicate posting prevented (tested under concurrency) |
| 31 | Inventory | COMPLETE | Receive, issue, return, transfer, adjustments with approval; quantity only, no GL posting by design |
| 32 | Budgeting | COMPLETE | Versions, submit, approve, revise |
| 33 | Budget vs Actual | COMPLETE | Actuals from posted journals, excluding year-end closing journals |
| 34 | Employees | COMPLETE | Salary structures; encrypted sensitive fields |
| 35 | Payroll | COMPLETE (posting blocked by policy) | Runs, validation, approval, accrual, payment. Deductions can no longer point at cash or bank. No payslips |
| 36 | Payroll Excel import | COMPLETE (data) | Mapping, validation, exclusions; the client workbook has no usable figures. **Row exclusion button fixed in this release** |
| 37 | Financial statements | COMPLETE | I&E, Balance Sheet, Cash Flow, Receipts & Payments; I&E stays correct after year-end closing |
| 38 | Branch and service reporting | COMPLETE | Branch-wise I&E, service analysis, budget summaries |
| 39 | PDF/Excel/CSV exports | COMPLETE | Every report; formula-injection protection |
| 40 | Audit logging | COMPLETE | **Profile, password change and password reset now audited** |
| 41 | Application settings | COMPLETE | |
| 42 | Financial approval controls | COMPLETE (data) | Director only; no real Director appointed |
| 43 | FRC fee and payment sync | COMPLETE (local) | Mirror, incremental sync, refunds flagged for review, service-specific income, settlement mapping, preview and authorised posting |
| 44 | FRC payment reconciliation | COMPLETE | Finance Report rules; export |

## 2. Added or corrected in this release

- **Year-end closing:**
  - A preview per branch, then authorised posting of one closing journal per branch to the Accumulated Surplus ledger.
  - The fiscal year cannot be closed before the closing is posted.
  - Reversal re-opens the balances.
  - The Trial Balance carries balance-sheet balances forward.
  - Performance reports (I&E, branch I&E, service analysis, Budget vs Actual, dashboard) ignore closing journals.
- **Printable receipts** for cash and bank receipts.
- **Discarding draft journals** (with a reason and an audit entry). Previously a stray draft could block the year-end closing.
- **Security fixes:**
  - Payroll deductions on cash/bank ledgers.
  - Cash/bank ledgers as the contra account of receipts and payments.
  - Maker-checker now includes whoever edited a draft.
  - Salary amounts in import errors.
  - The opening-balance import uploader approving the batch.
  - User and role administration scope.
  - Two GET pages that wrote data.
- **UI fixes:**
  - Payroll import row exclusion button.
  - Expense and Donation Register links.

## 3. Not built, by decision

| Item | Reason |
|---|---|
| Bank reconciliation | Listed as optional for this release; documented future work |
| Payslips, bank transfer file, tax and statutory calculations | Depend on payroll policy (client decision 5) |
| Staff advances workflow | Depends on advance rules (client decision 5); advances can be recorded as a deduction category and an advances ledger |
| Asset disposal, transfer, impairment | Excluded since Milestone 7 |
| Cost centres and fund codes from the workbook | Replaced by branch and service dimensions; restricted-fund rules are an open policy question |
| School fee management, rehab billing, student/client masters | Owned by the FRC Management Software |
| Two-factor login, online payment gateway | Optional or later phase in the original document |

## 4. Waiting for real data or decisions

These are prerequisites for real use, not missing features:
- Chart of Accounts approval and import.
- Director appointment.
- Real bank accounts.
- GL and settlement mappings.
- Service income ledgers: Quran Teaching has no workbook ledger; a New FRC Project Donation ledger is also missing.
- Opening balances.
- Fee, depreciation and payroll policy approval.
- Usable payroll figures.
- Production deployment of the FRC endpoints.

The current list is in `RELEASE_CANDIDATE_STATUS.md`.
