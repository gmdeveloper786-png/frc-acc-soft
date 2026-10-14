<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['payroll_run_id', 'employee_id', 'gross_amount', 'total_deductions', 'net_amount', 'validation_errors'])]
class PayrollRunLine extends Model
{
    protected static function booted(): void
    {
        $guard = function (PayrollRunLine $line): void {
            self::assertRunEditable((int) $line->payroll_run_id);
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** Lines and their items may only change while the run is a draft or was returned. */
    public static function assertRunEditable(int $runId): void
    {
        $status = PayrollRun::query()->whereKey($runId)->value('status');
        if (! $status instanceof PayrollRunStatus || ! $status->isEditable()) {
            throw new LogicException('Payroll lines can only change while the run is a draft.');
        }
    }

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:4', 'total_deductions' => 'decimal:4', 'net_amount' => 'decimal:4', 'validation_errors' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollRunLineItem::class)->orderBy('id');
    }
}
