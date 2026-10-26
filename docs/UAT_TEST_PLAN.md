# User Acceptance Test Plan

**Status of browser testing:** an automated browser UAT on synthetic data has been executed (14 of 14 workflow cases passed, plus a smoke test of every screen per role; see `UAT_EXECUTION_REPORT.md`, and `tests/Uat/README.md` to repeat it). This plan remains the manual test for the client's staff, to be run on the approved setup before go-live.

## 1. Before you start

- Use a **separate UAT database**, never the development or production database. Create it empty, run `php artisan migrate --force` and `php artisan db:seed --force` (settings, roles, fiscal years, voucher types, donation categories; no financial data).
- Synchronise branches and services from the FRC Management Software test instance.
- Use **invented** vendors, employees, amounts and names. Do not enter real salaries or real client data.
- Browsers: current Chrome and Edge on Windows; Chrome on an Android phone; Safari on an iPhone or iPad if staff use them.
- Screen sizes: desktop 1366×768 and 1920×1080, tablet 768×1024, phone 390×844.
- Record each case as Pass, Fail or Blocked with a screenshot for every Fail.

## 2. Test users

Create one user per role with `php artisan app:create-administrator` for the first Administrator, then through Users. Give each user Branch A unless stated.

| User | Role | Branches | Used for |
|---|---|---|---|
| uat.admin | Administrator | all | setup, user and role administration |
| uat.accountant | Accountant | A, B | preparing and posting |
| uat.cashier | Cashier / Fee Clerk | A only | receipts, restricted access |
| uat.approver | Approver / Manager | A | approvals at or below the limit |
| uat.director | Director | A, B | approvals above the limit |
| uat.board | Management / Board | A, B | read-only |
| uat.auditor | Auditor | A, B | read-only, audit log |
| uat.branchb | Accountant | B only | cross-branch checks |

The Director is appointed only from the server console: `php artisan app:director-authority grant --database=… --email=… --branch=… --reason="UAT …"` (see `docs/FINANCIAL_AUTHORIZATION.md`).

## 3. Test data to prepare (UAT database only)

1. Import 15–20 accounts through the COA review: at least one class, group, sub-group and two ledgers for each of asset, liability, equity, income and expense, including two cash ledgers, two bank ledgers and one accumulated depreciation ledger.
2. Open fiscal year 2026 and the current month.
3. Create cash and bank accounts for Branch A and B; complete GL Mapping; map each donation category.
4. Create two vendors, one asset category, two inventory items with locations, three employees with a salary structure.

## 4. Test cases

### 4.1 Access and navigation
| ID | Steps | Expected |
|---|---|---|
| A1 | Log in with a wrong password six times. | Refused; throttled after five attempts. |
| A2 | Log in as each role. | Dashboard opens; the sidebar shows only that role's modules. |
| A3 | As uat.cashier, paste the URL of Users, Settings, Payroll and a Branch B record. | 403 each time. |
| A4 | Deactivate a user, then log in as them. | Refused. |
| A5 | Log out, press Back. | Login page, no data shown. |
| A6 | Collapse and expand the sidebar; reload. | State is remembered. On a phone the menu opens as a drawer and closes with the backdrop. |

### 4.2 Setup
| ID | Steps | Expected |
|---|---|---|
| S1 | Upload the client workbook in COA review. | 226 rows; nothing imported; counts shared 174, Rehab 25, School 27, manual review 11. |
| S2 | Approve a ledger without its parent. | Refused with the parent named. |
| S3 | Approve a School row without a reason. | Refused. |
| S4 | Export the approval summary. | CSV downloads with 226 rows. |
| S5 | In GL Mapping choose an income ledger for Accounts Payable. | Not offered or refused. |
| S6 | Open Setup Readiness as Administrator and as Accountant. | Administrator sees real statuses; Accountant gets 403. |

### 4.3 Journals and ledgers
| ID | Steps | Expected |
|---|---|---|
| J1 | Create, submit, approve (different user) and post a balanced journal. | Voucher number assigned on posting only. |
| J2 | Try to approve your own journal. | Refused. |
| J3 | Create a journal that credits a cash ledger against an expense. | Refused at submit with a clear message. |
| J4 | Create a cash-to-bank transfer journal. | Allowed. |
| J5 | Reverse a posted journal. | Both entries stay visible; ledger nets to zero. |
| J6 | Close the period and try to post. | Refused. |
| J7 | Open General Ledger and Trial Balance; export each as PDF, Excel, CSV. | Files open; totals equal the screen. |

### 4.4 Payments and approvals
| ID | Steps | Expected |
|---|---|---|
| P1 | Cash payment of exactly 150,000: submit, approve as uat.approver. | Approved. |
| P2 | Cash payment of 150,000.01: try as uat.approver and uat.admin. | Both refused; "Awaiting Director approval" shown. |
| P3 | Same payment as uat.director. | Approved. Disburse, then post. |
| P4 | Paid expense and petty cash above the limit. | Same as P2 and P3. |
| P5 | Two payments of 100,000 to the same payee within a week. | Split-payment warning on the second. |
| P6 | As uat.admin open Settings. | Approval limits shown read-only; no way to change them. |
| P7 | As uat.director change the payment limit with a reason. | Saved; appears in the audit log with old and new value. |
| P8 | As uat.admin try to give yourself or another user the Director role. | Refused. |

### 4.5 Purchasing
| ID | Steps | Expected |
|---|---|---|
| O1 | Create a purchase order, submit, approve, issue. | Number assigned; history shows each step; no journal created. |
| O2 | Purchase order above the limit. | Only the Director can approve. |
| O3 | Create two bills from one purchase order. | Remaining amount reduces. |
| O4 | Bill more than the remaining amount without a reason. | Refused. |
| O5 | Same with a reason; approve as uat.admin, then uat.director. | Administrator refused; Director approves. |
| O6 | Post the bill; pay it in two parts; try to overpay. | Outstanding reduces; overpayment refused. |

### 4.6 Assets, inventory, budgets, payroll
| ID | Steps | Expected |
|---|---|---|
| F1 | Register and activate an asset. | No journal created. |
| F2 | Prepare a depreciation run and try to post. | Blocked with the policy message while the policy is unapproved. |
| I1 | Receive 10, issue 4 with a recipient, issue 7. | Balance 6; the third issue is refused. |
| I2 | Transfer 3 from Branch A to B as uat.accountant; then as uat.cashier. | First works and both branches update; second refused. |
| I3 | Request an adjustment and approve it yourself. | Refused; another user can approve. |
| I4 | Double-click the Issue button. | One movement only. |
| B1 | Create a budget with many ledgers, save, edit one line, save. | All other lines unchanged. |
| B2 | Submit, approve, revise. | Version 2 created; version 1 superseded on approval. |
| B3 | Post expenses to 85% of a budget line; open Budget vs Actual. | Warning status. |
| Y1 | Import an anonymised payroll file; map columns. | Preview and error report shown; nothing posted. |
| Y2 | Import the client workbook as supplied. | Rejected: the salary columns contain random-number formulas. |
| Y3 | Open employees as a user without the sensitive payroll permission. | No salary figures anywhere, including page source and exports. |
| Y4 | Try to post an approved payroll run. | Blocked with the policy message. |

### 4.7 Reports and exports
| ID | Steps | Expected |
|---|---|---|
| R1 | Income & Expenditure for the year, one branch and all branches. | Totals equal the Trial Balance income and expense totals. |
| R2 | Balance Sheet as at today. | "Balanced" shown; unclosed surplus line present. |
| R3 | Cash Flow before classifying ledgers. | Unclassified section and "not final" label. Classify, reload: reconciles to the cash and bank balances. |
| R4 | Receipts & Payments. | Cash-to-bank transfer not listed; accrued expense not listed. |
| R5 | Service analysis. | Unallocated row shown separately. |
| R6 | Export each statement as PDF, Excel and CSV. | Organisation name, filters, date and totals present; numbers match the screen. |
| R7 | Enter a narration starting with `=1+1`, export to Excel. | Shown as text, not calculated. |
| R8 | As uat.board export a payroll report. | Refused. |
| R9 | As uat.branchb request a Branch A report by changing the URL. | 403. |
| R10 | Audit log as uat.auditor: filter by date, user, module. | Entries for posting, approvals, limit changes and Director changes are found; no edit or delete action exists. |

### 4.8 Layout and usability (repeat at desktop, tablet and phone widths)
| ID | Check | Expected |
|---|---|---|
| U1 | Every sidebar link opens a page. | No error page, no broken link. |
| U2 | Browser console while using each module. | No JavaScript errors. |
| U3 | Wide tables (ledger, budget grid, payroll register). | Scroll inside the table; the page itself does not scroll sideways. |
| U4 | Submit each form empty. | Field-level messages; entered values kept. |
| U5 | Buttons that change data. | Ask for confirmation; cannot be double-submitted. |
| U6 | Print the asset register and a statement. | Readable without the sidebar. |

## 5. Exit criteria

UAT is complete when every case in sections 4.1 to 4.7 passes, no Fail remains in 4.8 at desktop width, and any phone or tablet issue is recorded with an agreed fix date. A failed case in sections 4.3, 4.4 or 4.5 blocks go-live.

## 6. After UAT

Delete the UAT database. Do not copy UAT data into production.
