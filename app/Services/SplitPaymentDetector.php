<?php

namespace App\Services;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Models\OperationalTransaction;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;

/**
 * Detects several outflows that each stay at or below the payment threshold
 * but together exceed it for one obligation. It only warns: no aggregate
 * approval limit is enforced until the client approves such a policy.
 */
class SplitPaymentDetector
{
    private const OUTFLOW_TYPES = [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment, OperationalTransactionType::VendorPayment, OperationalTransactionType::PettyCash, OperationalTransactionType::Expense];

    public function __construct(private AppSettings $settings) {}

    /**
     * @return array{basis: string, count: int, aggregate: string, threshold: string, transaction_ids: list<int>}|null
     */
    public function analyze(OperationalTransaction $transaction): ?array
    {
        if (! in_array($transaction->type, self::OUTFLOW_TYPES, true) || ($transaction->type === OperationalTransactionType::Expense && $transaction->settlement_type !== 'paid')) {
            return null;
        }
        $days = $this->settings->integer('approval.split_detection_days', 7);
        $query = OperationalTransaction::query()
            ->whereIn('type', array_map(fn (OperationalTransactionType $type): string => $type->value, self::OUTFLOW_TYPES))
            ->where(fn ($query) => $query->where('type', '!=', OperationalTransactionType::Expense->value)->orWhere('settlement_type', 'paid'))
            ->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value]);

        if ($transaction->purchase_bill_id) {
            $basis = 'purchase_bill:'.$transaction->purchase_bill_id;
            $query->where('purchase_bill_id', $transaction->purchase_bill_id);
        } elseif ($days > 0 && ($transaction->vendor_id || filled($transaction->party_name))) {
            $basis = $transaction->vendor_id ? 'vendor:'.$transaction->vendor_id : 'party:'.mb_strtolower(trim((string) $transaction->party_name));
            $query->where('branch_id', $transaction->branch_id)->whereNull('purchase_bill_id')
                ->whereDate('transaction_date', '>=', $transaction->transaction_date->copy()->subDays($days))
                ->whereDate('transaction_date', '<=', $transaction->transaction_date->copy()->addDays($days));
            $transaction->vendor_id
                ? $query->where('vendor_id', $transaction->vendor_id)
                : $query->whereNull('vendor_id')->whereRaw('LOWER(TRIM(party_name)) = ?', [mb_strtolower(trim((string) $transaction->party_name))]);
        } else {
            return null;
        }

        $related = $query->orderBy('id')->get(['id', 'amount']);
        $threshold = AccountingDecimal::of($this->settings->string('approval.payment_threshold', (string) config('accounting.approvals.payment_threshold')));
        $aggregate = $related->reduce(fn (BigDecimal $sum, OperationalTransaction $item): BigDecimal => $sum->plus(AccountingDecimal::of($item->amount)), BigDecimal::zero()->toScale(4));
        $eachWithin = $related->every(fn (OperationalTransaction $item): bool => ! AccountingDecimal::of($item->amount)->isGreaterThan($threshold));
        if ($related->count() < 2 || ! $eachWithin || ! $aggregate->isGreaterThan($threshold)) {
            return null;
        }

        return ['basis' => $basis, 'count' => $related->count(), 'aggregate' => AccountingDecimal::string($aggregate), 'threshold' => AccountingDecimal::string($threshold), 'transaction_ids' => $related->pluck('id')->all()];
    }
}
