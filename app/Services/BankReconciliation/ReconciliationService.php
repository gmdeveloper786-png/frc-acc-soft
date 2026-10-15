<?php

namespace App\Services\BankReconciliation;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation workflow: draft → submitted → approved, with rejection
 * back to draft. Matches change only while the reconciliation is a draft; an
 * approved reconciliation is locked and its figures are frozen. The preparer,
 * the submitter and everyone who changed a match are makers and cannot approve.
 */
class ReconciliationService
{
    /** Audit events that make a user a maker of the reconciliation. */
    public const MAKER_EVENTS = [
        'bank_reconciliation.created', 'bank_reconciliation.matched', 'bank_reconciliation.auto_matched', 'bank_reconciliation.unmatched',
        'bank_reconciliation.brought_forward', 'bank_reconciliation.line_ignored', 'bank_reconciliation.line_restored', 'bank_reconciliation.submitted',
    ];

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
        private BankBook $book,
        private ReconciliationCalculator $calculator,
    ) {}

    /** @param array{statement_date?: string|null, statement_closing_balance?: string|null, bank_statement_id?: int|string|null} $data */
    public function create(FinancialAccount $account, array $data, User $actor): BankReconciliation
    {
        $this->authorizeAccount($account, $actor, 'bank_reconciliation.prepare');
        $statement = null;
        if (filled($data['bank_statement_id'] ?? null)) {
            $statement = BankStatement::query()->where('financial_account_id', $account->id)->where('status', BankStatement::IMPORTED)->find($data['bank_statement_id']);
            if (! $statement) {
                throw ValidationException::withMessages(['bank_statement_id' => 'Select an imported statement of this bank account.']);
            }
        }
        $date = $statement ? CarbonImmutable::parse($statement->period_to) : CarbonImmutable::parse((string) $data['statement_date']);
        $balance = $statement ? AccountingDecimal::of($statement->closing_balance) : AccountingDecimal::of(Amount::parse((string) $data['statement_closing_balance']));

        try {
            return DB::transaction(function () use ($account, $actor, $statement, $date, $balance): BankReconciliation {
                FinancialAccount::query()->lockForUpdate()->findOrFail($account->id);
                $this->assertAfterLatestApproved($account, $date);
                if (BankReconciliation::query()->where('financial_account_id', $account->id)->whereNotNull('open_key')->exists()) {
                    throw ValidationException::withMessages(['financial_account_id' => 'This bank account already has a reconciliation in progress. Finish or continue it first.']);
                }
                $reconciliation = BankReconciliation::query()->create([
                    'financial_account_id' => $account->id, 'branch_id' => $account->branch_id, 'bank_statement_id' => $statement?->id,
                    'statement_date' => $date->toDateString(), 'statement_closing_balance' => AccountingDecimal::string($balance),
                    'status' => BankReconciliation::DRAFT, 'open_key' => '1', 'prepared_by' => $actor->id,
                ]);
                $this->audit->log('bank_reconciliation.created', $reconciliation, [
                    'financial_account_id' => $account->id, 'statement_date' => $date->toDateString(), 'statement_closing_balance' => AccountingDecimal::string($balance),
                ], $actor);

                return $reconciliation;
            }, 3);
        } catch (QueryException) {
            throw ValidationException::withMessages(['financial_account_id' => 'This bank account already has a reconciliation in progress. Finish or continue it first.']);
        }
    }

    public function match(BankReconciliation $reconciliation, int $statementLineId, int $journalLineId, User $actor, string $type = BankReconciliationMatch::MANUAL): BankReconciliationMatch
    {
        return $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($statementLineId, $journalLineId, $actor, $type): BankReconciliationMatch {
            $match = $this->createMatch($reconciliation, $statementLineId, $journalLineId, $actor, $type);
            $this->audit->log('bank_reconciliation.matched', $reconciliation, [
                'statement_line_id' => $statementLineId, 'journal_line_id' => $journalLineId, 'amount' => $match->statementLine->amount, 'match_type' => $type,
            ], $actor);

            return $match;
        });
    }

    /**
     * Apply the current auto-match suggestions. When pairs are given, only the
     * suggestions among them are applied; suggestions are always recomputed here.
     *
     * @param  list<string>|null  $pairs  "statementLineId:journalLineId" keys chosen on screen.
     */
    public function autoMatch(BankReconciliation $reconciliation, bool $requireReference, ?array $pairs, User $actor): int
    {
        return $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($requireReference, $pairs, $actor): int {
            $applied = 0;
            foreach ($this->calculator->suggestions($reconciliation, $requireReference) as $suggestion) {
                $key = $suggestion['statement_line']->id.':'.$suggestion['journal_line']->id;
                if ($pairs !== null && ! in_array($key, $pairs, true)) {
                    continue;
                }
                $this->createMatch($reconciliation, $suggestion['statement_line']->id, $suggestion['journal_line']->id, $actor, BankReconciliationMatch::AUTO);
                $applied++;
            }
            if ($applied > 0) {
                $this->audit->log('bank_reconciliation.auto_matched', $reconciliation, ['matches' => $applied, 'same_reference_required' => $requireReference], $actor);
            }

            return $applied;
        });
    }

    /**
     * Clear a book line dated before the first imported statement of the account,
     * e.g. the opening balance, which no imported statement line can ever match.
     */
    public function clearBroughtForward(BankReconciliation $reconciliation, int $journalLineId, string $note, User $actor): BankReconciliationMatch
    {
        return $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($journalLineId, $note, $actor): BankReconciliationMatch {
            $line = $this->bookLine($reconciliation, $journalLineId);
            $firstStatement = BankStatement::query()->where('financial_account_id', $reconciliation->financial_account_id)->where('status', BankStatement::IMPORTED)->min('period_from');
            if (! $firstStatement || $line->journalEntry->transaction_date->toDateString() >= CarbonImmutable::parse($firstStatement)->toDateString()) {
                throw ValidationException::withMessages(['journal_line_id' => 'Only book entries dated before the first imported statement can be cleared as brought forward.']);
            }
            $match = $this->insertMatch(['bank_reconciliation_id' => $reconciliation->id, 'bank_statement_line_id' => null, 'journal_line_id' => $line->id, 'match_type' => BankReconciliationMatch::BROUGHT_FORWARD, 'note' => $note, 'matched_by' => $actor->id]);
            $this->audit->log('bank_reconciliation.brought_forward', $reconciliation, ['journal_line_id' => $line->id, 'amount' => AccountingDecimal::string(Amount::journalLine($line->debit, $line->credit)), 'note' => $note], $actor);

            return $match;
        });
    }

    public function unmatch(BankReconciliation $reconciliation, BankReconciliationMatch $match, User $actor): void
    {
        $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($match, $actor): void {
            $match = BankReconciliationMatch::query()->lockForUpdate()->findOrFail($match->id);
            if ($match->bank_reconciliation_id !== $reconciliation->id) {
                abort(404);
            }
            if ($match->bank_statement_line_id) {
                BankStatementLine::query()->whereKey($match->bank_statement_line_id)->update(['status' => BankStatementLine::UNMATCHED]);
            }
            $this->audit->log('bank_reconciliation.unmatched', $reconciliation, ['statement_line_id' => $match->bank_statement_line_id, 'journal_line_id' => $match->journal_line_id, 'match_type' => $match->match_type], $actor);
            $match->delete();
        });
    }

    /** Set aside a statement line that is not a transaction of this account (e.g. a bank error later corrected). */
    public function ignore(BankReconciliation $reconciliation, BankStatementLine $line, User $actor): void
    {
        $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($line, $actor): void {
            $line = $this->statementLine($reconciliation, $line->id);
            $line->update(['status' => BankStatementLine::IGNORED, 'ignored_in_reconciliation_id' => $reconciliation->id]);
            $this->audit->log('bank_reconciliation.line_ignored', $reconciliation, ['statement_line_id' => $line->id, 'amount' => $line->amount], $actor);
        });
    }

    public function restore(BankReconciliation $reconciliation, BankStatementLine $line, User $actor): void
    {
        $this->editing($reconciliation, $actor, function (BankReconciliation $reconciliation) use ($line, $actor): void {
            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($line->ignored_in_reconciliation_id !== $reconciliation->id || $line->status !== BankStatementLine::IGNORED) {
                throw ValidationException::withMessages(['statement_line_id' => 'Only a line ignored in this reconciliation can be restored.']);
            }
            $line->update(['status' => BankStatementLine::UNMATCHED, 'ignored_in_reconciliation_id' => null]);
            $this->audit->log('bank_reconciliation.line_restored', $reconciliation, ['statement_line_id' => $line->id], $actor);
        });
    }

    public function submit(BankReconciliation $reconciliation, User $actor): BankReconciliation
    {
        $this->authorizeAccount($reconciliation->financialAccount, $actor, 'bank_reconciliation.prepare');

        return DB::transaction(function () use ($reconciliation, $actor): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            if (! $reconciliation->isDraft()) {
                throw ValidationException::withMessages(['status' => 'Only a draft reconciliation can be submitted.']);
            }
            $figures = $this->assertReconciled($reconciliation);
            $reconciliation->update(['status' => BankReconciliation::SUBMITTED, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->audit->log('bank_reconciliation.submitted', $reconciliation, $this->auditFigures($figures), $actor);

            return $reconciliation;
        }, 3);
    }

    public function approve(BankReconciliation $reconciliation, User $actor): BankReconciliation
    {
        $this->authorizeAccount($reconciliation->financialAccount, $actor, 'bank_reconciliation.approve');
        $this->assertChecker($reconciliation, $actor, 'approval', true);

        return DB::transaction(function () use ($reconciliation, $actor): BankReconciliation {
            FinancialAccount::query()->lockForUpdate()->findOrFail($reconciliation->financial_account_id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            if ($reconciliation->status !== BankReconciliation::SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted reconciliation can be approved.']);
            }
            $this->assertChecker($reconciliation, $actor, 'approval');
            $this->assertAfterLatestApproved($reconciliation->financialAccount, CarbonImmutable::parse($reconciliation->statement_date), $reconciliation->id);
            $figures = $this->assertReconciled($reconciliation);
            $reconciliation->update([
                'status' => BankReconciliation::APPROVED, 'open_key' => null, 'approved_by' => $actor->id, 'approved_at' => now(),
                'summary' => $this->calculator->summary($figures),
            ]);
            $this->audit->log('bank_reconciliation.approved', $reconciliation, $this->auditFigures($figures), $actor);

            return $reconciliation;
        }, 3);
    }

    public function reject(BankReconciliation $reconciliation, string $reason, User $actor): BankReconciliation
    {
        $this->authorizeAccount($reconciliation->financialAccount, $actor, 'bank_reconciliation.approve');
        $this->assertChecker($reconciliation, $actor, 'reason', true);

        return DB::transaction(function () use ($reconciliation, $reason, $actor): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            if ($reconciliation->status !== BankReconciliation::SUBMITTED) {
                throw ValidationException::withMessages(['status' => 'Only a submitted reconciliation can be returned.']);
            }
            $this->assertChecker($reconciliation, $actor, 'reason');
            $reconciliation->update(['status' => BankReconciliation::DRAFT, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->log('bank_reconciliation.rejected', $reconciliation, ['reason' => $reason], $actor);

            return $reconciliation;
        }, 3);
    }

    /** @return list<int> Users who prepared, submitted or changed matches of the reconciliation. */
    public function makerIds(BankReconciliation $reconciliation): array
    {
        return array_values(array_unique(array_filter([
            $reconciliation->prepared_by, $reconciliation->submitted_by,
            ...$this->audit->actorIds($reconciliation, self::MAKER_EVENTS),
        ])));
    }

    public function authorizeAccount(FinancialAccount $account, User $actor, string $permission): void
    {
        abort_unless($actor->can($permission) && $account->type === 'bank' && $this->branchAccess->canAccess($actor, $account->branch), 403);
    }

    /**
     * Run a change to a draft reconciliation under a row lock.
     *
     * @template T
     *
     * @param  callable(BankReconciliation): T  $change
     * @return T
     */
    private function editing(BankReconciliation $reconciliation, User $actor, callable $change): mixed
    {
        $this->authorizeAccount($reconciliation->financialAccount, $actor, 'bank_reconciliation.prepare');

        try {
            return DB::transaction(function () use ($reconciliation, $change): mixed {
                $locked = BankReconciliation::query()->with('financialAccount')->lockForUpdate()->findOrFail($reconciliation->id);
                if (! $locked->isDraft()) {
                    throw ValidationException::withMessages(['status' => $locked->isApproved()
                        ? 'This reconciliation is approved and locked. Matches can no longer change.'
                        : 'This reconciliation is submitted for approval. It must be returned before matches can change.']);
                }

                return $change($locked);
            }, 3);
        } catch (QueryException) {
            throw ValidationException::withMessages(['journal_line_id' => 'That statement line or book entry has already been matched.']);
        }
    }

    private function createMatch(BankReconciliation $reconciliation, int $statementLineId, int $journalLineId, User $actor, string $type): BankReconciliationMatch
    {
        $statementLine = $this->statementLine($reconciliation, $statementLineId);
        $bookLine = $this->bookLine($reconciliation, $journalLineId);
        if (AccountingDecimal::of($statementLine->amount)->compareTo(Amount::journalLine($bookLine->debit, $bookLine->credit)) !== 0) {
            throw ValidationException::withMessages(['journal_line_id' => 'The statement amount and the book amount must be equal: deposits match book debits and withdrawals match book credits.']);
        }
        $match = $this->insertMatch(['bank_reconciliation_id' => $reconciliation->id, 'bank_statement_line_id' => $statementLine->id, 'journal_line_id' => $bookLine->id, 'match_type' => $type, 'matched_by' => $actor->id]);
        $statementLine->update(['status' => BankStatementLine::MATCHED]);

        return $match->setRelation('statementLine', $statementLine);
    }

    /** @param array<string, mixed> $attributes */
    private function insertMatch(array $attributes): BankReconciliationMatch
    {
        if (BankReconciliationMatch::query()->where('journal_line_id', $attributes['journal_line_id'])->exists()) {
            throw ValidationException::withMessages(['journal_line_id' => 'This book entry has already been matched in a reconciliation of this account.']);
        }

        return BankReconciliationMatch::query()->create($attributes);
    }

    private function statementLine(BankReconciliation $reconciliation, int $id): BankStatementLine
    {
        $line = BankStatementLine::query()->lockForUpdate()->where('financial_account_id', $reconciliation->financial_account_id)->find($id);
        if (! $line || $line->transaction_date->toDateString() > $reconciliation->statement_date->toDateString()) {
            throw ValidationException::withMessages(['statement_line_id' => 'Select a statement line of this bank account dated on or before the statement date.']);
        }
        if ($line->status !== BankStatementLine::UNMATCHED) {
            throw ValidationException::withMessages(['statement_line_id' => 'This statement line has already been matched or ignored.']);
        }

        return $line;
    }

    private function bookLine(BankReconciliation $reconciliation, int $id): JournalLine
    {
        $line = $this->book->lines($reconciliation->financialAccount, $reconciliation->statement_date)->with('journalEntry')->where('journal_lines.id', $id)->first();
        if (! $line) {
            throw ValidationException::withMessages(['journal_line_id' => 'Select a posted entry in this account\'s bank book dated on or before the statement date.']);
        }
        if (BankReconciliationMatch::query()->where('journal_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages(['journal_line_id' => 'This book entry has already been matched in a reconciliation of this account.']);
        }

        return $line;
    }

    private function assertAfterLatestApproved(FinancialAccount $account, CarbonImmutable $date, ?int $exceptId = null): void
    {
        $latest = BankReconciliation::query()->where('financial_account_id', $account->id)->where('status', BankReconciliation::APPROVED)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->max('statement_date');
        if ($latest && $date->toDateString() <= CarbonImmutable::parse($latest)->toDateString()) {
            throw ValidationException::withMessages(['statement_date' => 'The statement date must be after the latest approved reconciliation of this account ('.CarbonImmutable::parse($latest)->format('d M Y').').']);
        }
    }

    /** Checked before the transaction so the denial stays in the audit trail, and again under the lock. */
    private function assertChecker(BankReconciliation $reconciliation, User $actor, string $field, bool $audit = false): void
    {
        if (in_array($actor->id, $this->makerIds($reconciliation), true)) {
            if ($audit) {
                $this->audit->log('bank_reconciliation.approval_denied', $reconciliation, ['reason' => 'maker_checker'], $actor);
            }
            throw ValidationException::withMessages([$field => 'A reconciliation must be approved or returned by someone other than its preparer, submitter or anyone who changed its matches.']);
        }
    }

    /** @return array<string, mixed> */
    private function assertReconciled(BankReconciliation $reconciliation): array
    {
        $figures = $this->calculator->calculate($reconciliation);
        if (! $figures['difference']->isZero()) {
            throw ValidationException::withMessages(['status' => 'The reconciliation difference is '.Amount::format($figures['difference']).'. It must be exactly 0.00 before submission or approval.']);
        }

        return $figures;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, string>
     */
    private function auditFigures(array $figures): array
    {
        return collect(['statement_balance', 'book_balance', 'uncleared_total', 'unpresented_total', 'difference'])
            ->mapWithKeys(fn (string $key): array => [$key => AccountingDecimal::string($figures[$key])])->all();
    }
}
