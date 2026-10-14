<?php

namespace App\Models;

use App\Enums\PayrollImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'accounting_period_id', 'original_filename', 'stored_path', 'file_hash', 'sheet_name', 'header_row', 'data_start_row', 'detected_headers', 'column_mapping', 'status', 'summary', 'payroll_run_id', 'created_by', 'confirmed_by', 'confirmed_at'])]
class PayrollImport extends Model
{
    protected function casts(): array
    {
        return ['status' => PayrollImportStatus::class, 'detected_headers' => 'array', 'column_mapping' => 'array', 'summary' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PayrollImportRow::class)->orderBy('row_number');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
