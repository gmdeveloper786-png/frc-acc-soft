<?php

namespace App\Services;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

class PayableObligationService
{
    public function __construct(private AuditLogger $audit) {}

    public function createForPostedExpense(OperationalTransaction $expense, JournalEntry $journal): PayableObligation
    {
        if ($expense->type !== OperationalTransactionType::Expense || $expense->settlement_type !== 'accrued' || $expense->status !== OperationalTransactionStatus::Posted || ! $expense->payable_account_id) {
            throw ValidationException::withMessages(['payable_obligation' => 'Only a posted accrued expense can create a payable obligation.']);
        }

        $obligation = PayableObligation::query()->firstOrCreate(
            ['source_expense_transaction_id' => $expense->id],
            [
                'source_journal_entry_id' => $journal->id,
                'branch_id' => $expense->branch_id,
                'payable_account_id' => $expense->payable_account_id,
                'vendor_id' => $expense->vendor_id,
                'original_amount' => $expense->amount,
                'obligation_date' => $expense->transaction_date,
                'source_reference' => $expense->reference_number,
            ],
        );

        if ($obligation->wasRecentlyCreated) {
            $this->audit->log('payable_obligations.created', $obligation, ['source_expense_transaction_id' => $expense->id, 'branch_id' => $expense->branch_id, 'amount' => $expense->amount]);
        }

        return $obligation;
    }

    /** @param array<string, mixed> $data */
    public function prepareSettlement(array $data, Branch $branch, BigDecimal $amount): array
    {
        if (blank($data['payable_obligation_id'] ?? null)) {
            return $data;
        }
        $type = OperationalTransactionType::from($data['type']);
        if (! in_array($type, [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment], true)) {
            throw ValidationException::withMessages(['payable_obligation_id' => 'An accrued payable can only be settled with a cash or bank payment.']);
        }

        $obligation = PayableObligation::query()->with('sourceExpense')->lockForUpdate()->findOrFail($data['payable_obligation_id']);
        $this->assertOpenSource($obligation, $branch);
        if ($amount->isGreaterThan(AccountingDecimal::of($obligation->outstandingAmount()))) {
            throw ValidationException::withMessages(['amount' => 'Settlement amount exceeds the payable outstanding amount.']);
        }

        return [...$data, 'branch_id' => $obligation->branch_id, 'contra_account_id' => $obligation->payable_account_id, 'purchase_bill_id' => null];
    }

    public function settlementSourceKey(array $data): ?string
    {
        if (blank($data['payable_obligation_id'] ?? null)) {
            return null;
        }
        $token = (string) ($data['settlement_request_token'] ?? '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token)) {
            throw ValidationException::withMessages(['settlement_request_token' => 'A valid settlement request token is required.']);
        }

        return 'payable-settlement:'.$data['payable_obligation_id'].':'.$token;
    }

    public function existingSettlement(string $sourceKey, array $data): ?OperationalTransaction
    {
        $existing = OperationalTransaction::query()->where('source_key', $sourceKey)->lockForUpdate()->first();
        if (! $existing) {
            return null;
        }
        if ($existing->type->value !== $data['type'] || $existing->branch_id !== (int) $data['branch_id'] || AccountingDecimal::of($existing->amount)->isEqualTo(AccountingDecimal::of($data['amount'])) === false) {
            throw ValidationException::withMessages(['settlement_request_token' => 'This settlement request token was already used with different data.']);
        }

        return $existing;
    }

    public function lockSettlement(?int $obligationId): ?PayableObligation
    {
        return $obligationId
            ? PayableObligation::query()->with('sourceExpense')->lockForUpdate()->findOrFail($obligationId)
            : null;
    }

    public function lockSourceExpense(int $expenseId): ?PayableObligation
    {
        return PayableObligation::query()->with('sourceExpense')->where('source_expense_transaction_id', $expenseId)->lockForUpdate()->first();
    }

    public function hasActiveSettlementsForSource(OperationalTransaction $expense): bool
    {
        return $expense->type === OperationalTransactionType::Expense
            && $expense->settlement_type === 'accrued'
            && PayableObligation::query()->where('source_expense_transaction_id', $expense->id)
                ->whereHas('settlements', fn ($query) => $query->whereIn('status', $this->activeSettlementStatuses()))
                ->exists();
    }

    public function guardSettlement(OperationalTransaction $payment, ?PayableObligation $lockedObligation = null): void
    {
        if (! $payment->payable_obligation_id) {
            return;
        }
        $obligation = $lockedObligation ?? $this->lockSettlement($payment->payable_obligation_id);
        if (! $obligation || $obligation->id !== $payment->payable_obligation_id) {
            throw ValidationException::withMessages(['payable_obligation_id' => 'The settlement no longer matches its accrued payable obligation.']);
        }
        $this->assertOpenSource($obligation, $payment->branch);
        if (! in_array($payment->type, [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment], true) || $payment->contra_account_id !== $obligation->payable_account_id) {
            throw ValidationException::withMessages(['payable_obligation_id' => 'The settlement no longer matches its accrued payable obligation.']);
        }

        $settlements = $obligation->settlements()->lockForUpdate()->get(['id', 'status', 'amount']);
        $posted = $settlements->filter(fn (OperationalTransaction $item): bool => $item->status === OperationalTransactionStatus::Posted)
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $item): BigDecimal => $sum->plus(AccountingDecimal::of($item->amount)), BigDecimal::zero()->toScale(4));
        $reserved = $settlements->filter(fn (OperationalTransaction $item): bool => in_array($item->status, [OperationalTransactionStatus::Submitted, OperationalTransactionStatus::Approved, OperationalTransactionStatus::Disbursed], true))
            ->reject(fn (OperationalTransaction $item): bool => $item->id === $payment->id)
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $item): BigDecimal => $sum->plus(AccountingDecimal::of($item->amount)), BigDecimal::zero()->toScale(4));
        if ($posted->plus($reserved)->plus(AccountingDecimal::of($payment->amount))->isGreaterThan(AccountingDecimal::of($obligation->original_amount))) {
            throw ValidationException::withMessages(['amount' => 'Settlement amount exceeds the payable amount after active payment reservations.']);
        }
    }

    public function assertSourceCanReverse(OperationalTransaction $expense, ?PayableObligation $lockedObligation = null): void
    {
        $obligation = $lockedObligation ?? $this->lockSourceExpense($expense->id);
        if (! $obligation) {
            return;
        }
        $hasActiveSettlement = $obligation->settlements()->lockForUpdate()->whereIn('status', $this->activeSettlementStatuses())->exists();
        if ($hasActiveSettlement) {
            throw ValidationException::withMessages(['status' => 'Reverse linked payable settlements before reversing the accrued expense.']);
        }
    }

    /** @return list<string> */
    private function activeSettlementStatuses(): array
    {
        return [
            OperationalTransactionStatus::Submitted->value,
            OperationalTransactionStatus::Approved->value,
            OperationalTransactionStatus::Disbursed->value,
            OperationalTransactionStatus::Posted->value,
        ];
    }

    private function assertOpenSource(PayableObligation $obligation, Branch $branch): void
    {
        $expense = $obligation->sourceExpense;
        if ($obligation->branch_id !== $branch->id || ! $expense || $expense->type !== OperationalTransactionType::Expense || $expense->settlement_type !== 'accrued' || $expense->status !== OperationalTransactionStatus::Posted || $expense->payable_account_id !== $obligation->payable_account_id) {
            throw ValidationException::withMessages(['payable_obligation_id' => 'This accrued payable is no longer available for settlement.']);
        }
    }
}
