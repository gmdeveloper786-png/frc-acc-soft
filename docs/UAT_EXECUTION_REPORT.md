# UAT Execution Report

**Result: 14 of 14 workflow cases passed (141 checks, 0 failed) in a real browser, after three application defects found by the first run were fixed.** This was a controlled UAT on synthetic data. It does not replace UAT by the client's staff with approved accounts, and it does not make the software production ready (see `FINAL_HANDOVER_STATUS.md`).

## 1. Environment

| Item | Value |
|---|---|
| Date | 14 October 2026 |
| Browser | Installed Google Chrome, headless, temporary profile, driven over the DevTools protocol (`tests/Uat/browser/cdp.mjs`); nothing installed |
| Application | This project, PHP 8.3.14 built-in server on `127.0.0.1:8010` |
| Database | `frc_accounting_software_uat` only, rebuilt by `tests/Uat/setup.php --fresh` (171 s). The scripts refuse any database without the `_uat` suffix. The development database was not used |
| FRC source | `tests/Uat/fake-frc-api.php` on `127.0.0.1:8011`: a synthetic implementation of the proposed contract with invented records. The real FRC software was not called |
| Accounts | Synthetic "UAT …" Chart of Accounts, synthetic UAT Director, synthetic users per role. No client data |
| How to repeat | `tests/Uat/README.md` |

Synthetic FRC data: 4 enrollments (active, approved, completed, cancelled) and 10 payments: 7 paid totalling 18,000 (cash 11,000; card, bank transfer and Easypaisa 7,000), 1 JazzCash pending verification (1,500), 1 rejected (1,000), and 1 paid payment of the cancelled enrollment (500).

## 2. Workflow cases (`tests/Uat/browser/workflows.mjs`)

| Case | Workflow | Checks | Result |
|---|---|---|---|
| UAT-01 | Approval controls: Administrator refused (403, also by crafted PUT); the synthetic Director approves the fee policy with a reason; a short reason is rejected; Auditor sees `approval_settings.changed`, old 0 → new 1, reason and Director as actor | 11 | PASS |
| UAT-02 | FRC sync with confirmation; 4 enrollments and 10 payments mirrored; paid 18,000, cash 11,000, online 7,000, pending 1,500; incremental and second full sync create no duplicates; branch, method, status and receipt filters; no personal data on the detail page; Branch B user sees only Branch B; Cashier 403 | 22 | PASS |
| UAT-03 | Settlement mapping per branch and method; posting preview with journal lines and skip reasons (pending, rejected, cancelled enrollment, unmapped Easypaisa B); 6 payments posted as FR journals; nothing posted twice; not reconciled until Easypaisa B is mapped and posted; then reconciled | 20 | PASS |
| UAT-04 | Ledger impact: Trial Balance balanced; Income 18,000 (Speech Therapy 13,000, General 5,000); Branch A 14,000, Branch B 4,000; service analysis and dashboard agree | 8 | PASS |
| UAT-05 | Reversal of a fee receipt with a reason: original journal kept and marked reversed, reversal journal with opposite lines, income falls to 15,000, reconciliation shows the difference | 5 | PASS |
| UAT-06 | Manual journal: crediting cash refused with the guard message; maker cannot approve (403); approver approves; accountant posts with a JV number | 7 | PASS |
| UAT-07 | Payment of 150,000.01: Administrator and Approver cannot approve; Director approves; disbursed and posted. 150,000.00 is approvable by the Approver | 14 | PASS |
| UAT-08 | Purchase order of 200,000 approvable only by the Director; bill from the PO; partial payment of 50,000; overpayment refused with the reason shown | 12 | PASS |
| UAT-09 | Donation receipt posted with a DR voucher | 2 | PASS |
| UAT-10 | Inventory: receive 10, issue 4, issue of 7 refused, the same form submitted twice at once creates one movement | 6 | PASS |
| UAT-11 | Depreciation and payroll runs approved but posting blocked while their policies are unapproved (no button; a crafted request is also refused); Cashier has no payroll access | 11 | PASS (see note) |
| UAT-12 | Exports through the on-screen links: Trial Balance, Income & Expenditure and FRC reconciliation as CSV, XLSX and PDF; correct type and file signature; CSV totals | 12 | PASS |
| UAT-13 | Direct-URL authorization: Branch B user refused Branch A payment, fee journal, manual journal and fee reversal; Management cannot post or export payroll; Cashier cannot open FRC screens | 8 | PASS |
| UAT-14 | Phone width (390 px): FRC payment list, posting preview and reconciliation without page overflow or JavaScript errors | 3 | PASS |

JavaScript console errors during the final run: 0.

**Note on UAT-11.** The Management account sees salary amounts on screen because the Management role holds `payroll.view_sensitive`. This is the current role design and an open client decision (`MILESTONE_8_QA_REPORT.md` §7); Management cannot export or post payroll. It is recorded, not counted as a defect.

## 3. Defects found and fixed

| # | Found in | Defect | Fix | Regression test |
|---|---|---|---|---|
| D1 | UAT-02 | The "Incremental sync" and "Manual full sync" buttons showed no confirmation and raised `TypeError: confirm is not a function`, so a sync started without confirmation. Inside an inline handler, `confirm` resolved to the form's hidden field named `confirm` | Handlers call `window.confirm(...)` (sync buttons and "Mark resolved") | `UatDefectRegressionTest` |
| D2 | UAT-02 | The source payment list showed "Paid total" 18,500 and "Cash" 11,500: it counted a paid payment of a cancelled enrollment, unlike the FRC Finance Report (18,000 / 11,000) | Paid, cash and online totals count only approved, active or completed enrollments; the excluded paid amount is stated on the card | `FrcReconciliationTest` |
| D3 | UAT-06, UAT-08 | When a request was refused with a reason not tied to a visible field (cash/bank journal guard, vendor overpayment), the user saw only "Please review the highlighted information." | The error banner lists each message | `UatDefectRegressionTest` |

Two further first-run failures (UAT-01 actor, UAT-03 journal lines) were errors in the test script's text matching; the screens were correct. The script was corrected and the whole run repeated from a fresh UAT database.

## 4. Smoke test of every screen (`tests/Uat/browser/smoke.mjs`)

Run before the FRC integration screens existed: 8 roles signed in, 589 page visits (every sidebar page per role at desktop width; tablet and phone widths for Administrator and Cashier), 0 HTTP errors, 0 server error pages, 0 JavaScript errors, 0 horizontal overflow, 984 links checked with none broken, and 17 of 17 forbidden URLs refused with 403.

**Re-run including the FRC integration and fee posting screens (14 October 2026):** 8 roles signed in, 670 page visits, 0 HTTP errors, 0 server error pages, 0 horizontal overflow, 1,277 links checked with none broken, 17 of 17 forbidden URLs refused with 403. One page visit (`/frc-integration/exceptions` at tablet width) logged `net::ERR_NO_BUFFER_SPACE` while loading a resource, a Windows network-buffer error in the test machine; the page was re-opened three times at the same width with status 200, no JavaScript errors and no overflow. Results: `storage/uat/smoke-results.json`.

A first attempt at this re-run was interrupted when the machine's session ended; the result files of the final workflow run were in a temporary folder cleared by the same interruption, and the workflow figures above were read from them before that. Both scripts now write to `storage/uat/` (git-ignored).

## 5. Not covered by this UAT

- Accrual-basis fee posting in the browser. It is covered by automated tests only (`FeePostingTest`).
- The real FRC Management Software was not part of this UAT. Its endpoints were later activated locally (14 October 2026) and the local data was synchronised and reconciled by command, not in the browser; nothing has been posted (`FRC_FEE_INTEGRATION_DESIGN.md` §2).
- Real Chart of Accounts, real Director, opening balances, real payroll figures.
- Safari, Firefox, real phones and tablets (Chrome with device emulation only).
- Load, backup/restore and penetration testing.
- UAT by the client's own staff.
