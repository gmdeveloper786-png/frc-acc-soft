<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class JournalPostingService
{
    public function __construct(
        private BranchAccess $branchAccess,
        private JournalValidationService $validator,
        private VoucherNumberService $numbers,
        private AuditLogger $audit,
        private ManualJournalGuard $manualGuard,
    ) {}

    /**
     * Post a journal that a user prepared. The manual-journal guard always
     * applies here, whatever source labels the journal carries.
     */
    public function post(JournalEntry $journal, User $actor): JournalEntry
    {
        $this->manualGuard->assertAllowed($journal);

        return $this->commit($journal, $actor);
    }

    /**
     * Post a journal built entirely by a trusted server-side service (purchase
     * bill, payment, opening balance, reversal, depreciation, payroll). Never
     * call this with lines or source references taken from user input.
     */
    public function postSystem(JournalEntry $journal, User $actor): JournalEntry
    {
        if (blank($journal->source_type) || blank($journal->source_key)) {
            throw new LogicException('A system journal must carry a server-assigned source type and key.');
        }

        return $this->commit($journal, $actor);
    }

    private function commit(JournalEntry $journal, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.post'), 403);

        return DB::transaction(function () use ($journal, $actor): JournalEntry {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            abort_unless($this->branchAccess->canAccess($actor, $journal->branch), 403);
            if ($journal->status !== JournalStatus::Approved || $journal->voucher_number !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted journal can be posted.']);
            }

            $year = FiscalYear::query()->lockForUpdate()->findOrFail($journal->fiscal_year_id);
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($journal->accounting_period_id);
            if ($year->status !== FiscalYearStatus::Open || $period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['accounting_period_id' => 'The fiscal year and accounting period must both be open.']);
            }

            $journal->setRelation('fiscalYear', $year)->setRelation('accountingPeriod', $period);
            $totals = $this->validator->validate($journal);
            $voucherNumber = $this->numbers->next($journal->voucherType, $year);
            $journal->forceFill([
                ...$totals,
                'voucher_number' => $voucherNumber,
                'status' => JournalStatus::Posted,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();
            $this->audit->log('journal.posted', $journal, ['voucher_number' => $voucherNumber, ...$totals]);

            return $journal->refresh()->load(['lines.account', 'voucherType', 'branch']);
        }, 3);
    }
}
