# Decisions Needed from FRC

**For:** FRC owner, Director and accountant
**Purpose:** the accounting system is built, but it will not post real financial entries until the decisions below are made. Nothing here has been decided on your behalf.

Each item says what is blocked, what we recommend, and who should decide.

## A. Decisions that block financial posting

| # | Decision | Blocked until decided | Our recommendation | Who decides |
|---|---|---|---|---|
| 1 | **Chart of Accounts.** Approve or exclude each of the 226 rows in the workbook (see `COA_APPROVAL_SUMMARY.md`). | Every financial entry: receipts, payments, bills, journals, opening balances, all statements. | Approve the shared and Rehab Centre rows; decide the 27 former School rows and 11 flagged rows one by one. | Accountant, confirmed by owner |
| 2 | **Appoint a Director user.** Name the person who holds Director approval authority. | Any payment, purchase order or petty cash above PKR 150,000; any bill above its purchase order; any change to approval limits; approval of the policies in rows 4 and 5. | One named Director who is not also the system Administrator. | Owner |
| 3 | **Operational ledgers.** Which ledgers are cash, bank (one per real bank account), receivable, payable, fee income, each donation type, salary expense and salary payable. | Posting in the module that uses each ledger. | Decide together with the Chart of Accounts. A ledger for "New FRC Project Donation" does not exist in the workbook and must be named. | Accountant |
| 4 | **Depreciation policy.** First-month rule (full month, following month, or by days), and the minimum cost for an item to be treated as an asset. | Posting depreciation. Assets can be registered and schedules previewed now. | Full month from the month the asset enters service; set a capitalization minimum the accountant is comfortable with. | Accountant, approved by Director |
| 5 | **Payroll rules.** How income tax, AKKF, advances and "Semester + Yearly Leaves" are calculated, and whether leave is an addition or a deduction. | Posting payroll. Employees, imports and approvals work as drafts now. | Supply the written rules. Until then the system only stores amounts you enter; it calculates no tax or deduction itself. | Accountant, approved by Director |
| 6 | **Payroll figures.** The payroll workbook supplied has random-number formulas in the Total Salary, Gross Salary and three Paid Salaries columns, so it contains no usable salary figures. | Importing any real payroll. | Send a version with fixed numbers in those columns. | Accountant |
| 7 | **Opening balances** as at 1 January 2026, per branch. | A complete Balance Sheet. | Provide a balanced list per branch once the chart is approved. | Accountant |

## B. Decisions about controls (the system is safe without them, but stricter or looser rules need your approval)

| # | Decision | Current behaviour | Our recommendation |
|---|---|---|---|
| 8 | **Split payments.** Several payments, each under PKR 150,000, that together exceed it for one bill or payee. | The system warns the approver and records the warning. It does not block. | Require Director approval once the total for one bill or payee passes the limit. |
| 9 | **Who approves purchase orders at or below the limit.** | Any authorised approver who did not create the order. | Confirm, or require the Director for every order. |
| 10 | **High-value purchase bills.** | A bill within its purchase order, of any value, needs only an ordinary approver. Paying it still needs the Director above the limit. | Decide whether bills above the limit, or bills with no purchase order, also need the Director. |
| 11 | **Reversing a receipt** takes money back out of cash or bank. | Needs the reversal permission only. | Require the Director above the limit. |
| 12 | **Manual cash or bank adjustments.** | Not possible: a manual journal cannot pay money out of cash or bank. Corrections are made by reversing the original entry. | Keep as is unless a specific need arises. |
| 13 | **Budget overspend.** | Warning at 80% (adjustable) and a recorded overrun. Spending is not blocked. | Keep warnings only for the first year. |
| 14 | **Negative stock.** | Not allowed: an issue larger than the stock on hand is refused. | Keep as is. |
| 15 | **Director delegation** (a stand-in while the Director is away). | Switched off. | Decide whether to allow it, for how many days, and up to what amount. |
| 16 | **One person as both Administrator and Director.** | Refused by the system. | Keep separate. |
| 17 | **Missed depreciation months.** | Reported for review. Nothing is posted automatically. | Decide how missed months are to be charged. |

## C. Decisions about fee income from the FRC Management Software

| # | Decision | Why it matters |
|---|---|---|
| 18 | **Cash or accrual basis for fee income.** | Cash basis records income when a verified payment arrives. Accrual records a receivable when an enrollment is approved. The FRC software has no invoices, so we recommend starting on the cash basis. |
| 19 | **Permission to add read-only connections** in the FRC Management Software for enrollments and payments. | Decided: approved by the owner on 14 October 2026, built, and activated in the LOCAL development environment (6 enrollments and 7 payments mirrored; nothing posted). Production deployment still needs the owner's go-ahead (see `FRC_FEE_INTEGRATION_DESIGN.md` §2). |
| 20 | **Which bank or cash account each payment method settles into**, per branch. | Needed to record Easypaisa, JazzCash, card and bank transfers correctly. |

## D. Reporting decisions

| # | Decision | Current behaviour |
|---|---|---|
| 21 | **Cash flow categories.** Mark each ledger as operating, investing or financing. | The Cash Flow Statement lists unmarked ledgers as "Unclassified" and is labelled not final until they are marked. |
| 22 | **Shared costs by service.** | Service reports show direct income and direct expenses only. Costs with no service are shown separately and are not spread across services, so service results are not profitability figures. Decide whether, and how, shared costs should be allocated. |
| 24 | **Who may see salary amounts.** | Management / Board, Auditor, Approver and Director can see payroll amounts on screen; only Accountant, Director and Auditor can export them. Confirm, or remove Management's access. |
| 23 | **Branch balance sheets.** | A branch statement is labelled incomplete when that branch's entries do not balance on their own. Decide whether each branch should keep a full, self-balancing set of books. |

## What happens next

1. You return the Chart of Accounts decisions (row 1) and name the Director (row 2).
2. We import only the approved accounts and set the operational ledgers (row 3).
3. You provide opening balances (row 7).
4. User acceptance testing starts (see `UAT_TEST_PLAN.md`).
5. Policies 4 and 5 are approved by the Director inside the system when you are ready to post depreciation and payroll.
