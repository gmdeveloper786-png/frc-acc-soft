<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalReversalService
{
    /** System journals whose source has no reversal workflow of its own. */
    private const DIRECTLY_REVERSIBLE_SOURCES = ['opening_balance'];

    public function __construct(private BranchAccess $branchAccess, private JournalPostingService $posting, private AuditLogger $audit) {}

    /**
     * Reverse a journal from the journal screen. A journal posted by a source
     * document (payment, purchase bill, payroll or depreciation run) must be
     * reversed through that document so its status and balances stay in step,
     * and a reversing journal is never reversed again.
     */
    public function reverseDirect(JournalEntry $original, AccountingPeriod $period, string $date, string $reason, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.reverse'), 403);
        abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
        if (filled($original->source_type) && ! in_array($original->source_type, self::DIRECTLY_REVERSIBLE_SOURCES, true)) {
            throw ValidationException::withMessages(['status' => $original->source_type === 'journal_reversal'
                ? 'A reversing journal cannot be reversed. Record the transaction again through its own workflow.'
                : 'This journal was posted by a source document. Reverse that document through its own workflow instead.']);
        }

        return $this->reverse($original, $period, $date, $reason, $actor);
    }

    public function reverse(JournalEntry $original, AccountingPeriod $period, string $date, string $reason, User $actor): JournalEntry
    {
        abort_unless($actor->can('journals.reverse'), 403);

        return DB::transaction(function () use ($original, $period, $date, $reason, $actor): JournalEntry {
            $original = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($original->id);
            abort_unless($this->branchAccess->canAccess($actor, $original->branch), 403);
            if ($original->status !== JournalStatus::Posted || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['status' => 'This journal is not eligible for another reversal.']);
            }
            $reversal = JournalEntry::query()->create([
                'voucher_type_id' => $original->voucher_type_id,
                'transaction_date' => $date,
                'fiscal_year_id' => $period->fiscal_year_id,
                'accounting_period_id' => $period->id,
                'branch_id' => $original->branch_id,
                'narration' => 'Reversal of '.$original->voucher_number.': '.$reason,
                'reference_number' => $original->voucher_number,
                'source_system' => 'accounting',
                'source_type' => 'journal_reversal',
                'source_id' => (string) $original->id,
                'source_key' => 'journal_reversal:'.$original->id,
                'status' => JournalStatus::Approved,
                'total_debit' => $original->total_credit,
                'total_credit' => $original->total_debit,
                'created_by' => $actor->id,
                'submitted_by' => $actor->id,
                'approved_by' => $actor->id,
                'submitted_at' => now(),
                'approved_at' => now(),
                'reversal_reason' => $reason,
                'reversal_of_id' => $original->id,
            ]);
            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'line_number' => $line->line_number,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'service_id' => $line->service_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                ]);
            }

            $posted = $this->posting->postSystem($reversal, $actor);
            $original->update(['status' => JournalStatus::Reversed, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->audit->log('journal.reversed', $original, ['reversal_journal_id' => $posted->id, 'reason' => $reason]);

            return $posted;
        }, 3);
    }
}
