<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payroll_run_line_id', 'payroll_component_id', 'amount'])]
class PayrollRunLineItem extends Model
{
    protected static function booted(): void
    {
        $guard = function (PayrollRunLineItem $item): void {
            PayrollRunLine::assertRunEditable((int) PayrollRunLine::query()->whereKey($item->payroll_run_line_id)->value('payroll_run_id'));
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollRunLine::class, 'payroll_run_line_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
