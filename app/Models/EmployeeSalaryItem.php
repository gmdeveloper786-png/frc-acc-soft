<?php

namespace App\Models;

use Database\Factories\EmployeeSalaryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an employee's salary structure.
 */
#[Fillable(['employee_id', 'payroll_component_id', 'amount'])]
class EmployeeSalaryItem extends Model
{
    /** @use HasFactory<EmployeeSalaryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }
}
