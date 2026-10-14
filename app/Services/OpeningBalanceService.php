<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    public function __construct(private AuditLogger $audit, private AppSettings $settings) {}

    /** @param array<string, mixed> $data */
    public function saveDraft(array $data, User $actor): OpeningBalanceBatch
    {
        $year = FiscalYear::query()->findOrFail($data['fiscal_year_id']);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $openingDate = (string) $data['opening_date'];
        if ($openingDate < $year->start_date->toDateString() || $openingDate > $year->end_date->toDateString()) {
            throw ValidationException::withMessages(['opening_date' => 'Opening date must fall inside the selected fiscal year.']);
        }
        $configuredDate = $this->settings->string('fiscal.initial_opening_date', '2026-01-01');
        if ($openingDate !== $configuredDate) {
            throw ValidationException::withMessages(['opening_date' => "Initial opening date is configured as {$configuredDate}."]);
        }

        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $prepared = [];
        foreach ($data['lines'] as $index => $line) {
            $lineDebit = BigDecimal::of((string) ($line['debit'] ?? '0'));
            $lineCredit = BigDecimal::of((string) ($line['credit'] ?? '0'));
            if ($lineDebit->isNegative() || $lineCredit->isNegative()) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Opening amounts cannot be negative.']);
            }
            if (! $lineDebit->isZero() && ! $lineCredit->isZero()) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Use either debit or credit for an account, not both.']);
            }
            if ($lineDebit->isZero() && $lineCredit->isZero()) {
                continue;
            }
            $account = Account::query()->findOrFail($line['account_id']);
            if (! $account->is_active || ! $account->is_postable || $account->level->value !== 'ledger') {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Only active, postable ledger accounts may receive an opening amount.']);
            }
            $debit = $debit->plus($lineDebit);
            $credit = $credit->plus($lineCredit);
            $prepared[] = ['account_id' => $account->id, 'debit' => (string) $lineDebit, 'credit' => (string) $lineCredit, 'source_reference' => $line['source_reference'] ?? null];
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['lines' => 'Enter at least one debit and one credit amount.']);
        }
        if (! $debit->isEqualTo($credit)) {
            throw ValidationException::withMessages(['lines' => 'Opening balances must be balanced. Debit: '.$debit.'; Credit: '.$credit.'.']);
        }

        return DB::transaction(function () use ($data, $actor, $year, $branch, $openingDate, $debit, $credit, $prepared): OpeningBalanceBatch {
            $batch = OpeningBalanceBatch::query()->lockForUpdate()->firstOrNew(['fiscal_year_id' => $year->id, 'branch_id' => $branch->id]);
            if ($batch->exists && ! in_array($batch->status, ['draft', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Approved or posted opening balances cannot be edited.']);
            }
            $batch->fill(['opening_date' => $openingDate, 'status' => 'draft', 'total_debit' => (string) $debit, 'total_credit' => (string) $credit,
                'difference' => '0.00', 'source_reference' => $data['source_reference'] ?? null, 'updated_by' => $actor->id]);
            $batch->created_by ??= $actor->id;
            $batch->save();
            $batch->lines()->delete();
            $batch->lines()->createMany($prepared);
            $this->audit->log('opening_balance.draft_saved', $batch, ['branch_id' => $branch->id, 'total_debit' => (string) $debit, 'journal_posting' => 'not_available_until_milestone_4']);

            return $batch->load(['lines.account', 'branch', 'fiscalYear']);
        });
    }
}
