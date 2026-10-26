# FRC Payment Integration — QA

**Status:** the accounting side is IMPLEMENTED and TESTED against the API contract with simulated responses and a synthetic API server. The two FRC endpoints were approved and implemented in the FRC software on 14 October 2026 and activated in the LOCAL development environment the same day: 6 enrollments and 7 payments were mirrored and reconciled with the source (see `FRC_FEE_INTEGRATION_DESIGN.md` §2). Nothing has been posted, and nothing is deployed to production. Real fee posting is additionally BLOCKED by the Chart of Accounts, settlement mappings and the fee policy approval.

Contract, source rules and accounting design: `FRC_FEE_INTEGRATION_DESIGN.md`.

## 1. Components

| Component | Where | Purpose |
|---|---|---|
| API reader | `app/Services/Frc/FinancialSync/FrcFinancialApi.php` | Pages `enrollments` and `payments` with an `(updated_since, after_id)` cursor through the existing `FrcApiClient` (bearer token, timeouts, retries) |
| Normaliser | `FrcRecordNormalizer`, `InvalidFrcRecord` | Whitelists fields, validates statuses, methods, amounts (≤ 2 decimals) and dates; any other field (for example a name) is dropped |
| Sync | `FrcFinancialSyncService`, command `frc:sync-financial --confirm` | Enrollments then payments; per-page transactions; upsert by FRC id; change detection by content hash; stale records ignored; run history; sanitised exceptions; one sync at a time |
| Mirror | `frc_enrollments`, `frc_payments` | Read-only copies with local branch and service ids |
| Settlement mapping | `fee_settlement_mappings`, `/fee-settlement-mappings` | Branch × payment method → cash or bank account of that branch |
| Posting | `app/Services/FeePosting/*`, `/fee-posting/*` | Eligibility, preview, authorised posting, reversal; cash and accrual basis |
| Reconciliation | `FrcReconciliationService`, `/frc-integration/reconciliation` | Source figures (Finance Report rules) vs mirror vs postings; receipt-level differences; export |

## 2. Screens

| Screen | Route | Permission |
|---|---|---|
| FRC Financial Integration (status, last sync, manual and incremental sync, connection check) | `/frc-integration` | view; sync for the buttons |
| Source payments (filters, totals) | `/frc-integration/payments` | view |
| Payment detail | `/frc-integration/payments/{id}` | view |
| Unmapped payments | `/frc-integration/payments/unmapped` | view |
| Sync history | `/frc-integration/sync-runs` | view |
| Sync exceptions | `/frc-integration/exceptions` | view; sync to resolve |
| Reconciliation and export | `/frc-integration/reconciliation` | view; export needs `financial_statements.export` |
| Settlement mapping | `/fee-settlement-mappings` | `fee_settlement.manage` to change |
| Posting preview and post (payments) | `/fee-posting/payments` | view; post needs `frc_integration.post` + `journals.post` |
| Enrollment recognition (accrual only) | `/fee-posting/enrollments` | same |
| Posted fee receipts and reversal | `/fee-posting/postings` | view; reversal needs `frc_integration.reverse` + `journals.reverse` |

All lists are scoped to the user's branches. Accountant and Administrator can sync, post and reverse; Approver, Director, Management and Auditor can view.

## 3. Journal entries

| Basis | Event | Voucher | Entry | Source key |
|---|---|---|---|---|
| Cash | Paid payment | FR | Dr settlement account · Cr fee income | `frc_payment:{id}` |
| Accrual | Enrollment approved/active/completed | JV | Dr Accounts Receivable · Cr fee income (final total) | `frc_enrollment:{id}:v1` |
| Accrual | Final total changed | JV | Difference only, sign-aware | `frc_enrollment:{id}:v{n}` |
| Accrual | Paid payment | FR | Dr settlement account · Cr Accounts Receivable | `frc_payment:{id}` |
| Either | Reversal | as original | Opposite journal through the Journal Engine | reversal of the original |

Fee income is the service's own ledger only. A service without an active, postable income ledger blocks posting of its payments (they stay synchronised and unposted, with a configuration error in the preview); General Fee Income is not used for synchronised payments. Every line carries the service. After a reversal a payment can be posted again under `frc_payment:{id}:r{n}`; income is still recognised once overall.

## 4. Eligibility for posting

A payment is posted only when every rule passes; failures are listed with reason codes: policy approved; no posting under the other basis; status `paid`; not deleted at source; enrollment mirrored, approved/active/completed and not deleted; amount above zero; not flagged for review; not already posted; branch and service resolved and active; valid settlement mapping; fee income (and receivable under accrual) mapped; enrollment recognised (accrual); open period on the payment date; user permitted and branch accessible.

The source status alone never authorises posting: local permissions, the policy flag, period checks and mappings always apply.

## 5. Automated tests (executed)

| File | Tests | Covers |
|---|---|---|
| `FrcFinancialSyncTest` | 36 | token sent; 401/403/404/timeout/malformed → failed run, cursor kept, token never stored; 3-page pagination; incremental cursor; duplicates across pages and runs; updates; stale records; invalid records; partial and pending payments; unmapped branch/service; change after posting → review without touching journals; extra personal fields dropped; sync lock; permissions and branch scope; command confirmation; branch/service sync unchanged |
| `FrcReconciliationTest` | 7 | 7 paid = 18,000 (11,000 cash, 7,000 online) with pending, rejected and a cancelled-enrollment payment; reconciled only when all eligible are posted once; each difference type; filters; export; payment list totals follow the Finance Report rule |
| `FeePostingTest` | 34 | exact cash-basis journal; idempotent and concurrent double post; ineligible statuses never posted; each blocking reason; accrual recognition, payment, adjustment, single income recognition; basis conflict; reversal; permissions; branch scope; service- and branch-wise income; balanced Trial Balance; preview writes nothing |
| `FeeSettlementMappingTest` | 12 | cash/bank account type rules; same-branch and active account; independent card/Easypaisa/JazzCash mappings; audit; permissions |

Full suite after the UAT fixes: **588 passed, 0 failed** (Pest, PHP 8.3.14).

## 6. End-to-end UAT

Executed in a real Chrome browser against the isolated UAT database and the synthetic FRC API: sync, mapping, cash-basis posting, ledger impact, reversal, reconciliation and exports all passed. Accrual basis was tested by automated tests only. Results in `UAT_EXECUTION_REPORT.md`.

## 7. Limitations

- The FRC endpoints are active locally only; production needs its own activation (`FRC_FEE_INTEGRATION_DESIGN.md` §2).
- FRC hard-deletes unpaid payments without a tombstone. A pending payment that disappears at source stays in the mirror as pending; it is never posted, so this does not affect the ledger.
- An enrollment edit in FRC changes branch or service for its earlier payments. The sync follows the current values; a posted payment whose branch changes is flagged for review.
- Cancelling an enrollment after accrual recognition is flagged; the reversal is a manual, authorised action.
- Whether reversing a fee receipt above the payment threshold should need Director approval is an open client decision.
