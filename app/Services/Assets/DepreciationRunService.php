<?php

namespace App\Services\Assets;

use App\Enums\AccountingPeriodStatus;
use App\Enums\DepreciationConvention;
use App\Enums\DepreciationRunStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\FixedAssetStatus;
use App\Enums\JournalStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\DepreciationRun;
use App\Models\DepreciationRunLine;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly straight-line depreciation for one branch and one accounting
 * period: draft, submit, approve, post one journal, and reverse.
 */
class DepreciationRunService
{
    public const SOURCE_TYPE = 'depreciation_run';

    public function __construct(
        private BranchAccess $branchAccess,
        private DepreciationCalculator $calculator,
        private AssetPolicy $policy,
        private AssetCategoryService $categories,
        private JournalPostingService $posting,
        private JournalReversalService $reversal,
        private AuditLogger $audit,
    ) {}

    /**
     * What a run for this branch and period would contain right now. A
     * preview only: nothing is stored and nothing is posted.
     *
     * @return array{lines: list<array<string, mixed>>, total: string, convention: DepreciationConvention}
     */
    public function preview(Branch $branch, AccountingPeriod $period, User $actor): array
    {
        abort_unless($actor->is_active && $actor->canAny(['depreciation.view', 'depreciation.prepare']), 403);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        $convention = $this->policy->convention();
        $lines = $this->compute($branch, $period, $convention);

        return ['lines' => $lines, 'total' => $this->total($lines), 'convention' => $convention];
    }

    public function prepare(int $branchId, int $periodId, User $actor): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.prepare'), 403);

        return DB::transaction(function () use ($branchId, $periodId, $actor): DepreciationRun {
            $branch = Branch::query()->findOrFail($branchId);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            $period = $this->openPeriod($periodId);

            $run = DepreciationRun::query()->create([
                'branch_id' => $branch->id, 'accounting_period_id' => $period->id, 'status' => DepreciationRunStatus::Draft,
                'convention' => $this->policy->convention(), 'total_amount' => '0.0000', 'created_by' => $actor->id,
            ]);
            $this->rebuildLines($run, $branch, $period);
            $this->audit->log('depreciation.prepared', $run, ['branch_id' => $branch->id, 'accounting_period_id' => $period->id, 'total_amount' => $run->total_amount, 'convention' => $run->convention->value, 'assets' => $run->lines()->count()]);

            return $run->load('lines');
        }, 3);
    }

    public function submit(DepreciationRun $run, User $actor): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.prepare'), 403);

        return DB::transaction(function () use ($run, $actor): DepreciationRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== DepreciationRunStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft depreciation run can be submitted.']);
            }
            // A draft may be stale or returned from rejection: always submit freshly calculated lines.
            $this->rebuildLines($run, $run->branch, $this->openPeriod($run->accounting_period_id));
            $run->update(['status' => DepreciationRunStatus::Submitted, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->audit->log('depreciation.submitted', $run, ['total_amount' => $run->total_amount]);

            return $run;
        }, 3);
    }

    public function approve(DepreciationRun $run, User $actor): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.approve'), 403);

        return DB::transaction(function () use ($run, $actor): DepreciationRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== DepreciationRunStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted depreciation run can be approved.']);
            }
            $this->assertNotMaker($run, $actor);
            $run->update(['status' => DepreciationRunStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->log('depreciation.approved', $run, ['total_amount' => $run->total_amount]);

            return $run;
        });
    }

    /** Returns the run to draft and releases its assets for a fresh calculation. */
    public function reject(DepreciationRun $run, string $reason, User $actor): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.approve'), 403);

        return DB::transaction(function () use ($run, $reason, $actor): DepreciationRun {
            $run = $this->lock($run, $actor);
            if (! in_array($run->status, [DepreciationRunStatus::Submitted, DepreciationRunStatus::Approved], true)) {
                throw ValidationException::withMessages(['status' => 'Only a submitted or approved depreciation run can be rejected.']);
            }
            $this->assertNotMaker($run, $actor);
            $from = $run->status;
            DepreciationRunLine::query()->where('depreciation_run_id', $run->id)->update(['active_key' => null]);
            $run->update(['status' => DepreciationRunStatus::Draft, 'rejection_reason' => $reason, 'approved_by' => null, 'approved_at' => null]);
            $this->audit->log('depreciation.rejected', $run, ['from_status' => $from->value, 'reason' => $reason]);

            return $run;
        });
    }

    /** A draft is not a financial record, so it may be removed (with an audit trail). */
    public function discard(DepreciationRun $run, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('depreciation.prepare'), 403);

        DB::transaction(function () use ($run, $actor): void {
            $run = $this->lock($run, $actor);
            if ($run->status !== DepreciationRunStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft depreciation run can be discarded.']);
            }
            $this->audit->log('depreciation.discarded', $run, ['branch_id' => $run->branch_id, 'accounting_period_id' => $run->accounting_period_id, 'total_amount' => $run->total_amount]);
            DepreciationRunLine::query()->where('depreciation_run_id', $run->id)->delete();
            $run->delete();
        });
    }

    /**
     * Post the run as ONE journal: debit depreciation expense, credit
     * accumulated depreciation. Every figure is recalculated first and the
     * run is refused if anything differs from what was approved.
     */
    public function post(DepreciationRun $run, User $actor): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.post') && $actor->can('journals.post'), 403);

        return DB::transaction(function () use ($run, $actor): DepreciationRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== DepreciationRunStatus::Approved || $run->journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'Only an approved, unposted depreciation run can be posted.']);
            }
            if (! $this->policy->isApproved()) {
                throw ValidationException::withMessages(['policy' => 'Depreciation cannot be posted until the depreciation policy is approved. A Director must approve the setting "'.AssetPolicy::APPROVAL_SETTING.'" (Depreciation Policy Approved).']);
            }
            $period = $this->openPeriod($run->accounting_period_id);
            $lines = $run->lines()->with('asset')->get();
            $this->assertStillValid($run, $period, $lines->all());

            [$debits, $credits] = [[], []];
            foreach ($lines as $line) {
                $asset = $line->asset;
                $this->categories->assertAccounts($asset->asset_account_id, $asset->accumulated_depreciation_account_id, $asset->depreciation_expense_account_id);
                $amount = AccountingDecimal::of($line->depreciation_amount);
                $debits[$asset->depreciation_expense_account_id] = ($debits[$asset->depreciation_expense_account_id] ?? BigDecimal::zero()->toScale(4))->plus($amount);
                $credits[$asset->accumulated_depreciation_account_id] = ($credits[$asset->accumulated_depreciation_account_id] ?? BigDecimal::zero()->toScale(4))->plus($amount);
            }
            ksort($debits);
            ksort($credits);

            $voucher = VoucherType::query()->where('code', 'JV')->where('is_active', true)->firstOrFail();
            $label = 'Depreciation '.$period->name.' - '.$run->branch->name;
            $journal = JournalEntry::query()->create([
                'voucher_type_id' => $voucher->id, 'transaction_date' => $period->end_date->toDateString(),
                'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
                'branch_id' => $run->branch_id, 'narration' => $label, 'reference_number' => 'DEP-RUN-'.$run->id,
                'source_system' => 'accounting', 'source_type' => self::SOURCE_TYPE, 'source_id' => (string) $run->id,
                'source_key' => self::SOURCE_TYPE.':'.$run->id, 'status' => JournalStatus::Approved,
                'total_debit' => $run->total_amount, 'total_credit' => $run->total_amount,
                'created_by' => $run->created_by, 'submitted_by' => $run->submitted_by, 'approved_by' => $run->approved_by,
                'submitted_at' => $run->submitted_at, 'approved_at' => $run->approved_at,
            ]);
            $lineNumber = 0;
            foreach ($debits as $accountId => $amount) {
                $journal->lines()->create(['line_number' => ++$lineNumber, 'account_id' => $accountId, 'description' => 'Depreciation expense - '.$period->name, 'debit' => AccountingDecimal::string($amount), 'credit' => '0.0000']);
            }
            foreach ($credits as $accountId => $amount) {
                $journal->lines()->create(['line_number' => ++$lineNumber, 'account_id' => $accountId, 'description' => 'Accumulated depreciation - '.$period->name, 'debit' => '0.0000', 'credit' => AccountingDecimal::string($amount)]);
            }
            $journal = $this->posting->postSystem($journal, $actor);

            $run->update(['status' => DepreciationRunStatus::Posted, 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()]);
            foreach ($lines as $line) {
                $asset = $line->asset;
                if (AccountingDecimal::of($line->accumulated_after)->isEqualTo($this->calculator->depreciableAmount($asset))) {
                    $asset->update(['status' => FixedAssetStatus::FullyDepreciated]);
                }
                $asset->events()->create(['event' => 'depreciated', 'actor_id' => $actor->id, 'created_at' => now(), 'details' => "Depreciation {$line->depreciation_amount} posted for {$period->name} (run #{$run->id}, voucher {$journal->voucher_number}). Net book value {$line->net_book_value_after}."]);
            }
            $this->audit->log('depreciation.posted', $run, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number, 'total_amount' => $run->total_amount, 'assets' => $lines->count()]);

            return $run->refresh();
        }, 3);
    }

    /**
     * Reverse a posted run. Its lines stop counting, so accumulated
     * depreciation falls back and the period can be run again.
     */
    public function reverse(DepreciationRun $run, string $reason, User $actor, ?string $date = null): DepreciationRun
    {
        abort_unless($actor->is_active && $actor->can('depreciation.reverse') && $actor->can('journals.reverse'), 403);

        return DB::transaction(function () use ($run, $reason, $actor, $date): DepreciationRun {
            $run = $this->lock($run, $actor);
            if ($run->status !== DepreciationRunStatus::Posted || ! $run->journalEntry) {
                throw ValidationException::withMessages(['status' => 'Only a posted depreciation run can be reversed.']);
            }
            $period = $run->accountingPeriod;
            $assetIds = $run->lines()->pluck('fixed_asset_id');
            $laterPosted = DepreciationRunLine::query()->whereIn('fixed_asset_id', $assetIds)->where('active_key', DepreciationRunLine::ACTIVE)
                ->whereHas('run', fn ($query) => $query->where('status', DepreciationRunStatus::Posted->value))
                ->whereHas('accountingPeriod', fn ($query) => $query->whereDate('start_date', '>', $period->start_date))
                ->exists();
            if ($laterPosted) {
                throw ValidationException::withMessages(['status' => 'Depreciation has been posted for a later period for assets in this run. Reverse the later runs first.']);
            }

            $reversalDate = Carbon::parse($date ?? $period->end_date)->toDateString();
            $reversalPeriod = AccountingPeriod::query()->with('fiscalYear')->where('status', AccountingPeriodStatus::Open->value)
                ->whereDate('start_date', '<=', $reversalDate)->whereDate('end_date', '>=', $reversalDate)->first();
            if (! $reversalPeriod || $reversalPeriod->fiscalYear->status !== FiscalYearStatus::Open) {
                throw ValidationException::withMessages(['reversal_date' => 'There is no open accounting period for the reversal date '.$reversalDate.'. Choose a date inside an open period.']);
            }
            $reversalJournal = $this->reversal->reverse($run->journalEntry, $reversalPeriod, $reversalDate, $reason, $actor);

            $run->update(['status' => DepreciationRunStatus::Reversed, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            DepreciationRunLine::query()->where('depreciation_run_id', $run->id)->update(['active_key' => null]);
            foreach (FixedAsset::query()->whereIn('id', $assetIds)->lockForUpdate()->get() as $asset) {
                if ($asset->status === FixedAssetStatus::FullyDepreciated) {
                    $asset->update(['status' => FixedAssetStatus::Active]);
                }
                $asset->events()->create(['event' => 'depreciation_reversed', 'actor_id' => $actor->id, 'created_at' => now(), 'details' => "Depreciation for {$period->name} (run #{$run->id}) reversed by voucher {$reversalJournal->voucher_number}. Reason: {$reason}"]);
            }
            $this->audit->log('depreciation.reversed', $run, ['journal_entry_id' => $run->journal_entry_id, 'reversal_journal_id' => $reversalJournal->id, 'reason' => $reason]);

            return $run->refresh();
        }, 3);
    }

    /** Whether the user may approve or reject this run right now. */
    public function canDecide(DepreciationRun $run, User $actor): bool
    {
        return $actor->is_active && $actor->can('depreciation.approve')
            && ! in_array($actor->id, [$run->created_by, $run->submitted_by], true)
            && $this->branchAccess->canAccess($actor, $run->branch);
    }

    /**
     * Lines for every eligible asset of the branch. An asset is skipped when
     * it is not active, already has a counting line for the period in another
     * run, has posted depreciation for a later period, or earns nothing.
     *
     * @return list<array{fixed_asset_id: int, asset: FixedAsset, accounting_period_id: int, depreciation_amount: string, accumulated_before: string, accumulated_after: string, net_book_value_after: string}>
     */
    private function compute(Branch $branch, AccountingPeriod $period, DepreciationConvention $convention, ?int $exceptRunId = null, bool $lock = false): array
    {
        $assets = FixedAsset::query()->with('category')->where('branch_id', $branch->id)->where('status', FixedAssetStatus::Active->value)
            ->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->get();
        $rows = [];

        foreach ($assets as $asset) {
            if ($lock) {
                // Locking reads: plain reads could miss lines and postings committed while this transaction waited for the asset.
                $active = $asset->depreciationLines()->where('active_key', DepreciationRunLine::ACTIVE)->lockForUpdate()->get();
                $postedRunIds = DepreciationRun::query()->whereKey($active->pluck('depreciation_run_id')->unique()->values())->where('status', DepreciationRunStatus::Posted->value)->lockForUpdate()->pluck('id');
                $posted = $active->whereIn('depreciation_run_id', $postedRunIds);
                $laterPeriodIds = AccountingPeriod::query()->whereKey($posted->pluck('accounting_period_id')->unique()->values())->whereDate('start_date', '>', $period->start_date)->pluck('id');
                $heldElsewhere = $active->where('accounting_period_id', $period->id)->contains(fn (DepreciationRunLine $line): bool => $exceptRunId === null || (int) $line->depreciation_run_id !== $exceptRunId);
                $postedLater = $posted->whereIn('accounting_period_id', $laterPeriodIds)->isNotEmpty();
                $before = $posted->reduce(fn (BigDecimal $sum, DepreciationRunLine $line): BigDecimal => $sum->plus(AccountingDecimal::of($line->depreciation_amount)), AccountingDecimal::of($asset->opening_accumulated_depreciation));
            } else {
                $heldElsewhere = $asset->depreciationLines()->where('accounting_period_id', $period->id)->where('active_key', DepreciationRunLine::ACTIVE)
                    ->when($exceptRunId, fn ($query) => $query->where('depreciation_run_id', '!=', $exceptRunId))->exists();
                $postedLater = $asset->postedLines()->whereHas('accountingPeriod', fn ($query) => $query->whereDate('start_date', '>', $period->start_date))->exists();
                $before = AccountingDecimal::of($asset->accumulatedDepreciation());
            }
            if ($heldElsewhere || $postedLater) {
                continue;
            }

            $amount = $this->calculator->amountForPeriod($asset, $period->start_date, $period->end_date, $before, $convention);
            if ($amount === null) {
                continue;
            }
            $after = $before->plus($amount);
            $rows[] = [
                'fixed_asset_id' => $asset->id, 'asset' => $asset, 'accounting_period_id' => $period->id,
                'depreciation_amount' => AccountingDecimal::string($amount), 'accumulated_before' => AccountingDecimal::string($before),
                'accumulated_after' => AccountingDecimal::string($after),
                'net_book_value_after' => AccountingDecimal::string(AccountingDecimal::of($asset->acquisition_cost)->minus($after)),
            ];
        }

        return $rows;
    }

    private function rebuildLines(DepreciationRun $run, Branch $branch, AccountingPeriod $period): void
    {
        $convention = $this->policy->convention();
        DepreciationRunLine::query()->where('depreciation_run_id', $run->id)->delete();
        $rows = $this->compute($branch, $period, $convention, $run->id, true);
        if ($rows === []) {
            throw ValidationException::withMessages(['accounting_period_id' => 'No asset of this branch is eligible for depreciation in this period. Assets must be active and in service, and each asset can be depreciated only once per period.']);
        }

        try {
            foreach ($rows as $row) {
                $run->lines()->create([...collect($row)->except('asset')->all(), 'active_key' => DepreciationRunLine::ACTIVE]);
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['accounting_period_id' => 'Another depreciation run already covers an asset for this period.']);
        }
        $run->update(['convention' => $convention, 'total_amount' => $this->total($rows)]);
    }

    /**
     * Recalculate the run and refuse to post if anything differs from the
     * approved lines (asset changes, other runs, convention, history).
     *
     * @param  list<DepreciationRunLine>  $lines
     */
    private function assertStillValid(DepreciationRun $run, AccountingPeriod $period, array $lines): void
    {
        $stale = ValidationException::withMessages(['status' => 'The approved figures are no longer valid (an asset, the depreciation convention or earlier depreciation has changed). Reject this run and prepare it again.']);
        $convention = $this->policy->convention();
        if ($run->convention !== $convention || $lines === []) {
            throw $stale;
        }
        $current = collect($this->compute($run->branch, $period, $convention, $run->id, true))->keyBy('fixed_asset_id');
        if ($current->count() !== count($lines)) {
            throw $stale;
        }

        $total = BigDecimal::zero()->toScale(4);
        foreach ($lines as $line) {
            $row = $current->get($line->fixed_asset_id);
            if (! $row || $line->active_key !== DepreciationRunLine::ACTIVE) {
                throw $stale;
            }
            foreach (['depreciation_amount', 'accumulated_before', 'accumulated_after', 'net_book_value_after'] as $field) {
                if (! AccountingDecimal::of($line->{$field})->isEqualTo(AccountingDecimal::of($row[$field]))) {
                    throw $stale;
                }
            }
            $total = $total->plus(AccountingDecimal::of($line->depreciation_amount));
        }
        if (! $total->isPositive() || ! $total->isEqualTo(AccountingDecimal::of($run->total_amount))) {
            throw $stale;
        }
    }

    private function openPeriod(int $periodId): AccountingPeriod
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->lockForUpdate()->find($periodId);
        if (! $period || $period->status !== AccountingPeriodStatus::Open || $period->fiscalYear->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['accounting_period_id' => 'Depreciation needs an open accounting period in an open fiscal year.']);
        }

        return $period;
    }

    /** @param list<array<string, mixed>> $rows */
    private function total(array $rows): string
    {
        return AccountingDecimal::string(collect($rows)->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus(AccountingDecimal::of($row['depreciation_amount'])), BigDecimal::zero()->toScale(4)));
    }

    private function lock(DepreciationRun $run, User $actor): DepreciationRun
    {
        $run = DepreciationRun::query()->with(['branch', 'accountingPeriod'])->lockForUpdate()->findOrFail($run->id);
        abort_unless($this->branchAccess->canAccess($actor, $run->branch), 403);

        return $run;
    }

    private function assertNotMaker(DepreciationRun $run, User $actor): void
    {
        if (in_array($actor->id, [$run->created_by, $run->submitted_by], true)) {
            throw ValidationException::withMessages(['approval' => 'The user who prepared or submitted a depreciation run cannot approve or reject it.']);
        }
    }
}
