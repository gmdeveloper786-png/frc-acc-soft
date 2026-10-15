<?php

namespace App\Models;

use App\Services\AccountingDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scheduled recovery of a staff advance, due in an accounting period. */
#[Fillable(['staff_advance_id', 'sequence', 'accounting_period_id', 'amount', 'recovered_amount', 'payroll_run_line_id'])]
class StaffAdvanceInstalment extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'recovered_amount' => 'decimal:4', 'sequence' => 'integer'];
    }

    public function outstandingAmount(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->amount)->minus(AccountingDecimal::of($this->recovered_amount)));
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function payrollRunLine(): BelongsTo
    {
        return $this->belongsTo(PayrollRunLine::class);
    }
}
