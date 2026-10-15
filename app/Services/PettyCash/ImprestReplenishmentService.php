<?php

namespace App\Services\PettyCash;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\JournalWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prepares the contra (CV) journal that restores an imprest float: debit the
 * petty cash ledger, credit the replenishment source. The journal is only a
 * draft; submission, approval and posting stay with the journal workflow, so
 * maker-checker applies and nothing is ever posted from here.
 */
class ImprestReplenishmentService
{
    public const VOUCHER_CODE = 'CV';

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
        private ImprestLedgerService $ledger,
        private ImprestFloatService $floats,
        private JournalWorkflowService $journals,
    ) {}

    /** @param array{transaction_date: string, amount?: string|null} $data */
    public function request(ImprestFloat $float, array $data, User $actor): ImprestReplenishment
    {
        abort_unless($actor->can('imprest.replenish'), 403);
        abort_unless($actor->can('journals.create'), 403, 'Preparing a replenishment journal also needs permission to create journals.');

        return DB::transaction(function () use ($float, $data, $actor): ImprestReplenishment {
            // Locking the float serialises replenishment requests for it.
            $float = ImprestFloat::query()->with(['branch', 'account', 'sourceAccount.account'])->lockForUpdate()->findOrFail($float->id);
            abort_unless($this->branchAccess->canAccess($actor, $float->branch), 403);
            if (! $float->is_active) {
                throw ValidationException::withMessages(['float' => 'This imprest float is inactive.']);
            }
            $this->ledger->syncStatuses($float);
            if ($open = $this->ledger->openReplenishment($float)) {
                throw ValidationException::withMessages(['float' => "Replenishment #{$open->id} for this float is still open ({$open->status}). It must be posted, or its draft discarded, before another can be prepared."]);
            }

            $due = $this->ledger->due($float, $this->ledger->balance($float));
            if (! $due->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'No replenishment is due: the petty cash balance already equals or exceeds the float.']);
            }
            $amount = filled($data['amount'] ?? null) ? AccountingDecimal::of($data['amount']) : $due;
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'The replenishment amount must be greater than zero.']);
            }
            if ($amount->isGreaterThan($due)) {
                throw ValidationException::withMessages(['amount' => 'The replenishment amount cannot exceed the replenishment due of '.AccountingDecimal::string($due).'.']);
            }

            $source = $this->floats->candidateSources($float->branch_id)->firstWhere('id', $float->source_financial_account_id);
            if (! $source || $source->account_id === $float->account_id) {
                throw ValidationException::withMessages(['float' => 'The replenishment source of this float is no longer an active bank or cash account of the branch. Update the float first.']);
            }
            $period = $this->openPeriod($data['transaction_date']);
            $voucherType = VoucherType::query()->where('code', self::VOUCHER_CODE)->where('is_active', true)->first();
            if (! $voucherType) {
                throw ValidationException::withMessages(['transaction_date' => 'The CV (contra) voucher type is not active.']);
            }

            $vouchers = $this->ledger->uncoveredVouchers($float);
            $amountText = AccountingDecimal::string($amount);
            $journal = $this->journals->create([
                'voucher_type_id' => $voucherType->id, 'transaction_date' => $data['transaction_date'],
                'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
                'branch_id' => $float->branch_id, 'reference_number' => 'IMPREST-'.$float->id,
                'narration' => $this->narration($float, $vouchers->all(), $amountText),
                'lines' => [
                    ['account_id' => $float->account_id, 'debit' => $amountText, 'credit' => '0', 'description' => 'Petty cash imprest replenishment'],
                    ['account_id' => $source->account_id, 'debit' => '0', 'credit' => $amountText, 'description' => 'Transfer to petty cash: '.$source->name],
                ],
            ], $actor);

            $replenishment = ImprestReplenishment::query()->create([
                'imprest_float_id' => $float->id, 'journal_entry_id' => $journal->id, 'amount' => $amountText,
                'due_at_request' => AccountingDecimal::string($due), 'covered_voucher_ids' => $vouchers->pluck('id')->values()->all(),
                'covered_from' => $vouchers->min('transaction_date'), 'covered_to' => $vouchers->max('transaction_date'),
                'status' => $journal->status->value, 'created_by' => $actor->id,
            ]);
            $this->audit->log('imprest.replenishment_requested', $replenishment, [
                'branch_id' => $float->branch_id, 'imprest_float_id' => $float->id, 'journal_entry_id' => $journal->id,
                'amount' => $amountText, 'due' => AccountingDecimal::string($due), 'vouchers' => $vouchers->count(),
            ], $actor);

            return $replenishment;
        });
    }

    private function openPeriod(string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
        if (! $period || $period->fiscalYear?->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['transaction_date' => 'No open accounting period exists for this date.']);
        }

        return $period;
    }

    /** @param list<OperationalTransaction> $vouchers */
    private function narration(ImprestFloat $float, array $vouchers, string $amount): string
    {
        $text = 'Petty cash imprest replenishment of '.$amount.' for '.$float->label().' (custodian: '.$float->custodian_name.').';
        if ($vouchers === []) {
            return $text.' No posted petty cash vouchers since the last replenishment.';
        }
        $references = array_map(fn (OperationalTransaction $voucher): string => $voucher->journalEntry?->voucher_number ?? 'PCV #'.$voucher->id, $vouchers);
        $dates = array_map(fn (OperationalTransaction $voucher): string => $voucher->transaction_date->toDateString(), $vouchers);
        $text .= ' Covers '.count($vouchers).' petty cash voucher(s) dated '.min($dates).' to '.max($dates).': ';

        return str($text.implode(', ', $references))->limit(1990)->toString();
    }
}
