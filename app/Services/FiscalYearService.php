<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalYearService
{
    public function __construct(private AuditLogger $audit, private YearEndClosingService $closing) {}

    /** @param array{name:string,start_date:string,end_date:string} $data */
    public function create(array $data, User $actor): FiscalYear
    {
        return DB::transaction(function () use ($data, $actor): FiscalYear {
            $this->ensureNoOverlap($data['start_date'], $data['end_date']);
            $year = FiscalYear::query()->create([...$data, 'status' => FiscalYearStatus::Draft, 'is_current' => false, 'created_by' => $actor->id]);
            $this->generateMonthlyPeriods($year);
            $this->audit->log('fiscal_year.created', $year, ['name' => $year->name, 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString()]);

            return $year;
        });
    }

    /** @param array{name:string,start_date:string,end_date:string} $data */
    public function update(FiscalYear $year, array $data): FiscalYear
    {
        if ($year->status !== FiscalYearStatus::Draft || $year->openingBalanceBatches()->exists()) {
            throw ValidationException::withMessages(['fiscal_year' => 'Only an unused draft fiscal year can be edited.']);
        }

        return DB::transaction(function () use ($year, $data): FiscalYear {
            $this->ensureNoOverlap($data['start_date'], $data['end_date'], $year);
            $year->periods()->delete();
            $year->update($data);
            $this->generateMonthlyPeriods($year->refresh());
            $this->audit->log('fiscal_year.updated', $year, ['name' => $year->name]);

            return $year;
        });
    }

    public function open(FiscalYear $year): void
    {
        if ($year->status !== FiscalYearStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft fiscal year can be opened.']);
        }
        DB::transaction(function () use ($year): void {
            $year->update(['status' => FiscalYearStatus::Open, 'closed_by' => null, 'closed_at' => null]);
            $year->periods()->where('status', AccountingPeriodStatus::Draft->value)->update(['status' => AccountingPeriodStatus::Open->value]);
            $this->audit->log('fiscal_year.opened', $year);
        });
    }

    public function close(FiscalYear $year, User $actor): void
    {
        if ($year->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['status' => 'Only an open fiscal year can be closed.']);
        }
        if ($this->closing->hasUnclosedBalance($year)) {
            throw ValidationException::withMessages(['closing' => 'Post the year-end closing entry before closing the fiscal year.']);
        }
        if ($year->periods()->where('status', '!=', AccountingPeriodStatus::Closed->value)->exists()) {
            throw ValidationException::withMessages(['periods' => 'Close every accounting period before closing the fiscal year.']);
        }
        DB::transaction(function () use ($year, $actor): void {
            $year->update(['status' => FiscalYearStatus::Closed, 'is_current' => false, 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit->log('fiscal_year.closed', $year, ['year_end_closing_check' => 'income_and_expenditure_closed']);
        });
    }

    public function reopen(FiscalYear $year): void
    {
        if ($year->status !== FiscalYearStatus::Closed) {
            throw ValidationException::withMessages(['status' => 'Only a closed fiscal year can be reopened.']);
        }
        $year->update(['status' => FiscalYearStatus::Open, 'closed_by' => null, 'closed_at' => null]);
        $this->audit->log('fiscal_year.reopened', $year);
    }

    public function setCurrent(FiscalYear $year): void
    {
        if ($year->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['status' => 'Only an open fiscal year can be selected as current.']);
        }
        DB::transaction(function () use ($year): void {
            FiscalYear::query()->lockForUpdate()->where('is_current', true)->update(['is_current' => false]);
            $year->update(['is_current' => true]);
            $this->audit->log('fiscal_year.current_selected', $year);
        });
    }

    private function ensureNoOverlap(string $startDate, string $endDate, ?FiscalYear $except = null): void
    {
        $overlap = FiscalYear::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'Fiscal year dates overlap an existing fiscal year.']);
        }
    }

    private function generateMonthlyPeriods(FiscalYear $year): void
    {
        $cursor = CarbonImmutable::parse($year->start_date);
        $end = CarbonImmutable::parse($year->end_date);
        $number = 1;

        while ($cursor->lte($end)) {
            $periodEnd = $cursor->addMonthNoOverflow()->subDay()->min($end);
            $year->periods()->create([
                'name' => $cursor->format('F Y'),
                'period_number' => $number++,
                'start_date' => $cursor,
                'end_date' => $periodEnd,
                'status' => AccountingPeriodStatus::Draft,
            ]);
            $cursor = $periodEnd->addDay();
        }
    }
}
