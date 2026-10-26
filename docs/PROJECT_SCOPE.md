# FRC Accounting Software — Approved Scope

## Milestone 7 delivered scope

Milestone 7 adds fixed assets with straight-line depreciation, quantity-only inventory, budgeting with Budget vs Actual, and a payroll foundation with a secure Excel import, together with protection against manual journals paying money out of cash or bank.

The Journal Engine remains the only source of financial truth. Depreciation and payroll accruals post through it as system journals; salary is paid through the existing payment workflow. Inventory and budgets never post to the General Ledger.

Accounting policies the client has not approved are not assumed. Asset registration, depreciation previews, payroll import, validation and approval all work as drafts, while depreciation posting and payroll accrual posting stay blocked until a Director approves the corresponding policy setting. No tax, leave, AKKF or statutory rule is calculated by the system.

No employee, salary, asset, stock item or budget was created in the real database, and the client payroll workbook was not imported.

## Milestone 6 delivered scope

Milestone 6 adds the Chart of Accounts approval review, dynamic GL mapping, an administrator-only setup readiness screen, and purchase orders linked to purchase bills.

The Chart of Accounts remains empty until the client approves an exact row-level selection. The review proposes a treatment for each workbook row but never approves one; a school-labelled account is not excluded because of its name, since School Readiness Program is a normal FRC service. `docs/COA_APPROVAL_SUMMARY.md` is the approval request for the client and accountant.

A purchase order is a procurement commitment and never posts to the General Ledger. Approving a purchase order does not receive goods and creates no inventory record; a future quantity-only inventory receipt can reference `purchase_order_lines`. Payables are recognised once, by the purchase bill, through the Journal Engine.

Missing required GL mappings stop the dependent posting with a configuration error. No mapping, ledger code, or account identifier is hardcoded.

## Milestone 4 delivered scope

Milestone 4 adds the shared double-entry journal engine. Journal lines are the authoritative source for balances; drafts and approvals do not affect reports. An authorized maker creates and submits, a separate checker approves, and an authorized poster performs an atomic revalidation and posting transaction. Voucher numbers are allocated only inside successful posting with locked database sequences.

Posted journal headers and lines are immutable. Corrections use a linked opposite reversing journal in an eligible open period; both original and reversal remain visible in the General Ledger and Trial Balance. Fiscal-year, accounting-period, permission, and branch checks are enforced server-side at every financial transition.

Opening Balance batches now require separate approval and post through this same engine. No account balances are independently stored or mutated. Real posting remains unavailable in practice until an approved postable Chart of Accounts and client-approved opening figures exist; no accounts, financial journals, or opening values were fabricated.

Cash receipts, bank receipts/payments, purchase bills, payroll, cash/bank books, and FRC operational payment posting are foundations only and are not claimed as completed workflows.

## Milestone 3 delivered scope

Milestone 3 adds fiscal years, monthly accounting periods, the shared organisation-level hierarchical Chart of Accounts, a private workbook review/selection workflow, and branch-scoped opening-balance preparation. Fiscal setup and account master changes are database-driven, permission-protected, and audited.

The supplied workbook remains a review source. Its 226 Chart of Accounts rows are not automatically imported. Legacy `School`, `Rehab Centre`, inter-unit, owner/private, and `[Confirm]` signals are shown for manual review; an account is created only after an authorized user explicitly selects a structurally valid row. School Readiness Program remains a normal FRC service and no separate school entity or branch-specific chart is introduced.

Opening figures use fixed-precision decimals and must balance exactly. Milestone 3 created drafts only; Milestone 4 now provides separately authorized posting through the shared Journal Engine. Period locks and financial posting now use coordinated transactional guards.

## Milestone 2 delivered scope

Milestone 2 adds database-driven roles and permissions, protected user and role administration, local read-only mirrors for FRC branches and published services, reusable server-side branch scoping, dynamic accounting settings, and audit logs for sensitive administration and synchronization actions.

The existing FRC application remains the only source of branch and service master data. Accounting stores external FRC IDs and never creates, edits or deletes those source records. Published service synchronization treats School Readiness Program like every other FRC service; no separate school company or department exists.

The confirmed integration path currently uses `/services/published`. Unpublished or inactive service synchronization is not claimed until a suitable read-only source endpoint and integration permission are confirmed.

## Product model

FRC Accounting Software serves one organisation, Faizan Rehabilitation Centre, with multiple branches. Branches and services are mastered in the existing FRC Management Software. School Readiness is a normal FRC service; it is not a separate company, school accounting entity, or department.

The older requirements DOCX and Chart of Accounts workbook contain pre-approval references to separate School and Rehabilitation entities. Those references are historical discovery inputs and do not override the approved product model above. The Chart of Accounts is not imported in the foundation milestone and must be reconciled with the approved model before its own milestone.

## Ownership boundary

Existing FRC owns branches, services, operational client records, enrollments, operational payments, staff, and therapists. Accounting owns the Chart of Accounts, fiscal setup, opening balances, journals, vouchers, ledgers, cash and bank, receivables, donations, expenses, vendors, purchasing and approvals, petty cash, payroll, fixed assets, quantity inventory, budgets, financial reports, audit records, users, permissions, and accounting settings.

Integration is through environment-configured HTTP APIs. Databases remain separate. FRC source identifiers and idempotency keys are mandatory before future synchronization can create accounting-side records. Operational payments are not posted automatically in this milestone.

Source inspection confirmed the Laravel 10 API prefix is `/api` and it uses Laravel Sanctum bearer authentication plus active-user middleware. The following read endpoints exist: `GET /branches`, `GET /services/published`, `GET /children`, `GET /enrollments`, and `GET /payments`. Children, enrollments, and payments require their respective management permissions; branches and published services still require an authenticated active user. A future dedicated integration account must receive only the minimum read permissions. No Laravel 10 user, token, permission, endpoint, or source file was changed in this milestone.

## Approved business rules

- Financial year: 1 January through 31 December.
- Initial opening date: 1 January 2026. Earlier history remains in Excel/archive sources initially.
- Default currency: PKR, configurable.
- Donation categories: General Donation, Zakat, and New FRC Project Donation; initially manual entry.
- Purchase orders above the configurable PKR 150,000 threshold require Director approval; Director is final approver.
- Payment approval is separate from PO approval. Up to the configurable threshold requires an authorized approver; above it requires Director approval. A creator cannot approve their own payment.
- Inventory initially tracks quantities received, issued, balance, and issued-to only. No cost valuation is inferred.
- Fixed assets use straight-line depreciation.
- Budget vs Actual is required. The warning threshold defaults to 80% and remains configurable.
- Payroll rules are deferred until the supplied payroll workbook is reviewed. Unvalidated payroll is never posted.

## Accounting invariants

- Posted journal debits must equal credits.
- Posted entries cannot be silently changed or deleted; correction uses reversal or corrective entries.
- Closed periods reject unauthorized posting.
- Financial history and audit records remain traceable.
- Monetary values use fixed-precision decimal handling, never binary floating point.

## Dynamic-data policy

Mutable business values belong in database settings or centralized environment-backed configuration. This includes organisation identity, currency, formatting, branches, services, fiscal years, account codes, numbering, bank accounts, payment methods, approval limits, categories, roles, permissions, budget warnings, payroll components, and integration credentials. Domain invariants remain code-enforced.

## Laravel 10 approval policy

Laravel 10 is read-only. If a change becomes necessary, the proposal must identify the exact change, rationale, affected files, risks, no-change alternative, and test plan. Work waits for explicit approval specific to that proposal. Its database, environment, dependencies, credentials, and production configuration are never modified without approval.

## Foundation milestone

This milestone includes Laravel 13 configuration, development documentation, authentication, responsive reusable UI, a no-fabrication accounting dashboard, the FRC API client foundation, and automated tests. It deliberately excludes the full accounting engine, complete data synchronization, RBAC, financial posting, and business-module workflows.
