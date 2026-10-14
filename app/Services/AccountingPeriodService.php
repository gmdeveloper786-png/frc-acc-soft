<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function __construct(private AuditLogger $audit) {}

    public function open(AccountingPeriod $period): void
    {
        if ($period->fiscalYear->status !== FiscalYearStatus::Open || $period->status !== AccountingPeriodStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'This period cannot be opened in its current state.']);
        }
        $period->update(['status' => AccountingPeriodStatus::Open, 'locked_at' => null, 'locked_by' => null]);
        $this->audit->log('accounting_period.opened', $period);
    }

    public function close(AccountingPeriod $period, User $actor): void
    {
        DB::transaction(function () use ($period, $actor): void {
            FiscalYear::query()->lockForUpdate()->findOrFail($period->fiscal_year_id);
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['status' => 'Only an open period can be closed.']);
            }
            $period->update(['status' => AccountingPeriodStatus::Closed, 'locked_at' => now(), 'locked_by' => $actor->id]);
            $this->audit->log('accounting_period.closed', $period);
        });
    }

    public function reopen(AccountingPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($period->fiscal_year_id);
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($year->status !== FiscalYearStatus::Open || $period->status !== AccountingPeriodStatus::Closed) {
                throw ValidationException::withMessages(['status' => 'This period cannot be reopened in its current state.']);
            }
            $period->update(['status' => AccountingPeriodStatus::Open, 'locked_at' => null, 'locked_by' => null]);
            $this->audit->log('accounting_period.reopened', $period);
        });
    }

    public function postingAllowed(AccountingPeriod $period): bool
    {
        return $period->fiscalYear->status === FiscalYearStatus::Open
            && $period->status === AccountingPeriodStatus::Open;
    }
}
