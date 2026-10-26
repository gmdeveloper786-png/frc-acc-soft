# FRC Accounting Software — Implementation Plan

## Milestone 1: Foundation (implemented)

- Laravel 13 application configuration for FRC Accounting, Asia/Karachi, English, Vite, Blade, and Bootstrap 5.3.
- Database-backed application setting foundation with environment-backed defaults.
- Secure login/logout, password recovery/reset, profile update, password change, inactive-user enforcement, CSRF protection, session regeneration, and login throttling.
- No public registration route.
- Interactive `app:create-administrator` command; no generated credentials.
- Responsive guest and application layouts, collapsible desktop sidebar, mobile drawer, topbar, profile menu, cards, controls, badges, alerts, tables, and empty states.
- Dashboard KPI shells, financial overview, activity, branch/fiscal context, alerts, and explicit unavailable states without fabricated balances.
- Environment-configured FRC API client with bearer authentication, timeouts, temporary-failure retry, pagination helpers, safe errors, and read-only connection check.
- Pest coverage for authentication, protection rules, password reset, profile access, and mocked API behavior.

## Milestone 2: Access control and master foundations (implemented)

- Spatie Laravel Permission v8 with grouped permissions and seven initial database roles.
- Accounting users with activation, role/branch assignment, self-escalation prevention and last-active-Administrator protection.
- Dynamic role creation/editing and permission assignment from database records.
- Read-only branch and published-service mirrors using unique external IDs and idempotent upserts.
- Reusable server-side branch access scope and route middleware.
- Validated settings administration with separate PO/payment thresholds and safe logo upload.
- Audit events for access changes, settings changes and synchronization outcomes.

Fiscal years and accounting periods were delivered in Milestone 3. Voucher types and concurrency-safe sequences are delivered with the Milestone 4 journal engine; payment methods and bank/cash masters remain future operational-finance setup.

## Milestone 3: Accounting setup (implemented)

- Fiscal years with non-overlap validation, 12 generated monthly periods, Draft/Open/Closed lifecycle, single current-year selection, and audited close/reopen controls.
- Accounting-period open/lock/reopen controls and posting-availability guard. Journal-dependent closing checks are explicitly pending Milestone 4.
- Shared organisation-level Class/Group/Sub-Group/Ledger Chart of Accounts with classification, normal-balance, statement, hierarchy, cycle, posting, activation, and history-preservation guards.
- Private Excel preview for the supplied Chart of Accounts workbook with source-column validation, duplicates, parent checks, manual-review flags, explicit row selection, and idempotent confirmation. No automatic workbook import was performed.
- Branch-scoped opening-balance preparation and Excel/CSV preview with fixed-precision debit/credit validation. Drafts cannot be posted until the journal engine exists.
- Permission-aware FRC-styled screens, audit events, forward-only migrations, and regression tests.

## Milestone 4: Journal engine and ledgers (implemented)

- Database-driven voucher types plus fiscal-year/global sequence scopes, row locking, unique constraints, and voucher allocation only during successful posting.
- Draft, Submitted, Approved, Rejected, Posted, and Reversed journal lifecycle with separate creator/submitter/approver/poster identities and maker-checker enforcement.
- Exact `DECIMAL(19,4)` and Brick Math validation; only active postable ledgers, open fiscal years/periods, authorized branches, and valid optional services are accepted.
- Atomic posting, duplicate/source idempotency controls, immutable posted headers/lines, authorized linked reversals, and sanitized audit events.
- Approved branch-scoped opening-balance batches post through the same journal engine and cannot be posted twice.
- General Ledger with exact opening/running/closing balances and stable pagination; hierarchical Trial Balance with ledger-only grand totals and integrity warning.
- Permission-aware FRC UI, print styles, backend branch isolation, and automated accounting/regression coverage.
- Cash/bank books and operational payment posting remain future modules; no FRC payment was posted automatically.

## Milestone 5: Operational finance (implemented)

- Cash and bank accounts, receipts, payments, expenses, petty cash, manual donations, vendors, purchase bills, vendor payments, cash/bank books, and registers, all posting through the Journal Engine.
- Configurable PKR 150,000 payment approval threshold with separate maker-checker and disbursement controls.
- Bank reconciliation and FRC fee receivables remain future work.

## Milestone 6: Chart of Accounts readiness, GL mapping, and purchase orders (implemented)

- COA Approval Review: every workbook row is classified (shared, Rehab-specific, school-only, manual review), given a proposed decision with a reason, and stays pending until an authorized reviewer approves or excludes it. Flagged rows need a documented reason; parents must be approved with or before their ledgers; only approved rows can be imported. CSV approval summary export. No workbook row was imported.
- GL Mapping: database-driven control mappings (Accounts Receivable, Accounts Payable, Purchase Bill control, General Fee Income, Default Expense, Petty Cash) plus service-specific fee income on `frc_services.revenue_account_id`. Cash/bank and donation mappings keep their existing tables. Only active, postable, classification-compatible ledgers are accepted; changes are permission-protected and audited. Purchase bills, receipts, payments, and accrued expenses resolve a blank ledger from the mapping and stop with a configuration error when it is missing.
- Accounting Setup Readiness: administrator-only screen reporting the real state of the chart, postable ledgers, open fiscal year and period, cash/bank, fee revenue, donation and payable mappings, and required settings.
- Purchase orders: draft, submit, approve/reject, issue, close, and cancel with append-only history, branch scoping, maker-checker, and immutable commercial terms after submission. Numbers come from a dedicated locked `document_sequences` table and settings-driven prefix, padding, and reset; financial voucher sequences are not reused.
- PO approval: above the configurable `approval.purchase_order_threshold` requires `purchase_orders.approve_high_value` (Director). At or below it the configurable `approval.purchase_order_standard_policy` applies. Nothing is auto-approved.
- PO to bill: a purchase bill may reference an approved or issued PO; multiple and partial bills are tracked by value and line quantity. A bill above the remaining PO needs a documented exception approved by a user holding `purchase_bills.approve_po_exception`. A purchase order never posts to the General Ledger.
- Fee integration is designed in `docs/FRC_FEE_INTEGRATION_DESIGN.md`; no client, enrollment, or payment data is synchronised.

Outstanding decisions: the client's row-level Chart of Accounts approval; who approves purchase orders at or below the threshold; the New FRC Project Donation ledger; cash or accrual basis for fee income; approval of new read-only Laravel 10 integration endpoints.

## Milestone 7: Fixed assets, inventory, budgets, and payroll (implemented)

- **Financial bypass protection.** `ManualJournalGuard` stops a manual journal from crediting a cash, bank or petty cash ledger against another account at submit, approve and post; contra transfers and cash debits remain allowed. Server-built journals post through `JournalPostingService::postSystem()` with a server-assigned source. `SplitPaymentDetector` audits and shows a warning when several outflows within the threshold together exceed it for one bill or payee; no aggregate limit is enforced.
- **Fixed assets.** Configurable categories with three GL ledgers, asset register with documents and append-only history, straight-line depreciation with exact decimals and the remainder in the final period, depreciation runs (prepare, submit, approve, post, reverse) posting one `JV` journal per run. Registering an asset posts nothing: cost is recognised by the purchase bill. Accumulated depreciation is derived from posted run lines, never stored as a mutable balance.
- **Inventory.** Quantity only: items, categories, locations, receipts, issues with recipient, returns, approved adjustments and branch transfers. Balances are locked rows that always equal the sum of posted movements. No price, valuation or journal exists in this module, and approving a purchase order never creates stock.
- **Budgets.** Versioned annual budgets per fiscal year, branch and optional service, with maker-checker approval and revisions. Budget vs Actual reads posted and reversed journals only, at ledger level, so reversals net to zero and parents are never double counted. Warnings use `budget.warning_percentage`; overruns are recorded once in `budget_alerts`. Purchase order commitments are shown separately. There is no hard stop.
- **Payroll.** Employees, configurable components, salary structures, runs (draft, validated, submitted, approved, posted, paid), a secure Excel import (upload, preview, column mapping, validation, error report, confirmation into a draft run) that never evaluates spreadsheet formulas, accrual posting as an `SV` journal, and salary payment only through the existing payment engine so the threshold and Director authority apply and expense is never recognised twice. Amounts are visible only with `payroll.view_sensitive`.

Posting stays blocked until the client decides: depreciation needs `assets.depreciation_policy_approved`, payroll accrual needs `payroll.calculation_policy_approved` plus the Salary Expense and Salary Payable mappings and a ledger on each deduction component. Both flags are Director-controlled approval settings. No tax, leave, AKKF or statutory formula was invented; those are entered or imported amounts.

The client payroll workbook was inspected for structure only. Five of its columns (Total Salary, Gross Salary and the three Paid Salaries columns) are `RANDBETWEEN` formulas in every row, so it has no usable gross or net figures and cannot be imported until the client supplies fixed values.

Not included: asset disposal gain or loss, asset transfer, impairment, inventory valuation, budget hard stops, payslips, and tax or leave calculation.

## Milestone 8: Reporting, exports, QA and release readiness (implemented)

- Financial statements from posted journals only: Income & Expenditure, Balance Sheet with an equation check and an unclosed-surplus line, branch-wise Income & Expenditure, service analysis with unallocated costs kept separate, Cash Flow (direct method, explicit ledger classification, reconciled to cash ledgers) and Receipts & Payments.
- Operational reports for fixed assets (including a missed-depreciation review report), inventory quantities, budgets (version and hierarchy selection) and payroll (amounts only with `payroll.view_sensitive`).
- CSV, XLSX and genuine PDF exports for every report through `App\Services\Reports\ReportExporter`, with formula-injection protection, branch scoping and audit entries.
- Read-only audit log viewer with branch scoping and masking; audit records are immutable and now carry the branch.
- Dashboard built from real data with honest empty states.
- Cross-module review: ten defects fixed, including double-payment and cross-branch editing paths.
- Real MySQL concurrency verification (`tests/Concurrency/run.php`): found and fixed over-payment, over-billing, transfer, duplicate-submission and sequence deadlock defects; 23 of 23 checks pass.
- Budget form submits one JSON payload and was tested with 250 ledgers × 12 periods.
- 497 automated tests passed at the end of the milestone; see `MILESTONE_8_QA_REPORT.md`, `PRODUCTION_READINESS_CHECKLIST.md`, `UAT_TEST_PLAN.md` and `CLIENT_POLICY_DECISIONS.md`.

## Final integration: FRC fee payments and end-to-end UAT (implemented; FRC endpoints approved and implemented, not deployed)

- Read-only inspection of the FRC Management Software finance logic and a minimal proposal for two read-only endpoints (`payments`, `enrollments`). Approved by the owner on 14 October 2026 and implemented in Laravel 10 with tests; not deployed.
- Payment and enrollment mirror with full and incremental sync, run history, sanitised exceptions and no personal data (`frc:sync-financial --confirm` or the FRC Financial Integration screen).
- Fee settlement mapping per branch and payment method; preview-then-post fee posting on cash or accrual basis through the Journal Engine, gated by the Director-approved fee policy; reversal and repost.
- Reconciliation of source figures (Finance Report rules), mirror and postings, with CSV, XLSX and PDF export.
- Browser UAT in Chrome on an isolated `_uat` database with a synthetic FRC API: 14 of 14 workflow cases and a smoke test of every screen per role. Three defects found and fixed. See `UAT_EXECUTION_REPORT.md` and `FINAL_HANDOVER_STATUS.md`.
- 588 automated tests pass.

## Next: go-live

- Deployment of the two FRC endpoints and re-issue of the integration token.
- Client decisions on the Chart of Accounts, Director appointment, opening balances and posting policies.
- UAT by client staff on the approved setup, following `UAT_TEST_PLAN.md`.
- Production environment hardening, backup and restore rehearsal, penetration and performance testing.
## Quality gates for every milestone

- No Laravel 10 change without explicit scoped approval.
- No secrets or private client data in source, logs, UI, fixtures, or documentation.
- No destructive database commands or uncontrolled financial seeds.
- Feature tests cover authorization, invariants, failure paths, and idempotency.
- Pint, targeted/full Pest tests, route inspection, and frontend production build pass before handoff.
