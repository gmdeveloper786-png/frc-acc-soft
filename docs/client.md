# FRC Accounting Software — Client Manual Testing Guide

## 1. Is document ka maqsad

Ye guide client team ko FRC Accounting Software ka complete overview aur start-to-end manual testing process deti hai. Is testing ko **User Acceptance Testing (UAT)** kaha gaya hai.

Har test ke samne:

- `[ ] Pass` tab mark karein jab result expected result ke mutabiq ho.
- `[ ] Fail` tab mark karein jab error, ghalat total, missing button, unauthorized access ya unexpected result aaye.
- Fail hone par screenshot, role, branch, waqt aur exact steps note karein.

> **Safety:** Manual testing sirf isolated UAT database par synthetic/fake data ke sath karein. Development ya production database mein test entries, fake accounts, fake salary, fake bank details ya fake financial postings na karein.

---

## 2. Software ka high-level overview

FRC Accounting Software aik organisation, **Faizan Rehabilitation Centre**, ke liye multi-branch accounting system hai. Branch aur service master data FRC Management Software se read-only API sync hota hai. School Readiness Program aik normal FRC service hai; ye separate company ya department nahi hai.

Posted Journal Entries financial source of truth hain. Kisi account ka balance manually maintain nahi hota. Har financial posting:

- Debit aur credit mein balanced honi chahiye.
- Open fiscal year aur open accounting period mein honi chahiye.
- Authorized branch ke andar honi chahiye.
- Maker-checker rule follow karti hai.
- Duplicate posting se protected hai.
- Audit Log mein traceable hai.
- Posted hone ke baad edit/delete nahi hoti; correction reversal se hoti hai.

## 3. Available modules

| Area                    | Modules                                                                                                                                                |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Access & Administration | Login, Profile, Users, Roles & Permissions, Branch Access, Settings, Approval Controls, Audit Log                                                      |
| FRC Master Data         | Branches, Services, FRC API sync aur diagnostics                                                                                                       |
| Accounting Setup        | Fiscal Years, Accounting Periods, Chart of Accounts, COA Import Review, Voucher Types, Opening Balances, GL Mapping, Setup Readiness, Year-end Closing |
| Core Accounting         | Journal Entries, Approval, Posting, Reversal, General Ledger, Trial Balance                                                                            |
| Cash & Bank             | Cash/Bank Accounts, Cash Receipts, Bank Receipts, Cash Payments, Bank Payments, Cash Book, Bank Book, Bank Statements, Bank Reconciliation             |
| Expenses & Purchasing   | Expenses, Vendors, Purchase Orders, Purchase Bills, Vendor Payments, Payables, Aging, Vendor Ledger                                                    |
| Donations & Funds       | Donation Categories, Donation Receipts, Funds, Grants, Fund-wise I&E, Fund Balances, Grant Utilisation                                                 |
| Petty Cash              | Petty Cash Vouchers, Petty Cash Imprest, Replenishment                                                                                                 |
| Fixed Assets            | Asset Categories, Fixed Asset Register, Depreciation Runs, Asset Reports                                                                               |
| Inventory               | Items, Categories, Locations, Receipts, Issues, Returns, Transfers, Adjustments, Stock Balances, Inventory Reports                                     |
| Budgets                 | Annual Budgets, Revisions, Budget vs Actual, Branch/Service Summary, Utilisation                                                                       |
| Payroll                 | Employees, Components, Salary Structures, Payroll Import, Payroll Runs, Payslips, Staff Advances, Payroll Reports                                      |
| FRC Fee Integration     | Enrollment/Payment mirror, Sync History, Exceptions, Settlement Mapping, Fee Posting Preview, Reversal, Reconciliation                                 |
| Reports                 | Registers, GL, Trial Balance, I&E, Balance Sheet, Cash Flow, Receipts & Payments, Branch-wise/Service reports, PDF/XLSX/CSV exports                    |

---

## 4. Roles aur zimmedariyan

### 4.1 Standard roles

| Role                | Manual testing mein bunyadi kaam                                                                                              |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Administrator       | Users, roles, settings, accounting setup, master configuration. Administrator ko Director authority automatically nahi milti. |
| Accountant          | Transactions/journals prepare karna, submit/post karna, mappings, imports, reports aur integration operate karna.             |
| Cashier / Fee Clerk | Receipts/payments/expenses/petty cash create aur submit karna; apna document approve nahi kar sakta.                          |
| Approver / Manager  | Normal threshold tak submitted records approve/reject karna; maker ka record checker ke taur par review karna.                |
| Director            | High-value approvals, approval thresholds/policies aur special exceptions approve karna.                                      |
| Management / Board  | Management reports aur financial information read-only dekhna.                                                                |
| Auditor             | Read-only financial/report access aur Audit Log review.                                                                       |

### 4.2 Zaroori authorization rules

1. Record banane/edit/submit karne wala user usi record ko approve nahi kar sakta.
2. PKR 150,000 default threshold se zyada payment ya PO Director approval mangta hai; exact threshold Approval Controls se dynamic hai.
3. Administrator ke paas tamam permissions ho sakti hain, lekin woh Director nahi hota.
4. Administrator aur Director aik hi user account par combine nahi kiye ja sakte.
5. User sirf assigned branches ka data dekh sakta hai.
6. Button chup jana security ka substitute nahi; direct URL se bhi unauthorized access 403/404 hona chahiye.
7. Payroll amounts sirf `payroll.view_sensitive` permission walay roles ko dikhne chahiye.

---

## 5. Testing shuru karne se pehle

### 5.1 Technical team ki tayari

Client testing se pehle technical operator confirm kare:

- UAT URL available hai: `http://127.0.0.1:8010`
- UAT database: `frc_accounting_software_uat`
- Synthetic FRC API: `http://127.0.0.1:8011`
- UAT database mein synthetic users, branches, services, COA, mappings aur FY 2026 mojood hain.
- UAT start par posted journals zero hon.
- Real development aur production databases UAT se separate hon.
- UAT login password client ko secure channel se diya gaya ho; password bug report ya screenshot mein na likha jaye.

> UAT reset/start commands client na chalaye. Ye kaam technical operator `tests/Uat/README.md` ke mutabiq kare. Reset sirf `_uat` database par hona chahiye.

### 5.2 UAT users

| User                      | Role          | Scope                             |
| ------------------------- | ------------- | --------------------------------- |
| `uat.admin@uat.test`      | Administrator | Setup/administration              |
| `uat.accountant@uat.test` | Accountant    | UAT Branch A aur allowed branches |
| `uat.cashier@uat.test`    | Cashier       | Transaction preparation           |
| `uat.approver@uat.test`   | Approver      | Standard approvals                |
| `uat.director@uat.test`   | Director      | High-value/policy approvals       |
| `uat.board@uat.test`      | Management    | Read-only management review       |
| `uat.auditor@uat.test`    | Auditor       | Read-only audit/reports           |
| `uat.branchb@uat.test`    | Accountant    | Sirf UAT Branch B                 |

Do maker-checker testing ke liye do alag browser profiles ya aik normal aur aik private/incognito window use karein.

### 5.3 Har test ke general checks

Har screen par ye cheezen bhi check karein:

- Page title aur menu sahi ho.
- Search, filters, pagination aur reset kaam karein.
- Required field khali chhorne par clear validation aaye.
- Success/error message readable ho.
- Amount PKR mein, do decimal places aur right aligned ho.
- Branch selector sirf authorized branches dikhaye.
- Double click/re-submit se duplicate record na bane.
- Back/refresh ke baad status aur totals consistent rahen.
- High-impact action par confirmation aaye.

---

## 6. Start-to-end manual testing

### Test 01 — Login, logout aur profile

**Role:** Har role mein kam az kam aik martaba.

1. Login page kholen.
2. Ghalat password enter karein; login reject hona chahiye.
3. Sahi credentials se login karein; Dashboard khulna chahiye.
4. User menu se Profile kholen aur display name update karein.
5. Password change karein, logout karke naye password se login karein, phir UAT password restore karein.
6. Logout karein aur browser Back press karein.

**Expected:** Unauthorized login reject ho, inactive user login na kar sake, session logout ke baad protected page na khule, password kisi log mein nazar na aaye.

- [ ] Pass - [ ] Fail

### Test 02 — Dashboard

**Role:** Administrator, Accountant, Cashier, Management.

1. Dashboard cards, alerts aur recent activity dekhein.
2. Branch aur fiscal filters available hon to change karein.
3. Cashier aur Management se dobara dekhein.

**Expected:** Numbers sirf posted journals se hon; empty ledger par fake amount ya crash na ho; sensitive payroll amount unauthorized user ko mask ho.

- [ ] Pass - [ ] Fail

### Test 03 — Users, roles aur branch security

**Role:** Administrator.

1. `uat.client.cashier@uat.test` naam ka temporary Cashier user UAT Branch A par banayein.
2. User edit karke Branch B assign/remove karein.
3. User deactivate karein aur login try karein.
4. Roles & Permissions mein custom role create karke limited view permission dein.
5. Apne aap ko extra privilege ya Director role dene ki koshish karein.
6. Last active Administrator ko deactivate karne ki koshish karein.

**Expected:** Valid changes save/audit hon; inactive login reject ho; self-escalation, unauthorized Director grant aur last Administrator deactivation reject ho.

- [ ] Pass - [ ] Fail

### Test 04 — Approval Controls

**Role:** Pehle Administrator, phir Director.

1. Administrator se Administration > Approval Controls kholen.
2. Director se payment threshold 150,000 se 160,000 reason `Client UAT threshold check` ke sath change karein.
3. 10 characters se chhota reason try karein.
4. Threshold wapas approved value par set karein.

**Expected:** Administrator ko 403; Director change save kar sake; short reason reject ho; old/new value Audit Log mein ho.

- [ ] Pass - [ ] Fail

### Test 05 — Branches aur Services sync

**Role:** Administrator/authorized sync user.

1. Branches screen par sync chalayein.
2. Services screen par published services sync karein.
3. Dono sync repeat karein.
4. Six branches aur nine services expected baseline se compare karein.
5. School Readiness Program ko normal service ke taur par verify karein.

**Expected:** API connected ho, repeat sync duplicates na banaye, branch-user assignments aur accounting mappings preserve rahen, source records read-only hon.

- [ ] Pass - [ ] Fail

### Test 06 — Fiscal Years, Periods aur Year-end Closing

**Role:** Accountant, Approver/Director.

1. FY 2026 aur us ke 12 monthly periods verify karein.
2. January 2026 lock/close karein.
3. 15 January ka journal post try karein.
4. Authorized user se January reopen karein.
5. Overlapping fiscal year create try karein.
6. Year-end closing preview dekhein; actual closing poori UAT ke bilkul end mein karein.

**Expected:** Closed period posting reject ho, overlap reject ho, reopen authorization enforce ho, closing preview branch-wise income/expense aur accumulated surplus dikhaye.

- [ ] Pass - [ ] Fail

### Test 07 — Chart of Accounts import review

**Role:** Administrator/Accountant with import permission.

1. Chart tree, list, type/status filters aur search test karein.
2. Synthetic COA workbook upload karein; real client workbook approve/import na karein.
3. Preview totals: valid, invalid, duplicate, existing, excluded aur manual-review rows dekhein.
4. Child ledger ko parent se pehle approve karein.
5. Flagged row ko reason ke baghair approve karein.
6. Do valid rows approve, aik legacy/inter-unit row exclude aur import confirm karein.

**Expected:** Preview se account create na ho; parent-first aur reason rules enforce hon; sirf selected approved rows exact code/name ke sath import hon; repeat import duplicate na banaye.

- [ ] Pass - [ ] Fail

### Test 08 — GL Mapping aur Setup Readiness

**Role:** Administrator/Accountant.

1. Accounts Receivable, Accounts Payable, Fee Income, Petty Cash, Salary Expense, Salary Payable aur Accumulated Surplus mappings check karein.
2. Speech Therapy aur School Readiness ke service income ledgers check karein.
3. Income mapping mein liability ledger select try karein.
4. Setup Readiness page kholen.

**Expected:** Sirf compatible active/postable ledgers save hon; wrong classification reject ho; missing mappings readiness page par clear hon; changes audited hon.

- [ ] Pass - [ ] Fail

### Test 09 — Cash & Bank accounts

**Role:** Administrator/Accountant.

1. UAT Branch B ke liye cash account create karein.
2. Usi branch aur usi GL ledger par duplicate account create try karein.
3. Cash ledger ko bank account ke liye reuse try karein.
4. Bank account number ki masking check karein.

**Expected:** Valid account save ho; unsafe duplicate/conflicting type reject ho; sensitive number masked ho; Posted Balance journal lines se derive ho.

- [ ] Pass - [ ] Fail

### Test 10 — Journal complete cycle

**Roles:** Accountant maker, Approver checker, Accountant poster.

1. Branch A mein journal banayein: Dr Utilities 1,000 / Cr Trade Creditors 1,000.
2. Save aur submit karein.
3. Maker se approve try karein.
4. Approver se approve karein.
5. Accountant se post karein.
6. General Ledger aur Trial Balance mein verify karein.
7. Reason ke sath reverse karein.
8. Manual journal mein cash/bank ko credit karke payment bypass try karein.
9. Cash-to-bank transfer ko CV contra journal se try karein.
10. Aik draft journal reason ke sath discard karein.

**Expected:** Maker approval reject; balanced posting par unique voucher; TB debit=credit; reversal opposite linked journal banaye; manual cash outflow reject; valid CV transfer allow; posted record edit/delete na ho.

- [ ] Pass - [ ] Fail

### Test 11 — Cash aur Bank Receipts

**Roles:** Cashier create, Accountant post.

1. Cash Receipt: Branch A, UAT Cash A, amount 2,500, received from `Synthetic Client`, contra Income.
2. Post karke printable receipt kholen.
3. Bank Receipt bhi isi tarah banayein.
4. Contra account mein cash/bank ledger select karke invalid receipt try karein.

**Expected:** CR/BR voucher generate ho; cash/bank book aur Receipt Register update hon; print view sahi ho; invalid cash-to-bank receipt reject ho.

- [ ] Pass - [ ] Fail

### Test 12 — Cash/Bank Payments aur Director threshold

**Roles:** Accountant/Cashier maker, Approver, Director.

1. Cash Payment 150,000.01 create aur submit karein.
2. Maker, Administrator aur normal Approver se approve try karein.
3. Director se approve karein.
4. Accountant se disbursement confirm, phir post karein.
5. Exactly 150,000 payment normal Approver se approve karein.
6. Same record dobara post try karein.

**Expected:** Above-threshold payment sirf Director approve kare; approval ke baghair disbursement/posting na ho; CP/BP balanced ho; repeat posting reject ho.

- [ ] Pass - [ ] Fail

### Test 13 — Expenses aur Petty Cash vouchers

**Roles:** Cashier/Accountant maker, Approver checker.

1. Paid expense 3,000 Utilities par create, submit, approve, disburse aur post karein.
2. Accrued expense create karein jiska credit Accounts Payable ho.
3. Petty cash expense 800 create karein; vendor blank rakhein.
4. Approve aur post karein.

**Expected:** Paid: Dr Expense/Cr Cash-Bank. Accrued: Dr Expense/Cr AP. Petty cash bina vendor accept ho. Same expense dobara recognize na ho.

- [ ] Pass - [ ] Fail

### Test 14 — Vendors, Purchase Orders, Bills aur Vendor Payments

**Roles:** Accountant maker, Approver, Director.

1. Synthetic vendor create/edit/view/deactivate/reactivate karein.
2. PO amount 200,000 create aur submit karein.
3. Normal Approver se approve try, phir Director se approve karein.
4. PO issue karein aur us se Purchase Bill create karein.
5. Bill approve aur post karein.
6. Vendor Payment 50,000 create, approve, disburse aur post karein.
7. Outstanding se zyada payment try karein.
8. PO remaining amount se zyada bill create try karein.
9. Vendor Ledger aur Payables & Aging check karein.

**Expected:** High-value PO Director ko jaye; bill Dr Expense/Asset, Cr AP ho; payment Dr AP, Cr Cash/Bank ho; partial balance sahi rahe; overpayment/overbilling reject ho.

- [ ] Pass - [ ] Fail

### Test 15 — Donations

**Role:** Accountant.

1. General Donation, Zakat aur New FRC Project Donation categories/mappings dekhein.
2. General Donation 5,000 UAT Cash A mein create aur post karein.
3. Donation acknowledgment print karein.
4. Missing category ledger ke sath posting try karein.

**Expected:** DR voucher, correct donation income credit, receipt/acknowledgment aur Donation Register update ho; missing mapping par posting block ho.

- [ ] Pass - [ ] Fail

### Test 16 — Funds aur Grants

**Roles:** Accountant; Auditor read-only.

1. GEN, DNR, ZKT, GRT, CAP aur sample grant open karein.
2. Allowed fund/ledger combination se expense post karein.
3. Restricted fund ko disallowed ledger ke sath use karein.
4. Fund-wise I&E, Fund Balances aur Grant Utilisation dekhein.

**Expected:** Restricted-fund rule disallowed posting reject kare; allowed activity correct fund report mein aaye; Auditor change na kar sake.

- [ ] Pass - [ ] Fail

### Test 17 — Petty Cash Imprest

**Roles:** Accountant maker, Approver checker.

1. Branch A float 20,000 open karein.
2. Petty cash vouchers total 3,500 post karein.
3. Replenishment due 3,500 verify karein.
4. Replenishment request create karein.
5. Doosri open request create try karein.
6. Replenishment CV submit, approve aur post karein.

**Expected:** Doosri open request reject ho; replenishment Dr Petty Cash/Cr Bank ho; posting ke baad float 20,000 restore ho.

- [ ] Pass - [ ] Fail

### Test 18 — Bank Reconciliation

**Roles:** Accountant preparer, Approver checker.

1. UAT Bank A mein aik receipt aur aik payment post karein.
2. Synthetic CSV/XLSX bank statement import karein aur columns map karein.
3. Same file dobara import try karein.
4. New reconciliation banayein, auto-match aur manual match karein.
5. Difference exactly 0.00 hone ke baad submit karein.
6. Preparer se approve try karein; phir Approver se approve karein.
7. Approved reconciliation edit try karein.
8. Reconciliation statement print/export karein.

**Expected:** Duplicate statement reject; zero difference se pehle submit reject; maker approve na kar sake; approved reconciliation locked ho.

- [ ] Pass - [ ] Fail

### Test 19 — Inventory

**Role:** Accountant/Administrator.

1. Item/category/location create ya seeded Gloves use karein.
2. Receive 10, Issue 4, Return 1 aur Branch B transfer 2 karein.
3. Available stock se zyada issue try karein.
4. Adjustment -1 banayein aur doosray authorized user se approve karein.
5. Stock Balance aur Movement History dekhein.

**Expected:** Quantity exact reconcile ho; negative stock reject ho; duplicate submit aik movement banaye; inventory GL journal create na kare.

- [ ] Pass - [ ] Fail

### Test 20 — Fixed Assets aur Depreciation

**Roles:** Accountant maker, Approver, Director.

1. Asset Category ke Asset, Accumulated Depreciation aur Expense ledgers check karein.
2. Synthetic asset 120,000, useful life 60 months, in-service 01 Jan 2026 register karein.
3. Depreciation run prepare, submit aur approve karein.
4. Policy approval ke baghair post try karein.
5. Director se UAT policy approve karwa ke post karein.
6. Same period dobara post try karein.

**Expected:** Policy ke baghair block; policy ke baad aik balanced JV; duplicate depreciation reject; asset register updated ho.

- [ ] Pass - [ ] Fail

### Test 21 — Budgets

**Roles:** Accountant maker, Approver checker.

1. FY 2026 Branch A budget banayein aur income/expense ledgers par monthly values dein.
2. Submit karein; maker se approve try karein.
3. Approver se approve karein.
4. Revision create karke Version 2 banayein.
5. Budget vs Actual, Branch Summary, Service Summary aur Utilisation dekhein.

**Expected:** Maker-checker enforce ho; Version 1 preserve ho; Actual sirf posted journals se aaye; warning threshold dynamic ho.

- [ ] Pass - [ ] Fail

### Test 22 — Employees, Payroll Import aur Payroll Run

**Roles:** Accountant, Approver, Director; access check ke liye Cashier/Management.

1. Payroll components aur ledger mappings check karein.
2. Synthetic employee aur salary structure create karein.
3. Synthetic payroll workbook upload, columns map, errors/exclusions review aur confirm karein.
4. Payroll run create, validate, submit aur approve karein.
5. Policy approval ke baghair post try karein.
6. Director se UAT Payroll Policy approve karwa ke accrual post karein.
7. Bank se salary pay karein aur overpayment try karein.
8. Cashier se Payroll URL open karein.

**Expected:** Unapproved policy posting block kare; accrual Dr Salary Expense/Cr Salary Payable ho; payment payable clear kare; overpayment reject; Cashier ko 403; sensitive salary unauthorized user ko na dikhe.

- [ ] Pass - [ ] Fail

### Test 23 — Payslips

**Role:** Sensitive payroll permission wala Accountant; negative test Cashier/other branch.

1. Approved/posted Payroll Run se individual payslip kholen.
2. Print All Payslips use karein.
3. Draft run par payslip try karein.
4. Cashier aur doosri branch user se direct URL try karein.

**Expected:** Organisation, period, employee, earnings, deductions, gross, net aur payment status sahi ho; print mein one employee per page; unauthorized access 403/404.

- [ ] Pass - [ ] Fail

### Test 24 — Staff Advances

**Roles:** Accountant maker, Approver checker.

1. Employee ke liye 30,000 advance, 3 installments create karein.
2. Submit aur approve karein.
3. Normal payment workflow se advance disburse karke posted payment link karein.
4. Payroll run mein due recovery add, validate aur post karein.
5. Outstanding report dekhein.

**Expected:** 10,000 x 3 schedule exact ho; module khud journal bypass na kare; first recovery ke baad outstanding 20,000 ho.

- [ ] Pass - [ ] Fail

### Test 25 — Opening Balances

**Roles:** Accountant preparer/poster, Approver checker.

1. Synthetic batch: Dr Cash 10,000 / Cr Fund-Equity 10,000 prepare karein.
2. Unbalanced batch submit try karein.
3. Preparer se approve try karein.
4. Approver se approve aur Accountant se post karein.
5. Same batch dobara post try karein.

**Expected:** Unbalanced aur self-approval reject; aik journal create ho; duplicate posting reject. Real opening balances UAT mein enter na karein.

- [ ] Pass - [ ] Fail

### Test 26 — FRC Financial Sync aur Fee Posting

**Roles:** Accountant; policy ke liye Director.

1. Financial Integration par full sync confirmation ke sath run karein.
2. Source Payments totals aur filters verify karein.
3. Sync dobara run karein.
4. Branch/payment-method settlement mappings set karein.
5. Director se UAT Fee Policy approve karwaein.
6. Fee Posting Preview mein journal lines aur skip reasons dekhein.
7. Eligible verified payments post karein.
8. Fee Reconciliation kholen.
9. Aik posted receipt reason ke sath reverse karein.

**Expected:** Repeat sync duplicate na banaye; pending/rejected/cancelled/unmapped payment post na ho; School Readiness apne mapped income ledger par aaye; har source payment sirf aik martaba post ho; reversal linked aur audited ho.

- [ ] Pass - [ ] Fail

### Test 27 — Financial Books, Registers aur Statements

**Roles:** Accountant, Management, Auditor, Branch B user.

1. Cash Book aur Bank Book account/date/branch filters se dekhein.
2. Receipt, Payment, Expense aur Donation Registers check karein.
3. Vendor Ledger aur Payables & Aging check karein.
4. GL aur Trial Balance dekhein.
5. Income & Expenditure, Balance Sheet, Branch-wise I&E, Service Analysis, Cash Flow aur Receipts & Payments dekhein.
6. Branch B user se same reports repeat karein.

**Expected:** Draft/approved-unposted figures financial reports mein na aayen; debits=credits; Balance Sheet equation pass ho; branch user sirf apni branch dekhe; consolidated totals double-count na hon.

- [ ] Pass - [ ] Fail

### Test 28 — Exports

**Role:** Authorized Accountant/Management/Auditor.

1. GL, TB, statements, payables, budget, assets, inventory, payroll aur FRC reconciliation se CSV/XLSX/PDF download karein.
2. Har file open karein aur screen totals se compare karein.
3. Unauthorized role se export URL try karein.

**Expected:** File valid format mein khule; filters/branch scope match kare; formulas/injection neutralized hon; unauthorized export reject ho.

- [ ] Pass - [ ] Fail

### Test 29 — Audit Log

**Role:** Auditor.

1. Upar ki actions actor, time, event aur branch se filter karein.
2. User changes, settings, approvals, posting, reversal, sync aur denied attempts verify karein.
3. Password, API token, bank secret ya APP_KEY search karein.
4. Audit record edit/delete karne ki koshish karein.

**Expected:** Sensitive values kabhi na dikhain; audit rows immutable hon; important success aur denied actions traceable hon.

- [ ] Pass - [ ] Fail

### Test 30 — Direct URL, branch isolation aur IDOR

**Roles:** Cashier, Management, Branch B user.

1. Admin session se Branch A journal, payment, asset, payroll, inventory aur fee record URLs copy karein.
2. Branch B user ke browser mein URLs paste karein.
3. Cashier se `/users`, journals, payroll aur FRC integration URLs try karein.
4. Management se create/post/approve URLs try karein.

**Expected:** Har unauthorized request 403/404 de; record ka koi private data render na ho; submitted `branch_id` change karke access bypass na ho.

- [ ] Pass - [ ] Fail

### Test 31 — Responsive UI aur usability

**Role:** Kam az kam Administrator aur Cashier.

1. Desktop width par tamam sidebar groups open karein.
2. Browser device mode mein 768px tablet aur 390px phone test karein.
3. Dashboard, transaction form, journal, Budget, Fee Preview aur report open karein.
4. Sidebar collapse/open, dropdowns, tables aur buttons test karein.

**Expected:** Page-level horizontal overflow na ho; menu aur buttons reachable hon; table apne container mein scroll kare; text overlap na ho; FRC teal/navy styling consistent ho.

- [ ] Pass - [ ] Fail

### Test 32 — End-to-end reconciliation

Ye test tamam postings ke baad aur year-end closing se pehle karein.

1. Consolidated Trial Balance ke debit aur credit totals note karein.
2. Income & Expenditure ka surplus/deficit Balance Sheet ke unclosed surplus se compare karein.
3. UAT Cash A ka Cash Book closing, Cash & Bank screen ke Posted Balance aur GL se compare karein.
4. Bank Book ko posted bank journals se compare karein.
5. Payables Outstanding ko unpaid posted bills minus posted vendor payments se compare karein.
6. Fee Reconciliation posted amount ko fee income ledgers ke GL se compare karein.
7. Inventory closing ko movements ke formula se compare karein.
8. Payroll payable ko accrual minus salary payments se compare karein.

**Expected:** Har financial figure paisa level tak match kare; TB balanced ho; koi draft amount posted report mein include na ho.

- [ ] Pass - [ ] Fail

### Test 33 — Year-end closing sab se aakhir mein

**Roles:** Accountant prepare, authorized approver/Director approve/post.

1. Confirm karein ke FY 2026 ke sab operational tests complete hain.
2. Drafts, unposted documents aur open blockers review karein.
3. Year-end closing preview branch-wise verify karein.
4. Accumulated Surplus mapping confirm karein.
5. Closing post karein aur FY 2026 close karein.
6. FY 2027 Trial Balance dekhein.

**Expected:** FY 2026 income/expense balances zero hon; balance-sheet balances FY 2027 mein carry forward hon; closing journals branch-wise balanced hon; duplicate closing reject ho.

- [ ] Pass - [ ] Fail

---

## 7. Recommended testing order

1. Login, roles, permissions aur Approval Controls — Tests 01–04.
2. FRC master sync aur accounting setup — Tests 05–09.
3. Core journals aur operational finance — Tests 10–18.
4. Inventory, assets, budgets aur payroll — Tests 19–24.
5. Opening balances aur FRC fee integration — Tests 25–26.
6. Reports, exports, audit aur security — Tests 27–31.
7. End-to-end reconciliation — Test 32.
8. Year-end closing — Test 33 sab se aakhir mein.

---

## 8. Bug report ka format

Har failed test ke liye ye detail dein:

| Field           | Detail                                               |
| --------------- | ---------------------------------------------------- |
| Test number     | Misal: Test 14, Step 7                               |
| Module          | Misal: Vendor Payments                               |
| Date/time       | Error ka exact waqt                                  |
| User/role       | Kis UAT user se test hua                             |
| Branch          | Branch A, Branch B ya consolidated                   |
| Steps           | Error dobara lane ke exact steps                     |
| Entered values  | Amount, date, status, reference; password/token nahi |
| Expected result | Kya hona chahiye tha                                 |
| Actual result   | Kya hua                                              |
| Evidence        | Full-page screenshot aur visible error message       |
| Frequency       | Har martaba ya kabhi kabhi                           |

Bug report mein password, API token, APP_KEY, real patient/client data, real salary ya real bank details kabhi include na karein.

### Severity

- **Critical:** Unauthorized financial posting/data exposure, unbalanced journal, duplicate posting, wrong branch data, login/security bypass.
- **High:** Approval bypass, wrong balance/report total, posting wrong account, reversal failure.
- **Medium:** Workflow/validation/filter/export issue jiska safe workaround ho.
- **Low:** Styling, alignment, wording ya minor usability issue.

---

## 9. UAT pass hone ke criteria

Client sign-off tab diya jaye jab:

- Sare critical end-to-end workflows Pass hon.
- Koi open Critical ya High defect na ho.
- Maker-checker aur Director threshold Pass hon.
- Branch isolation aur direct URL security Pass ho.
- GL, Trial Balance aur statements reconcile hon.
- Duplicate posting aur duplicate sync tests Pass hon.
- CSV/XLSX/PDF required exports valid hon.
- Client roles ko unki zaroorat ke mutabiq access nazar aaye.
- Client ne open accounting policies aur role visibility decisions document kar diye hon.

## 10. Client sign-off

| Item                       | Detail                                                 |
| -------------------------- | ------------------------------------------------------ |
| UAT start date             |                                                        |
| UAT end date               |                                                        |
| Client testers             |                                                        |
| Total Pass                 |                                                        |
| Total Fail                 |                                                        |
| Critical/High open defects |                                                        |
| Accepted limitations       |                                                        |
| Decision                   | Accepted / Accepted with conditions / Re-test required |
| Client name/signature      |                                                        |
| Date                       |                                                        |

---

## 11. Go-live se pehle abhi bhi zaroori client decisions

Manual testing ready hona production ready hone ke barabar nahi hai. Real financial use se pehle ye items complete/approve hon:

1. Real Chart of Accounts row-by-row approve aur import karna.
2. Real Director user officially appoint karna.
3. Real cash/bank accounts aur secure bank details configure karna.
4. Operational GL, service income, donation, payable, salary aur accumulated surplus mappings approve karna.
5. Opening balances 01 January 2026 per branch provide aur approve karna.
6. Fee accounting basis (cash/accrual), depreciation aur payroll policy approve karna.
7. Correct fixed-value payroll workbook provide karna.
8. Production FRC API endpoints/token activate karna.
9. Cash-flow classifications aur shared-cost allocation policy decide karna.
10. Production HTTPS, `APP_DEBUG=false`, backup/restore rehearsal, penetration test aur realistic performance test complete karna.

## 12. Important limitations aur boundaries

- FRC Management Software clients, enrollments aur operational fee payments ka source owner hai; Accounting duplicate operational billing system nahi hai.
- Financial reports sirf posted journals se bante hain.
- Inventory quantity-only hai; inventory valuation GL mein automatic nahi hoti.
- Asset disposal gain/loss, asset transfer aur impairment full workflow is UAT scope ka hissa nahi.
- Tax/statutory payroll formulas client policy ke baghair invent nahi kiye gaye.
- Bank overdraft/negative cash ka per-branch automatic hard stop approved policy ke baghair claim na karein.
- Browser-print receipt ko PDF printer se save kiya ja sakta hai; separate generated receipt PDF zaroori nahi.
- Bohat large exports ke liye production memory/performance testing alag se zaroori hai.
- Production mein koi fake financial transaction, synthetic COA ya UAT user copy nahi hona chahiye.

## 13. Final client confirmation checklist

- [ ] Maine tamam assigned modules test kiye.
- [ ] Maine apne role se sirf allowed actions dekhe.
- [ ] Maine unauthorized branch aur direct URL tests kiye.
- [ ] Maine approval threshold aur maker-checker verify kiya.
- [ ] Maine reports ko source postings se reconcile kiya.
- [ ] Maine open policy decisions aur limitations parh li hain.
- [ ] Maine real credentials ya personal data bug reports mein include nahi kiya.
- [ ] Main UAT result aur remaining conditions se mutmain hoon.
