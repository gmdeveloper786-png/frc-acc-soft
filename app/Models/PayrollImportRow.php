<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet row after column mapping. The mapped values hold salary
 * amounts and are therefore encrypted at rest.
 */
#[Fillable(['payroll_import_id', 'row_number', 'employee_id', 'mapped_values', 'formula_cells', 'errors', 'warnings', 'has_formula_warning', 'is_excluded'])]
class PayrollImportRow extends Model
{
    protected function casts(): array
    {
        return ['mapped_values' => 'encrypted:array', 'formula_cells' => 'array', 'errors' => 'array', 'warnings' => 'array', 'has_formula_warning' => 'boolean', 'is_excluded' => 'boolean'];
    }

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PayrollImport::class, 'payroll_import_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
