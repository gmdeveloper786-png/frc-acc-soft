# Financial Authorization

How approval authority is decided in FRC Accounting, how a Director is appointed, and which decisions are still open.

## Approval rules

| Document | At or below threshold | Above threshold |
|---|---|---|
| Purchase order (`approval.purchase_order_threshold`) | A user with `purchase_orders.approve`, unless `approval.purchase_order_standard_policy` is `director_only` | Director authority |
| Cash payment, bank payment, vendor payment (`approval.payment_threshold`) | A user with the `.approve` permission for that type | Director authority |
| Petty cash and an expense settled as paid (`approval.payment_threshold`) | A user with the `.approve` permission for that type | Director authority |
| Expense recorded as payable (accrued) | A user with `expenses.approve` | Same: no money leaves until it is paid through a payment |
| Purchase bill within its purchase order, or without one | A user with `purchase_bills.approve` | Same: the bill has no threshold; paying it does |
| Purchase bill above its purchase order (exception) | Director authority plus `purchase_bills.approve_po_exception` | Director authority |

Rules that apply in every case:

- The threshold is read from settings when the approval is attempted. The level stored on the record is informational.
- The user who created or submitted a record cannot approve or return it, whatever role they hold.
- The approver must be active and must have access to the record's branch.
- The checks run in `PurchaseOrderService`, `OperationalTransactionService` and `PurchaseBillService`. Routes and screens only reflect them.
- A refused attempt is audited (`*.approval_denied`, `purchase_bills.po_exception_denied`). A successful approval records its `authority_basis`.
- Money cannot be disbursed or posted before approval: disbursement requires the approved status and posting requires disbursement.

## What Director authority is

Director authority is **not a permission**. A user holds it when all of these are true:

- the user is active;
- one of their roles has `roles.approval_authority = 'director'`;
- none of their roles is a protected system role (Administrator).

The user also needs the matching permission for the action.

- Only the seeded Director role carries the marker. The Administrator role holds every permission but not the marker.
- The marker is not mass assignable and no form can set it. A protected role refuses it.
- Giving a high-value permission to a custom role grants nothing by itself.
- A role that carries the marker can be edited only by a Director.

## Changing approval thresholds and policies

These four settings are **approval controls**: `approval.purchase_order_threshold`, `approval.payment_threshold`, `approval.purchase_order_standard_policy` and `approval.delegation_max_days`.

- They are changed only on the Approval Controls screen (`/settings/approval-controls`) through `ApprovalSettingsService`, which requires Director authority and `approval_settings.manage`.
- A reason of at least 10 characters is mandatory.
- Each changed value is audited as `approval_settings.changed` with the key, old value, new value and reason; the audit row carries the actor and the time.
- The general Settings screen shows them read-only and ignores them if they are submitted, so `settings.update` alone cannot change them.

## Appointing a Director

Changing who holds a Director role is restricted:

- Through user management it requires an existing Director who also holds `approval_authority.assign`, a reason of at least 10 characters, and it can never be done to one's own account.
- One account cannot hold both Administrator and Director roles. This is refused in user management and in the console command, and such an account would hold no Director authority.
- A user without Director authority cannot change the email or password of a Director's account. They can still edit the name and branches, and can deactivate the account.
- Every grant or removal is audited as `approval_authority.director_granted` or `approval_authority.director_revoked` with the reason and source.
- The Director-only permissions (`payments.approve_high_value`, `vendor_payments.approve_high_value`, `purchase_orders.approve_high_value`, `purchase_bills.approve_po_exception`, `approval_delegations.manage`, `approval_authority.assign`, `approval_settings.manage`) are not given to the Administrator role and cannot be added to any other role through role administration. Each is also checked together with Director authority, so the permission alone never authorizes anything.
- The user forms show the Director role only to a user allowed to grant it. Anyone else sees a held Director role as read-only, and the server rejects forged requests either way.

### Trusted provisioning procedure

No Director is created automatically. The first Director, or a replacement when none is available, is appointed from the server console on the owner's written instruction:

```
php artisan app:director-authority grant --database=<db name> --email=director@example.org --branch=<id> [--branch=<id> ...] --reason="Owner letter dated ..."
php artisan app:director-authority grant --database=<db name> --create --name="Full Name" --email=director@example.org --branch=<id> --reason="Owner letter dated ..."
php artisan app:director-authority revoke --database=<db name> --email=director@example.org --reason="..."
```

1. The owner names the Director in writing.
2. Either an Administrator creates the user account with a non-Director, non-Administrator role, or the operator passes `--create` and types the password at a hidden prompt (it is never printed or logged).
3. The server operator runs the command. It prints the environment and database and refuses unless `--database` names that same database. A grant must list every branch the Director approves for with `--branch`; the user's branch assignments are set to exactly that list.
4. When an active Director already exists, a further appointment also needs `--authorized-by=<email>` naming an active Director who holds `approval_authority.assign`. Only the first Director is appointed on the operator's authority alone.
5. The operator confirms the prompt. An unattended run changes nothing, because the confirmation defaults to "no".
6. The owner checks the audit log entry. Its source is `console:<operating system user>`, and it records the environment, database, branches before and after, and the authorizing Director.

A user who holds the Administrator role, or carries the administrator flag, can never be provisioned as a Director.

### Changing an existing Director's branches

An Administrator cannot edit a Director account, so a Director's branch assignments are changed from the same console command:

```
php artisan app:director-authority add-branches --database=<db name> --email=director@example.org --branch=<id> [--branch=<id> ...] --reason="Owner letter dated ..."
php artisan app:director-authority remove-branches --database=<db name> --email=director@example.org --branch=<id> --reason="..."
```

- `add-branches` keeps every existing assignment and adds only the named branches that are not yet assigned. `remove-branches` removes only the named branches. Nothing else on the account changes.
- The command checks the database name, that the user holds Director authority, and that every `--branch` value is the id of an existing branch. It then prints the current branches, the change and the result, and asks for confirmation, which defaults to "no".
- When another active Director exists, `--authorized-by=<email>` must name that Director. The target can never authorize their own change.
- A Director must keep at least one branch; to remove every branch, revoke Director authority instead.
- A repeated run that changes nothing records nothing. Each real change is audited as `approval_authority.director_branches_changed`, with the branches before and after, the branches added and removed, the reason, the console source, environment, database and authorizing Director.

The command is not reachable from any web route. Console access is the trust boundary, the same as for `app:create-administrator`.

Until a Director is appointed, nothing above a threshold can be approved, no purchase bill exception can be approved and no approval control can be changed.

## Delegation

Director delegation exists in code but is **disabled** (`approval.delegation_enabled = 0`). While disabled, no delegation record authorizes anything and none can be created. The switch is on no screen, including Approval Controls; enabling it is a deliberate database change made after the owner approves a delegation policy. There is no delegation screen.

## Manual journals and cash or bank

A journal a user prepares cannot pay money out. `ManualJournalGuard` runs when a manual journal is submitted, approved and posted:

- A manual journal may not **credit** a cash, bank or petty cash ledger against any other account. Such ledgers are the ones linked to a cash or bank account, plus the Petty Cash mapping.
- It may debit them, and it may move money between two of them (a contra transfer).
- The guard does not look at voucher type, narration, reference or source fields. Source fields cannot be set from a request at all, and a source label written into the row gives no exemption.
- A blocked attempt is audited as `journal.cash_bank_credit_blocked`.

Journals built by server-side services (payments, purchase bills, opening balances, reversals, depreciation, payroll) are posted through `JournalPostingService::postSystem()`, which requires a server-assigned source and is never reachable with user-supplied lines.

Correcting a cash or bank entry is done by reversing the original through its own workflow. An exceptional manual cash or bank adjustment is therefore not possible today; if the client needs one, it requires a defined approval rule first.

## Split payments

`SplitPaymentDetector` warns when several outflows, each at or below the payment threshold, together exceed it:

- for the same purchase bill, or
- for the same vendor or payee name in the same branch within `approval.split_detection_days` (default 7; 0 switches it off).

The warning is audited when the payment is created (`<type>.split_warning`), shown to the approver, and recorded again in the approval audit entry. It does **not** change who may approve: no aggregate limit is enforced, because the client has not approved one.

Proposed policy for client confirmation: when the outflows detected for one obligation exceed the payment threshold in total, the payment that crosses the threshold, and every later one for that obligation, requires Director approval.

## Residual risks and open decisions

1. **Aggregate limit for split payments.** Detection and warning exist; enforcement awaits the policy above.
2. **Purchase bills have no threshold.** A bill of any value within its purchase order, or with no purchase order, is approved with `purchase_bills.approve`. It creates a payable, not a payment. Decide whether high-value bills or bills without a purchase order need Director approval.
3. **Accrued expenses have no threshold**, for the same reason.
4. **Reversing a receipt** credits cash or bank through the reversal workflow with the reversal permission only. Decide whether a reversal above the threshold needs Director approval.
5. **Contra transfers** between cash and bank ledgers need only ordinary journal approval, whatever the amount.
6. **Returning a record** needs only an approve permission, not Director authority, because it authorizes nothing.
7. Whether delegation is approved, and under what limits.
8. Whether one person may ever be both Administrator and Director.
9. Who approves purchase orders at or below the threshold.
10. Direct database or server access is outside application control and must be restricted operationally.

## Manual journal approval and journal reversal

### Approval threshold

A manual journal whose debit total is above the Director payment threshold (`approval.payment_threshold`, PKR 150,000.00) can be approved only by a Director with authority for its branch. At or below it, any user holding `journals.approve` for the branch may approve. The preparer and submitter can never approve, and the tier is taken from the validated debit total at approval time. Opening balance batches keep their own approval workflow.

### Posting a manual journal

Posting needs a third user: someone with `journals.post` and branch access who neither prepared (created, edited or submitted) nor approved the journal. No role is exempt. At posting the approval is checked again against today's policy, using the debit total recomputed from the lines: the recorded approver must still be active and eligible, with Director authority for the branch above the threshold. If the approval no longer qualifies, posting is refused; return the journal so it can be approved again. Journals posted by opening balances, payments, bills, payroll, depreciation, fee postings, year-end closing and the reversal workflow keep their own controls.

### Reversing a posted manual journal

Reversal is a three-step workflow; nothing is posted when it is requested.

| Step | Permission | Who may act |
|---|---|---|
| Request | `journals.reverse` | Any user with branch access |
| Approve | `journals.approve` (Director authority above the threshold) | Not the requester, not the preparer or submitter of the original journal |
| Post | `journals.post` | Not the requester, not the approver |
| Return | `journals.approve` or `journals.post` | Not the requester |
| Cancel | `journals.reverse` | The requester only |

- The reason needs at least 10 characters. The reversal date cannot be before the original journal date and must fall in an open fiscal year and period; a later open period may be used.
- Every rule is checked again on approval and on posting, including that the approver still holds the authority used.
- The original journal stays Posted until the reversal is posted. Only one pending or posted reversal can exist per journal; a returned or cancelled request is removed, so a new request is possible.
- Audit events: `journal.reversal_requested`, `journal.reversal_approved`, `journal.reversal_returned`, `journal.reversal_cancelled` and `journal.reversed`, recorded on the original journal with the amount, approval tier, branch, date, reason and journal ids.
- Opening balance and year-end closing journals cannot be reversed from the journal screen: neither has a correction workflow that keeps its source record in step. Journals posted by payments, bills, payroll, depreciation and fee postings are reversed through those documents. A reversing journal is never reversed.
