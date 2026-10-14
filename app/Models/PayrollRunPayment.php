<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Links a salary payment made through the payment engine to its payroll run.
 */
#[Fillable(['payroll_run_id', 'operational_transaction_id', 'created_by'])]
class PayrollRunPayment extends Model
{
    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('A salary payment link cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(OperationalTransaction::class, 'operational_transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
