<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Models\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalancePostingService
{
    public function __construct(
        private BranchAccess $branchAccess,
        private JournalPostingService $posting,
        private OpeningBalanceValidationService $validator,
        private AuditLogger $audit,
        private OpeningBalanceImportService $imports,
    ) {}

    public function approve(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->can('opening_balances.approve'), 403);

        return DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->with(['fiscalYear', 'lines.account'])->lockForUpdate()->findOrFail($batch->id);
            abort_unless($this->branchAccess->canAccess($actor, $batch->branch), 403);
            if ($batch->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft opening balance can be approved.']);
            }
            $this->validator->assertBatchIsValid($batch);
            if (in_array($actor->id, [$batch->created_by, $batch->updated_by, $this->imports->uploaderOf($batch)], true)) {
                throw ValidationException::withMessages(['status' => 'A separate authorized user must approve a draft opening balance.']);
            }
            $batch->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'rejection_reason' => null]);
            $this->audit->log('opening_balance.approved', $batch);

            return $batch;
        });
    }

    public function post(OpeningBalanceBatch $batch, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->can('opening_balances.post'), 403);

        return DB::transaction(function () use ($batch, $actor): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->with(['fiscalYear', 'lines.account'])->lockForUpdate()->findOrFail($batch->id);
            abort_unless($this->branchAccess->canAccess($actor, $batch->branch), 403);
            if ($batch->status !== 'approved' || $batch->journal_entry_id !== null) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted opening balance can be posted.']);
            }
            $this->validator->assertBatchIsValid($batch);
            $period = AccountingPeriod::query()
                ->where('fiscal_year_id', $batch->fiscal_year_id)
                ->whereDate('start_date', '<=', $batch->opening_date)
                ->whereDate('end_date', '>=', $batch->opening_date)
                ->firstOrFail();
            $type = VoucherType::query()->where('code', 'JV')->where('is_active', true)->firstOrFail();
            $journal = JournalEntry::query()->create([
                'voucher_type_id' => $type->id,
                'transaction_date' => $batch->opening_date,
                'fiscal_year_id' => $batch->fiscal_year_id,
                'accounting_period_id' => $period->id,
                'branch_id' => $batch->branch_id,
                'narration' => 'Opening balances for '.$batch->branch->name,
                'reference_number' => $batch->source_reference,
                'source_system' => 'accounting',
                'source_type' => 'opening_balance',
                'source_id' => (string) $batch->id,
                'source_key' => 'opening_balance:'.$batch->id,
                'status' => 'approved',
                'total_debit' => $batch->total_debit,
                'total_credit' => $batch->total_credit,
                'created_by' => $batch->created_by,
                'submitted_by' => $batch->created_by,
                'approved_by' => $batch->approved_by,
                'submitted_at' => $batch->approved_at,
                'approved_at' => $batch->approved_at,
            ]);
            foreach ($batch->lines as $index => $line) {
                $journal->lines()->create([
                    'line_number' => $index + 1,
                    'account_id' => $line->account_id,
                    'description' => $line->source_reference,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ]);
            }
            $journal = $this->posting->postSystem($journal, $actor);
            $batch->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now(), 'journal_entry_id' => $journal->id]);
            $this->audit->log('opening_balance.posted', $batch, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number]);

            return $batch->refresh();
        }, 3);
    }
}
