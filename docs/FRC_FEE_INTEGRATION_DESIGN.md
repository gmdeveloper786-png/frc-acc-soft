# FRC Fee Integration — Design and API Contract

**Status:** the accounting side is built against this contract and tested. The two endpoints were **approved by the owner on 14 October 2026 and implemented in the FRC Management Software (Laravel 10)** exactly as listed in §2, with tests. They were **activated in the LOCAL development environment on 14 October 2026** (index migration run, token re-issued, first real sync reconciled; see §2, "Activation"). They are **not deployed to production**.

## 1. What the FRC software does today (inspected read-only)

| Question | Finding |
|---|---|
| What is one payment? | One row in `payments`; each partial payment is a separate row. |
| Stable identifier | `payments.id`. `receipt_number` (`FRC-000001`) is assigned once, only when a payment becomes paid. |
| Settled payment | `status = paid`. `verified_at` is also written on rejection, so it does not mean settled. |
| How a payment becomes paid | Desk cash payment: created as `paid`. Uploaded slip: `pending_verification`, then verify → `paid` or reject → `rejected`. |
| Edit, cancel, refund | No code path exists. `cancelled` and `refunded` exist only as status values. |
| Deletion | Only unpaid (pending/rejected) payments are ever hard-deleted, together with their enrollment. Paid payments are never deleted. |
| Branch and service | Come from the enrollment (`payments.enrollment_id → enrollments.branch_id, service_id`). An enrollment edit can change them later without touching the payment row. |
| Fee and discount | `enrollments.final_total` already includes the discount. `paid_amount` and `remaining_amount` are recalculated from paid payments. |
| `updated_at` | Updated on every payment change; there is no index on it. |

### How the Finance Report (`/reports/finance`) calculates its cards

| Card | Calculation |
|---|---|
| Total Expected | Sum of `enrollments.final_total` for enrollments that are approved, active or completed (one value per enrollment). |
| Total Paid | Sum of `payments.amount` with status `paid`, for payments whose enrollment is approved, active or completed. |
| Cash Received | Same, method `cash`. |
| Online/Bank Received | Same, methods `bank_transfer`, `easypaisa`, `jazzcash`, `card`, `other`. |
| Pending | Sum of the stored `enrollments.remaining_amount` for approved or active enrollments. |
| Pending Verification | Sum of `payments.amount` with status `pending_verification`. |
| Payment Records | Count of payment rows of those enrollments, all statuses. |

Date filters apply to `payments.payment_date` and affect only the payment cards; branch, service and therapist filters use the enrollment's current values. The report has no staff branch scoping. In the table, Total Fee/Paid/Remaining repeat on every payment row of the same enrollment, so those columns must never be summed.

## 2. Read-only endpoints (Laravel 10 change — approved 14 October 2026, implemented, activated locally, not deployed to production)

Both follow the existing integration pattern: prefix `api/integrations/accounting/v1`, middleware `auth:sanctum, active_user, accounting_integration, throttle:60,1`, GET only, deny by default.

### `GET /payments`

Query: `updated_since` (ISO-8601, optional), `after_id` (with `updated_since`, for a stable cursor), `per_page` (1–100, default 50), optional `date_from`/`date_to` on `payment_date`. Ordered by `(updated_at, id)`. Includes soft-deleted rows with `deleted_at`.

```json
{
  "data": [{
    "id": 1201,
    "receipt_number": "FRC-000045",
    "enrollment_id": 330,
    "amount": "3000.00",
    "payment_method": "cash",
    "status": "paid",
    "payment_date": "2026-09-14",
    "verified_at": "2026-09-14T10:22:00+05:00",
    "created_at": "...", "updated_at": "...", "deleted_at": null,
    "enrollment": { "id": 330, "branch_id": 2, "service_id": 5, "status": "active", "updated_at": "..." }
  }],
  "meta": { "per_page": 50, "has_more": true, "next_updated_since": "...", "next_after_id": 1201, "server_time": "..." }
}
```

### `GET /enrollments`

Same cursor parameters. Fields: `id`, `branch_id`, `service_id`, `status`, `payment_status`, `final_total`, `discount_amount`, `paid_amount`, `remaining_amount`, `approved_at`, `created_at`, `updated_at`, `deleted_at`.

### Never exposed

Child, guardian or staff names, email, phone, CNIC, GR number, therapist, notes, discount reasons and files, payment slips and their paths, `transaction_reference`, rejection reasons, `zakat_eligibility`, any clinical data.

### Laravel 10 changes made

1. `routes/api.php` — two GET routes inside the existing integration group (`integrations.accounting.v1.payments`, `...enrollments`).
2. `AuthorizeAccountingIntegration::ALLOWLIST` and `DenyAccountingIntegrationTokenOutsideAllowlist::ALLOWED_ROUTES` — add both route names with abilities `accounting.payments.read` and `accounting.enrollments.read`.
3. `app/Models/Permission.php`, `PermissionSeeder`, `RolePermissionSeeder` — two permissions for the integration role only.
4. `ProvisionAccountingIntegrationToken` — add the two abilities; existing tokens must be re-issued (rotation).
5. Two controller methods using the existing per-page clamp, and two API resources returning only the fields above.
6. A migration adding indexes `(updated_at, id)` on `payments` and `enrollments`.
7. Extend `tests/Feature/Integration/AccountingIntegrationTest.php`: abilities, allowlist, field whitelist, cursor order.

Security impact: read-only, same token, two new abilities; no write paths; the integration user stays blocked from every other API route.

Implementation notes: the controller is `Api\Integration\AccountingFinanceController`; resources `AccountingEnrollmentResource` and `AccountingPaymentResource`; migration `2026_10_14_000001_add_sync_cursor_indexes_to_payments_and_enrollments`. Enrollment amounts are the **stored** columns, because the FRC model's `paid_amount` and `remaining_amount` accessors recalculate from payments while the Finance Report's "Pending" card uses the stored remaining amount. `after_id` requires `updated_since`; `per_page` is clamped to 1–100; invalid dates return 422. Tests: `tests/Feature/Integration/AccountingIntegrationTest.php` (17 tests: abilities per endpoint, a normal user's `*` token refused, exact field lists, no personal data, soft-deleted rows, cursor paging with equal timestamps, date filters, read-only).

### Activation

Each environment needs the owner's go-ahead. Steps:

1. Take and verify a backup of both databases and of the Accounting `.env`.
2. Deploy the FRC code and run only the reviewed migration `2026_10_14_000001_add_sync_cursor_indexes_to_payments_and_enrollments` (two indexes; no data change).
3. Re-issue the integration token with the four abilities: `php artisan frc:accounting-integration-token --accounting-env=<path to the Accounting .env>` (writes the token straight into the Accounting `.env` without displaying it; the previous token is revoked).
4. In the Accounting software: a manual full sync (`/frc-integration` or `php artisan frc:sync-financial --full --confirm`), a second sync to confirm no duplicates, then compare the mirror with the FRC Finance Report for the same filters.
5. Nothing is posted until the fee policy, Chart of Accounts and settlement mappings are approved.

#### LOCAL development activation — done 14 October 2026

| Item | Verified result |
|---|---|
| Backup | Both local databases and the Accounting `.env`, verified complete, in a folder outside the web root restricted to the owner, Administrators and SYSTEM (`PRODUCTION_READINESS_CHECKLIST.md` §6) |
| Laravel 10 migration | The index migration ran; no other migration pending; payments and enrollments unchanged |
| Integration token | One dedicated token, four read-only abilities (`accounting.branches.read`, `accounting.services.read`, `accounting.enrollments.read`, `accounting.payments.read`), expires 12 January 2027; the previous token revoked. Never displayed |
| Endpoint security | All four endpoints 200 with the token; no or invalid token 401; POST/DELETE 405; normal FRC API with the integration token 403 |
| Branches / services | 6 / 9, unchanged and still synchronised |
| First full sync | 6 enrollments and 7 payments created, 0 failed |
| Second full sync and incremental sync | 0 new, 0 updated; the incremental run read 0 records |
| Source vs mirror (Finance Report rules) | 7 records; paid PKR 18,000; cash PKR 11,000; online/bank PKR 7,000; pending verification 0; cancelled/refunded 0; every record matched on receipt, amount, method, status, date, branch and service |
| Duplicates / sync exceptions | 0 / 0 |
| Source records | Unchanged (no write by the integration) |
| Journals, fee postings, Chart of Accounts | 0 posted, 0 postings, 0 accounts imported |

Not done locally: browser review of the integration screens with the real development data (no login session was available; the synthetic browser UAT covers these screens), and a side-by-side check against the FRC Finance Report page in its own UI (the comparison applied the report's rules to the source database).

#### Production — not done

None of the steps above has been performed on any production system.

## 3. Synchronisation (Laravel 13, implemented)

1. Enrollments first, then payments, each with its own incremental cursor `(updated_since, after_id)` from the last successful run; a manual full sync is also available.
2. Each record is validated (types, statuses, methods, decimal amounts with at most two decimals, dates). Invalid records are skipped and recorded as sanitised exceptions; the rest continue.
3. Upsert by the FRC id (unique). A content hash detects real changes; unchanged records are counted, not rewritten. Out-of-order or stale records (older `updated_at` than stored) are ignored.
4. FRC branch and service ids resolve to the local mirrors; unknown ids leave the record unmapped with an exception, never auto-created.
5. A source change to a payment that is already posted never edits the journal: the payment is flagged `needs_review` with an exception.
6. Timeouts and transient errors use the existing client retry; a failed run keeps its cursor so the next run repeats safely. Tokens and response bodies are never logged.

## 4. Posting (Laravel 13, implemented, blocked by policy)

Posting is a separate, explicit step by an authorised user, after a preview. It runs only when `fees.accounting_policy_approved = 1` (Director setting) and all mappings exist.

| Basis (`fees.accounting_basis`) | Event | Journal |
|---|---|---|
| Cash (proposed default, not approved) | A payment with status `paid` | Dr settlement cash/bank account · Cr fee income |
| Accrual | Enrollment approved/active/completed | Dr Accounts Receivable · Cr fee income (final_total) |
| Accrual | A payment with status `paid` | Dr settlement cash/bank account · Cr Accounts Receivable |

- Fee income: the service's own fee income ledger only (GL Mapping → Service-specific fee income). It must be an active, postable income ledger. A payment of a service without one stays synchronised and unposted with an actionable configuration error; General Fee Income is never used for synchronised payments.
- Settlement: `fee_settlement_mappings` per branch and payment method → a cash or bank account of that branch (cash method → cash account). Card, Easypaisa and JazzCash are mapped separately.
- Pending, rejected, cancelled, refunded or deleted payments, and payments of non-approved enrollments, are never posted.
- Idempotency: unique `source_key` (`frc_payment:{id}`, `frc_enrollment:{id}:v{n}`) on both the posting link and the journal.
- Refund, cancellation or a changed amount after posting: the payment is flagged for review; an authorised user reverses the posting through the Journal Engine reversal and, if appropriate, posts again. Posted lines are never edited.
- An enrollment total change under accrual posts an adjusting journal for the difference only.
- Payments use voucher type `FR`; receivables use `JV`.

## 5. Reconciliation

For the same scope (dates on `payment_date`, branch, service), the reconciliation screen compares: source counts and totals (paid, cash, online/bank, pending verification, rejected/cancelled/refunded) computed from the mirror with the Finance Report's rules; the mirrored records; amounts eligible, posted, unposted and reversed; and open exceptions. It reports "Reconciled" only when every eligible paid payment is posted once with the same amount and there are no open exceptions; otherwise it lists each difference.

The Finance Report screenshot (7 records, PKR 18,000 paid, PKR 11,000 cash, PKR 7,000 online/bank) is a diagnostic reference only; it is not asserted, because source data changes.

## 6. Open decisions

Cash or accrual basis; which cash/bank account each method settles into per branch; approval of the two FRC endpoints; treatment of refunds when the FRC software starts recording them.
