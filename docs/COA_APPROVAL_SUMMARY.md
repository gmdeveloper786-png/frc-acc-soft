# Chart of Accounts — Approval Request

**For:** FRC owner and accountant
**Source reviewed:** `Chart-of-Accounts.xlsx`, sheet "Chart of Accounts" (226 rows)
**Status:** Nothing has been imported or approved. The accounting system holds no Chart of Accounts, no balances and no postings until you approve an exact selection.

## What we need from you

1. Mark every row in the attached CSV (`coa-approval-summary`) as **Approve** or **Exclude** in the last column.
2. Answer the 11 questions in "Rows that need your decision".
3. Confirm the operational ledgers in "Ledgers the system must be told to use".

The CSV is exported from the system's COA Approval Review screen and lists, for each row: code, name, hierarchy, normal balance, posting flag, our proposed decision and the reason.

## What the workbook contains

| Workbook "Entity" | Rows | Postable ledgers | Our proposal |
|---|---:|---:|---|
| Both | 174 | 115 | Include, subject to your approval |
| Rehab Centre | 25 | 25 | Include, subject to your approval |
| School | 27 | 27 | Your decision needed (see below) |
| **Total** | **226** | **167** | |

The other 59 rows are parent headings (5 classes, 14 groups, 40 sub-groups). Every one of the 167 ledgers has a complete parent chain, no code is duplicated and no row is structurally invalid.

## School accounts: an important distinction

The workbook was prepared when the School and the Rehab Centre were treated as two separate units. FRC is now one organisation, and **School Readiness Program is a normal FRC service**, recorded like every other service.

- We have **not** proposed excluding any account just because its name contains "School".
- The 27 School rows are ledgers of the *former separate school unit*: school cash and bank, Student Fees Receivable, Advance Fees from Students, tuition and admission fees, uniform and canteen income, fee concessions, Teaching Staff Salaries and school programme costs.
- For each one, please tell us whether FRC actually uses it today. If School Readiness income should be reported separately, the simplest route is to approve one fee income ledger for it; the system then records it by service.
- Five parent headings (School Income, Student Fees, Other School Income, Fee Discounts & Concessions, School Programme) are only needed if you approve at least one ledger beneath them.

## Rows that need your decision (11)

| Code | Account | Question |
|---|---|---|
| 11601 | Withholding Tax Recoverable | Confirm the tax treatment |
| 21202 | Income Tax Withheld from Staff Payable | Confirm the statutory rules |
| 21203 | AKKF Fund | Confirm which schemes apply |
| 31202 | Zakat / Charity Fund | Does FRC hold a separate Zakat fund? |
| 43102 | Zakat / Charity Donations | Confirm use for Zakat donations |
| 42107 | Skills & Vocational Training Fees | Is this service offered? |
| 31303 | Owner's / Trustees' Capital | Only if FRC is privately owned |
| 31304 | Owner's Drawings | Only if FRC is privately owned |
| 11602 | Inter-Unit Receivable | We propose **Exclude**: FRC is one organisation |
| 21501 | Inter-Unit Payable | We propose **Exclude**: FRC is one organisation |
| 215 | Inter-Unit & Other (heading) | We propose **Keep**, unchanged. It is also the parent of 21502 Deferred Grant Income and 21503 Other Payables, which are not inter-unit accounts and cannot be imported without it. A heading cannot be posted to, so it cannot hold inter-unit balances. Exclude it only if 21502 and 21503 are excluded too |

## Ledgers the system must be told to use

These are set on the GL Mapping screen after import. The codes below are candidates from your workbook, not decisions.

| Purpose | Candidate ledgers | Required before |
|---|---|---|
| Cash in hand (per branch) | 11102 Cash in Hand - Rehab Centre | Cash receipts and payments |
| Petty cash | 11104 Petty Cash - Rehab Centre | Petty cash |
| Bank (one ledger per real bank account) | 11201, 11203, 11204, 11205, 11206 | Bank receipts and payments |
| Accounts Receivable | 11302 Client Service Fees Receivable | Fee receivables |
| Accounts Payable | 21101 Trade Creditors / Suppliers | Purchase bills, accrued expenses |
| General fee income | one of 42101–42106 | Fee receipts |
| Service-specific fee income | 42101–42107, one per service if wanted | Optional |
| General Donation | 43101 General Donations | Donations |
| Zakat | 43102 Zakat / Charity Donations | Donations |
| New FRC Project Donation | **No ledger exists in the workbook** | Donations |

Two points need a decision:

- **Branches.** The workbook has one cash ledger and a few bank ledgers. FRC has six branches. Please confirm whether each branch needs its own cash ledger, and list the real bank accounts.
- **New FRC Project Donation.** Please give us a code and name for this ledger, or confirm that it should share 43101.

## What cannot happen until you approve

| Item | Blocked until |
|---|---|
| Any Chart of Accounts record | Your row-by-row approval |
| Opening balances | Approved chart and your opening figures |
| Receipts, payments, donations, purchase bills | Approved chart and the ledgers in the table above |
| Payroll | Review of the payroll workbook |

Purchase orders can already be raised and approved. They are procurement commitments and create no accounting entry.

## Rules we will follow

- Codes and names are imported exactly as they appear in your workbook; nothing is renamed or repurposed.
- A parent heading is imported only together with, or before, its approved ledgers.
- Excluded and undecided rows are never imported.
- Every decision is recorded with the reviewer's name, date and reason.
