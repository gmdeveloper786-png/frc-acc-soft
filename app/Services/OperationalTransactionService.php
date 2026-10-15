<?php

namespace App\Services;

use App\Enums\AccountMappingKey;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollRunStatus;
use App\Enums\PurchaseBillStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollRun;
use App\Models\PayrollRunPayment;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\VoucherType;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalTransactionService
{
    public function __construct(
        private BranchAccess $branchAccess,
        private JournalPostingService $posting,
        private JournalReversalService $reversal,
        private AppSettings $settings,
        private AuditLogger $audit,
        private AccountMappingService $mappings,
        private ApprovalAuthorityService $authority,
        private SplitPaymentDetector $splits,
        private ManualJournalGuard $guard,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): OperationalTransaction
    {
        $type = OperationalTransactionType::from($data['type']);
        abort_unless($actor->can($type->permissionPrefix().'.create'), 403);

        return DB::transaction(function () use ($data, $type, $actor): OperationalTransaction {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            $amount = AccountingDecimal::of($data['amount']);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
            }
            $data = $this->applyMappingDefaults($type, $data);
            $this->validateMappings($type, $data, $branch);
            if ($type === OperationalTransactionType::VendorPayment) {
                $this->guardVendorPayment($data, $amount);
            } else {
                // Only a vendor payment settles a purchase bill; any other type must not count against one.
                $data['purchase_bill_id'] = null;
            }

            $transaction = OperationalTransaction::query()->create([
                ...collect($data)->except('attachment')->all(),
                'amount' => AccountingDecimal::string($amount),
                'status' => OperationalTransactionStatus::Draft,
                'created_by' => $actor->id,
                'approval_tier' => $this->approvalTier($type, $amount, $data['settlement_type'] ?? null),
            ]);
            $this->audit->log($type->permissionPrefix().'.created', $transaction, ['type' => $type->value, 'branch_id' => $branch->id, 'amount' => AccountingDecimal::string($amount)]);
            if ($split = $this->splits->analyze($transaction)) {
                $this->audit->log($type->permissionPrefix().'.split_warning', $transaction, $split);
            }

            return $transaction;
        }, 3);
    }

    public function submit(OperationalTransaction $transaction, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        abort_unless($actor->can($prefix.'.submit'), 403);

        return DB::transaction(function () use ($transaction, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            if (! $transaction->type->requiresApproval() || ! in_array($transaction->status, [OperationalTransactionStatus::Draft, OperationalTransactionStatus::Rejected], true)) {
                throw ValidationException::withMessages(['status' => 'This transaction is not eligible for submission.']);
            }
            $this->guardObligation($transaction);
            $transaction->update(['status' => OperationalTransactionStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->audit->log($prefix.'.submitted', $transaction);

            return $transaction;
        }, 3);
    }

    public function approve(OperationalTransaction $transaction, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        if ($this->approvalBasis($transaction, $actor) === null) {
            $this->audit->log($prefix.'.approval_denied', $transaction, ['approval_tier' => $this->currentTier($transaction), 'amount' => $transaction->amount]);
            abort(403, 'You do not hold the approval authority this transaction requires.');
        }

        return DB::transaction(function () use ($transaction, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            $basis = $this->approvalBasis($transaction, $actor);
            abort_if($basis === null, 403);
            if ($transaction->status !== OperationalTransactionStatus::Submitted || in_array($actor->id, [$transaction->created_by, $transaction->submitted_by], true)) {
                throw ValidationException::withMessages(['approval' => 'A submitted transaction requires approval by a different authorized user.']);
            }
            $this->guardObligation($transaction);
            $transaction->update(['status' => OperationalTransactionStatus::Approved, 'approval_tier' => $this->currentTier($transaction), 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->log($prefix.'.approved', $transaction, ['approval_tier' => $transaction->approval_tier, 'authority_basis' => $basis, 'split_warning' => $this->splits->analyze($transaction)]);

            return $transaction;
        });
    }

    /**
     * The authority under which the user may approve this transaction, or
     * null. A payment above the configured threshold needs Director authority
     * by role or delegation; a permission alone is not enough.
     */
    public function approvalBasis(OperationalTransaction $transaction, User $actor): ?string
    {
        $prefix = $transaction->type->permissionPrefix();
        if (! $actor->is_active || ! $this->branchAccess->canAccess($actor, $transaction->branch)) {
            return null;
        }
        if ($this->currentTier($transaction) === 'director') {
            return $this->authority->directorBasis($actor, $transaction->branch, $transaction->amount, $this->highValuePermission($transaction->type), $prefix.'.approve');
        }

        return $actor->can($prefix.'.approve') ? 'standard' : null;
    }

    /** Whether the user may return this submitted transaction to its maker. */
    public function canReject(OperationalTransaction $transaction, User $actor): bool
    {
        $prefix = $transaction->type->permissionPrefix();

        return $actor->is_active
            && $transaction->status === OperationalTransactionStatus::Submitted
            && ! in_array($actor->id, [$transaction->created_by, $transaction->submitted_by], true)
            && ($actor->can($prefix.'.approve') || $actor->can($this->highValuePermission($transaction->type)))
            && $this->branchAccess->canAccess($actor, $transaction->branch);
    }

    /** Expenses and petty cash have no separate high-value permission. */
    private function highValuePermission(OperationalTransactionType $type): string
    {
        $prefix = $type->permissionPrefix();

        return in_array($type, [OperationalTransactionType::Expense, OperationalTransactionType::PettyCash], true) ? $prefix.'.approve' : $prefix.'.approve_high_value';
    }

    /** Whether the user may approve this submitted transaction right now. */
    public function canApprove(OperationalTransaction $transaction, User $actor): bool
    {
        return $transaction->status === OperationalTransactionStatus::Submitted
            && ! in_array($actor->id, [$transaction->created_by, $transaction->submitted_by], true)
            && $this->approvalBasis($transaction, $actor) !== null;
    }

    /** Approval level for the amount under the threshold configured right now. */
    private function currentTier(OperationalTransaction $transaction): ?string
    {
        return $this->approvalTier($transaction->type, AccountingDecimal::of($transaction->amount), $transaction->settlement_type);
    }

    public function reject(OperationalTransaction $transaction, string $reason, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        abort_unless($actor->is_active && ($actor->can($prefix.'.approve') || $actor->can($this->highValuePermission($transaction->type))), 403);

        return DB::transaction(function () use ($transaction, $reason, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            if ($transaction->status !== OperationalTransactionStatus::Submitted || in_array($actor->id, [$transaction->created_by, $transaction->submitted_by], true)) {
                throw ValidationException::withMessages(['status' => 'Sirf doosra authorized user submitted transaction reject kar sakta hai.']);
            }
            $transaction->update(['status' => OperationalTransactionStatus::Rejected, 'rejection_reason' => $reason]);
            $this->audit->log($prefix.'.rejected', $transaction, ['reason' => $reason]);

            return $transaction->refresh();
        });
    }

    public function disburse(OperationalTransaction $transaction, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        abort_unless($actor->can($prefix.'.disburse'), 403);

        return DB::transaction(function () use ($transaction, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            if (! $this->needsDisbursement($transaction) || $transaction->status !== OperationalTransactionStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Only an approved payment awaiting actual disbursement can be confirmed.']);
            }
            $this->guardObligation($transaction);
            $transaction->update(['status' => OperationalTransactionStatus::Disbursed, 'disbursed_by' => $actor->id, 'disbursed_at' => now()]);
            $this->audit->log($prefix.'.disbursed', $transaction);

            return $transaction;
        }, 3);
    }

    public function post(OperationalTransaction $transaction, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        abort_unless($actor->can($prefix.'.post'), 403);

        return DB::transaction(function () use ($transaction, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->with(['financialAccount.account', 'donationCategory', 'purchaseBill'])->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            if ($transaction->journal_entry_id !== null || $transaction->status === OperationalTransactionStatus::Posted) {
                throw ValidationException::withMessages(['status' => 'This transaction has already been posted.']);
            }
            $expected = $this->needsDisbursement($transaction) ? OperationalTransactionStatus::Disbursed : ($transaction->type->requiresApproval() ? OperationalTransactionStatus::Approved : OperationalTransactionStatus::Draft);
            if ($transaction->status !== $expected) {
                throw ValidationException::withMessages(['status' => 'The required workflow steps are incomplete.']);
            }
            $this->guardObligation($transaction);
            $journal = $this->buildJournal($transaction, $actor);
            $journal = $this->posting->postSystem($journal, $actor);
            $transaction->update(['status' => OperationalTransactionStatus::Posted, 'journal_entry_id' => $journal->id, 'posted_at' => now()]);
            $this->audit->log($prefix.'.posted', $transaction, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number]);

            return $transaction->refresh()->load('journalEntry');
        }, 3);
    }

    public function reverse(OperationalTransaction $transaction, AccountingPeriod $period, string $date, string $reason, User $actor): OperationalTransaction
    {
        $prefix = $transaction->type->permissionPrefix();
        abort_unless($actor->can($prefix.'.reverse'), 403);

        return DB::transaction(function () use ($transaction, $period, $date, $reason, $actor, $prefix): OperationalTransaction {
            $transaction = OperationalTransaction::query()->with(['branch', 'journalEntry'])->lockForUpdate()->findOrFail($transaction->id);
            $this->guardBranch($transaction, $actor);
            if ($transaction->status !== OperationalTransactionStatus::Posted || ! $transaction->journalEntry) {
                throw ValidationException::withMessages(['status' => 'Only a posted transaction can be reversed.']);
            }
            $this->reversal->reverse($transaction->journalEntry, $period, $date, $reason, $actor);
            $transaction->update(['status' => OperationalTransactionStatus::Reversed]);
            $this->audit->log($prefix.'.reversed', $transaction, ['reason' => $reason]);

            return $transaction->refresh();
        }, 3);
    }

    private function buildJournal(OperationalTransaction $transaction, User $actor): JournalEntry
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->where('status', 'open')->whereDate('start_date', '<=', $transaction->transaction_date)->whereDate('end_date', '>=', $transaction->transaction_date)->first();
        if (! $period || $period->fiscalYear->status->value !== 'open') {
            throw ValidationException::withMessages(['transaction_date' => 'No open accounting period exists for this date.']);
        }
        $financial = $transaction->financialAccount;
        if ($financial && (! $financial->is_active || $financial->branch_id !== $transaction->branch_id)) {
            throw ValidationException::withMessages(['financial_account_id' => 'The selected cash or bank account is unavailable for this branch.']);
        }
        if ($transaction->type === OperationalTransactionType::Donation && (! $transaction->donationCategory?->is_active || ! $transaction->donationCategory?->income_account_id)) {
            throw ValidationException::withMessages(['donation_category_id' => 'Active donation category ki approved income account mapping required hai.']);
        }
        $debitAccount = null;
        $creditAccount = null;
        if (in_array($transaction->type, [OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt, OperationalTransactionType::Donation], true)) {
            $debitAccount = $financial?->account_id;
            $creditAccount = $transaction->type === OperationalTransactionType::Donation ? $transaction->donationCategory?->income_account_id : $transaction->contra_account_id;
        } elseif ($transaction->type === OperationalTransactionType::Expense && $transaction->settlement_type === 'accrued') {
            $debitAccount = $transaction->contra_account_id;
            $creditAccount = $transaction->payable_account_id;
        } else {
            $debitAccount = $transaction->type === OperationalTransactionType::VendorPayment ? $transaction->purchaseBill?->payable_account_id : $transaction->contra_account_id;
            $creditAccount = $financial?->account_id;
        }
        if (! $debitAccount || ! $creditAccount || $debitAccount === $creditAccount) {
            throw ValidationException::withMessages(['mapping' => 'Complete, distinct debit and credit account mappings are required before posting.']);
        }
        $voucherCode = $transaction->type === OperationalTransactionType::VendorPayment
            ? ($financial?->type === 'cash' ? 'CP' : 'BP') : $transaction->type->voucherCode();
        $voucher = VoucherType::query()->where('code', $voucherCode)->where('is_active', true)->firstOrFail();
        $journal = JournalEntry::query()->create([
            'voucher_type_id' => $voucher->id, 'transaction_date' => $transaction->transaction_date,
            'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
            'branch_id' => $transaction->branch_id, 'narration' => $transaction->narration,
            'reference_number' => $transaction->reference_number, 'source_system' => $transaction->source_system,
            'source_type' => $transaction->type->value, 'source_id' => (string) $transaction->id,
            'source_key' => 'operational_transaction:'.$transaction->id, 'status' => JournalStatus::Approved,
            'total_debit' => $transaction->amount, 'total_credit' => $transaction->amount,
            'created_by' => $transaction->created_by, 'submitted_by' => $transaction->submitted_by ?: $transaction->created_by,
            'approved_by' => $transaction->approved_by ?: $actor->id, 'submitted_at' => $transaction->submitted_at ?: now(),
            'approved_at' => $transaction->approved_at ?: now(),
        ]);
        foreach ([[$debitAccount, $transaction->amount, '0.0000'], [$creditAccount, '0.0000', $transaction->amount]] as $index => [$accountId, $debit, $credit]) {
            $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $accountId, 'service_id' => $transaction->service_id, 'description' => $transaction->narration, 'debit' => $debit, 'credit' => $credit]);
        }

        return $journal;
    }

    /**
     * Fill ledgers the user left blank from the GL mapping configuration. A
     * missing required mapping stops here with a configuration error.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyMappingDefaults(OperationalTransactionType $type, array $data): array
    {
        $isReceipt = in_array($type, [OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt], true);
        $needsContra = ! in_array($type, [OperationalTransactionType::Donation, OperationalTransactionType::VendorPayment], true);
        if ($needsContra && blank($data['contra_account_id'] ?? null)) {
            $data['contra_account_id'] = $isReceipt
                ? $this->mappings->feeIncomeFor(filled($data['service_id'] ?? null) ? (int) $data['service_id'] : null)->id
                : $this->mappings->resolve(AccountMappingKey::DefaultExpense)->id;
        }
        if ($type === OperationalTransactionType::Expense && ($data['settlement_type'] ?? null) === 'accrued' && blank($data['payable_account_id'] ?? null)) {
            $data['payable_account_id'] = $this->mappings->resolve(AccountMappingKey::AccountsPayable)->id;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateMappings(OperationalTransactionType $type, array $data, Branch $branch): void
    {
        $financial = filled($data['financial_account_id'] ?? null) ? FinancialAccount::query()->with('account')->findOrFail($data['financial_account_id']) : null;
        if ($financial && ($financial->branch_id !== $branch->id || ! $financial->is_active)) {
            throw ValidationException::withMessages(['financial_account_id' => 'The financial account must be active and belong to the selected branch.']);
        }
        if ($financial && (! $financial->account || $financial->account->account_type->value !== 'asset' || ! $financial->account->is_postable || ! $financial->account->is_active)) {
            throw ValidationException::withMessages(['financial_account_id' => 'Cash/bank account ko active postable asset ledger se linked hona chahiye.']);
        }
        if ($financial) {
            $expected = in_array($type, [OperationalTransactionType::CashReceipt, OperationalTransactionType::CashPayment, OperationalTransactionType::PettyCash], true) ? 'cash' : (in_array($type, [OperationalTransactionType::BankReceipt, OperationalTransactionType::BankPayment], true) ? 'bank' : null);
            if ($expected && $financial->type !== $expected) {
                throw ValidationException::withMessages(['financial_account_id' => "A {$expected} account is required."]);
            }
        }
        $controlled = array_map('intval', $this->guard->controlledAccountIds());
        foreach (['contra_account_id', 'payable_account_id'] as $field) {
            if (filled($data[$field] ?? null) && ! Account::query()->whereKey($data[$field])->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$field => 'Only an active postable ledger account may be selected.']);
            }
            if (filled($data[$field] ?? null) && in_array((int) $data[$field], $controlled, true)) {
                throw ValidationException::withMessages([$field => 'A cash, bank or petty cash ledger cannot be the other side of this transaction. Record cash-bank transfers as a CV (contra) journal, which needs maker-checker approval.']);
            }
        }
        if (filled($data['payable_account_id'] ?? null) && ! Account::query()->whereKey($data['payable_account_id'])->where('account_type', 'liability')->where('normal_balance', 'credit')->exists()) {
            throw ValidationException::withMessages(['payable_account_id' => 'Accounts payable mapping credit-balance liability ledger honi chahiye.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function guardVendorPayment(array $data, BigDecimal $amount): void
    {
        $bill = PurchaseBill::query()->lockForUpdate()->findOrFail($data['purchase_bill_id']);
        if ($bill->status->value !== 'posted' || $bill->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages(['purchase_bill_id' => 'Only a posted bill from the selected branch can be paid.']);
        }
        // A locking read: a plain read could miss payments committed while this transaction waited for the bill.
        $reserved = $bill->payments()->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value])->lockForUpdate()->get(['id', 'amount'])
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)), BigDecimal::zero()->toScale(4));
        if ($reserved->plus($amount)->isGreaterThan(AccountingDecimal::of($bill->total_amount))) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the bill outstanding amount.']);
        }
    }

    /**
     * Re-check the obligation a payment settles before it moves forward. A
     * rejected payment stops reserving its purchase bill or payroll run, so a
     * resubmitted one must still fit, and the obligation must still be posted.
     */
    private function guardObligation(OperationalTransaction $transaction): void
    {
        $live = fn ($query) => $query->whereKeyNot($transaction->id)->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value]);
        $sum = fn ($payments): BigDecimal => $payments->reduce(fn (BigDecimal $carry, OperationalTransaction $payment): BigDecimal => $carry->plus(AccountingDecimal::of($payment->amount)), AccountingDecimal::of($transaction->amount));

        if ($transaction->type === OperationalTransactionType::VendorPayment) {
            $bill = PurchaseBill::query()->lockForUpdate()->find($transaction->purchase_bill_id);
            if (! $bill || $bill->status !== PurchaseBillStatus::Posted) {
                throw ValidationException::withMessages(['purchase_bill_id' => 'The purchase bill of this payment is no longer posted, so it cannot be paid.']);
            }
            if ($sum($live($bill->payments())->lockForUpdate()->get(['id', 'amount']))->isGreaterThan(AccountingDecimal::of($bill->total_amount))) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the bill outstanding amount.']);
            }
        }

        $link = PayrollRunPayment::query()->where('operational_transaction_id', $transaction->id)->first();
        if ($link) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($link->payroll_run_id);
            if (! in_array($run->status, [PayrollRunStatus::Posted, PayrollRunStatus::Paid], true)) {
                throw ValidationException::withMessages(['status' => 'The payroll run of this salary payment is no longer posted, so it cannot be paid.']);
            }
            $linked = PayrollRunPayment::query()->where('payroll_run_id', $run->id)->lockForUpdate()->pluck('operational_transaction_id');
            $others = OperationalTransaction::query()->whereIn('id', $linked);
            if ($sum($live($others)->lockForUpdate()->get(['id', 'amount']))->isGreaterThan(AccountingDecimal::of($run->net_amount))) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the unpaid net salary of its payroll run.']);
            }
        }
    }

    /**
     * Every workflow that pays money out of cash or bank is subject to the
     * payment threshold: payments, vendor payments, petty cash and expenses
     * settled as paid. An accrued expense only records a payable.
     */
    private function approvalTier(OperationalTransactionType $type, BigDecimal $amount, ?string $settlement = null): ?string
    {
        $paysOut = in_array($type, [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment, OperationalTransactionType::VendorPayment, OperationalTransactionType::PettyCash], true)
            || ($type === OperationalTransactionType::Expense && $settlement === 'paid');
        if (! $paysOut) {
            return $type->requiresApproval() ? 'standard' : null;
        }
        $threshold = AccountingDecimal::of($this->settings->string('approval.payment_threshold', '150000.00'));

        return $amount->isGreaterThan($threshold) ? 'director' : 'standard';
    }

    private function needsDisbursement(OperationalTransaction $transaction): bool
    {
        return $transaction->type->requiresDisbursement() || ($transaction->type === OperationalTransactionType::Expense && $transaction->settlement_type === 'paid');
    }

    private function guardBranch(OperationalTransaction $transaction, User $actor): void
    {
        abort_unless($this->branchAccess->canAccess($actor, $transaction->branch), 403);
    }
}
