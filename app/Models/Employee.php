<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee master record. Bank details and the salary structure are sensitive
 * and are only released to users holding payroll.view_sensitive.
 */
#[Fillable(['employee_code', 'name', 'branch_id', 'designation', 'employment_status', 'joined_on', 'bank_details', 'notes', 'created_by'])]
#[Hidden(['bank_details'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['employment_status' => EmploymentStatus::class, 'joined_on' => 'date', 'bank_details' => 'encrypted'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salaryItems(): HasMany
    {
        return $this->hasMany(EmployeeSalaryItem::class);
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
