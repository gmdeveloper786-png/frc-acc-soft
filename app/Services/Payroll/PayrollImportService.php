<?php

namespace App\Services\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\EmploymentStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PayrollImportStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Models\PayrollImport;
use App\Models\PayrollImportRow;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Secure payroll import: Upload → Preview → Column Mapping → Validation →
 * Error Report → Authorised Confirmation → Draft Payroll.
 *
 * Nothing is imported at upload, no employee is ever created from a file, no
 * spreadsheet formula is evaluated, and confirmation only produces a draft
 * payroll run that still has to be validated, approved and posted.
 */
class PayrollImportService
{
    public const MAX_DATA_ROWS = 2000;

    private const DISK = 'local';

    /** Non-component fields a spreadsheet column can be mapped to. */
    public const FIELDS = ['employee_code', 'employee_name', 'stated_gross', 'stated_net', 'pay_date'];

    /**
     * Default column suggestions, taken from the headings of the client's
     * payroll summary workbook. They only pre-select a drop-down.
     *
     * @var array<string, string>
     */
    private const CATEGORY_HEADINGS = [
        'base_salary' => '/^(basic|gross)( salary| pay)?$/',
        'allowance' => '/^allowances?$/',
        'bonus' => '/^bonus/',
        'arrears' => '/^arrears?/',
        'advance' => '/^advance/',
        'tax' => '/^(income )?tax(es)?$/',
        'leave_adjustment' => '/leaves?$/',
        'statutory' => '/^(akkf|eobi|provident fund|social security)$/',
        'other_deduction' => '/^other deductions?$/',
    ];

    public function __construct(
        private BranchAccess $branchAccess,
        private AuditLogger $audit,
        private PayrollWorkbookReader $reader,
        private PayrollRunService $runs,
    ) {}

    /** Store the workbook privately and detect its headings. Nothing is imported. */
    public function upload(UploadedFile $file, int $branchId, int $periodId, User $actor): PayrollImport
    {
        $this->authorize($actor);
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        $period = AccountingPeriod::query()->with('fiscalYear')->findOrFail($periodId);
        $this->assertOpenPeriod($period);

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw ValidationException::withMessages(['workbook' => 'Upload an .xlsx, .xls or .csv file.']);
        }
        $path = $file->storeAs('payroll-imports', Str::uuid()->toString().'.'.$extension, self::DISK);

        try {
            $sheet = $this->reader->read(Storage::disk(self::DISK)->path($path), $extension);
            if ($sheet['header_row'] === null || count($sheet['headers']) < 2) {
                throw ValidationException::withMessages(['workbook' => 'No header row was found. The first worksheet needs a row of column headings above the employee rows.']);
            }
            $dataRows = count(array_filter(array_keys($sheet['rows']), fn (int $row): bool => $row >= $sheet['data_start_row']));
            if ($dataRows === 0) {
                throw ValidationException::withMessages(['workbook' => 'The worksheet has headings but no data rows.']);
            }
            if ($dataRows > self::MAX_DATA_ROWS) {
                throw ValidationException::withMessages(['workbook' => 'The worksheet has more than '.self::MAX_DATA_ROWS.' data rows.']);
            }
        } catch (ValidationException $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        } catch (Throwable) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['workbook' => 'The file could not be read as a spreadsheet.']);
        }

        return DB::transaction(function () use ($file, $path, $sheet, $branch, $period, $actor, $dataRows): PayrollImport {
            $import = PayrollImport::query()->create([
                'branch_id' => $branch->id, 'accounting_period_id' => $period->id,
                'original_filename' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                'stored_path' => $path, 'file_hash' => hash_file('sha256', Storage::disk(self::DISK)->path($path)),
                'sheet_name' => $sheet['sheet'], 'header_row' => $sheet['header_row'], 'data_start_row' => $sheet['data_start_row'],
                'detected_headers' => $sheet['headers'], 'status' => PayrollImportStatus::Uploaded,
                'summary' => ['data_rows' => $dataRows], 'created_by' => $actor->id,
            ]);
            $this->audit->log('payroll_import.uploaded', $import, ['branch_id' => $branch->id, 'accounting_period_id' => $period->id, 'file_hash' => $import->file_hash, 'data_rows' => $dataRows]);

            return $import;
        });
    }

    /**
     * First data rows as stored in the file, for the preview screen.
     *
     * @return array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>
     */
    public function preview(PayrollImport $import, int $limit = 10): array
    {
        $rows = array_filter($this->readSheet($import)['rows'], fn (int $row): bool => $row >= $import->data_start_row, ARRAY_FILTER_USE_KEY);

        return array_slice($rows, 0, $limit, true);
    }

    /**
     * Pre-selected mapping based on the detected headings.
     *
     * @param  Collection<int, PayrollComponent>  $components
     * @return array{employee_code: ?string, employee_name: ?string, stated_gross: ?string, stated_net: ?string, pay_date: ?string, components: array<int, string>, required: list<int>}
     */
    public function suggestMapping(PayrollImport $import, Collection $components): array
    {
        $headers = collect($import->detected_headers ?? [])->map(fn (string $label): string => Str::of($label)->lower()->squish()->toString());
        $used = [];
        $take = function (callable $matches) use ($headers, &$used): ?string {
            foreach ($headers as $letter => $label) {
                if (! in_array($letter, $used, true) && $matches($label)) {
                    $used[] = $letter;

                    return $letter;
                }
            }

            return null;
        };

        $mapping = [
            'employee_code' => $take(fn (string $label): bool => preg_match('/^((employee|emp|staff)[ .]*)?(code|id|no\.?)$/', $label) === 1),
            'employee_name' => $take(fn (string $label): bool => preg_match('/^((employee|emp|staff)( name)?|name)$/', $label) === 1),
            'stated_gross' => null,
            'stated_net' => $take(fn (string $label): bool => preg_match('/(^|\/ )net (salary|pay)$/', $label) === 1),
            'pay_date' => null,
            'components' => [],
            'required' => [],
        ];
        // An exact component name or code wins over the category keywords.
        foreach ($components as $component) {
            $letter = $take(fn (string $label): bool => in_array($label, [Str::lower($component->name), Str::lower($component->code)], true));
            if ($letter !== null) {
                $mapping['components'][$component->id] = $letter;
            }
        }
        foreach ($components->groupBy(fn (PayrollComponent $component): string => $component->category->value) as $category => $group) {
            $component = $group->first();
            if (isset($mapping['components'][$component->id]) || ! isset(self::CATEGORY_HEADINGS[$category])) {
                continue;
            }
            $letter = $take(fn (string $label): bool => preg_match(self::CATEGORY_HEADINGS[$category], $label) === 1);
            if ($letter !== null) {
                $mapping['components'][$component->id] = $letter;
            }
        }

        return $mapping;
    }

    /**
     * Save the column mapping and validate every data row against it.
     *
     * @param  array<string, mixed>  $input
     */
    public function applyMapping(PayrollImport $import, array $input, User $actor): PayrollImport
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($import, $input, $actor): PayrollImport {
            $import = $this->lock($import, $actor);
            $this->assertOpen($import);
            $mapping = $this->normaliseMapping($import, $input);
            $import->update(['column_mapping' => $mapping, 'status' => PayrollImportStatus::Mapped]);
            $this->rebuildRows($import, []);
            $this->audit->log('payroll_import.mapped', $import, ['columns' => array_filter([$mapping['employee_code'], $mapping['employee_name'], $mapping['stated_gross'], $mapping['stated_net'], $mapping['pay_date']]), 'component_ids' => array_keys($mapping['components']), ...$this->counts($import)]);

            return $import->refresh();
        });
    }

    /**
     * Explicitly exclude rows from, or return them to, the import.
     *
     * @param  list<int>  $rowIds
     */
    public function setExcluded(PayrollImport $import, array $rowIds, bool $excluded, User $actor): PayrollImport
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($import, $rowIds, $excluded, $actor): PayrollImport {
            $import = $this->lock($import, $actor);
            $this->assertOpen($import);
            if ($import->status !== PayrollImportStatus::Validated) {
                throw ValidationException::withMessages(['status' => 'Map the columns before excluding rows.']);
            }
            $rows = $import->rows()->get(['id', 'row_number', 'is_excluded']);
            $changed = $rows->whereIn('id', $rowIds)->pluck('row_number');
            if ($changed->isEmpty()) {
                throw ValidationException::withMessages(['rows' => 'Select at least one row.']);
            }
            $current = $rows->where('is_excluded', true)->pluck('row_number');
            $excludedRows = $excluded ? $current->merge($changed)->unique() : $current->diff($changed);
            // Re-validate: duplicate checks only consider rows that are still included.
            $this->rebuildRows($import, $excludedRows->map(fn ($row): int => (int) $row)->values()->all());
            $this->audit->log($excluded ? 'payroll_import.rows_excluded' : 'payroll_import.rows_included', $import, ['row_numbers' => $changed->values()->all()]);

            return $import->refresh();
        });
    }

    /**
     * Authorised confirmation: creates a DRAFT payroll run only. Nothing is
     * approved or posted.
     */
    public function confirm(PayrollImport $import, bool $formulasAcknowledged, User $actor): PayrollRun
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($import, $formulasAcknowledged, $actor): PayrollRun {
            $import = $this->lock($import, $actor);
            if ($import->status === PayrollImportStatus::Confirmed) {
                throw ValidationException::withMessages(['status' => 'This import was already confirmed and created payroll run #'.$import->payroll_run_id.'.']);
            }
            if ($import->status !== PayrollImportStatus::Validated) {
                throw ValidationException::withMessages(['status' => 'Only a mapped and validated import can be confirmed.']);
            }
            // Validate again against the current employee master and payroll runs.
            $this->rebuildRows($import, $import->rows()->where('is_excluded', true)->pluck('row_number')->map(fn ($row): int => (int) $row)->all());
            $import->refresh();
            if (! empty($import->summary['import_errors'])) {
                throw ValidationException::withMessages(['status' => $import->summary['import_errors']]);
            }
            $rows = $import->rows()->where('is_excluded', false)->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['rows' => 'Every row is excluded: there is nothing to import.']);
            }
            $withErrors = $rows->filter(fn (PayrollImportRow $row): bool => $row->hasErrors())->count();
            if ($withErrors > 0) {
                throw ValidationException::withMessages(['rows' => "{$withErrors} row(s) still have errors. Correct the file and upload it again, or explicitly exclude those rows."]);
            }
            if ($rows->contains('has_formula_warning', true) && ! $formulasAcknowledged) {
                throw ValidationException::withMessages(['acknowledge_formulas' => 'Some amounts were computed by spreadsheet formulas. Verify them and tick the acknowledgement before confirming.']);
            }

            $lines = [];
            foreach ($rows as $row) {
                $lines[(int) $row->employee_id] = $row->mapped_values['amounts'] ?? [];
            }
            $run = $this->runs->createFromImport($import->branch, $import->period, $lines, $actor);
            $import->update(['status' => PayrollImportStatus::Confirmed, 'payroll_run_id' => $run->id, 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            $this->audit->log('payroll_import.confirmed', $import, ['payroll_run_id' => $run->id, 'imported_rows' => $rows->count(), 'excluded_rows' => $import->rows()->where('is_excluded', true)->count(), 'formula_warnings_acknowledged' => $rows->contains('has_formula_warning', true)]);

            return $run;
        }, 3);
    }

    /** Abandon an import and remove its stored file and rows. */
    public function cancel(PayrollImport $import, User $actor): PayrollImport
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($import, $actor): PayrollImport {
            $import = $this->lock($import, $actor);
            $this->assertOpen($import);
            $import->rows()->delete();
            Storage::disk(self::DISK)->delete($import->stored_path);
            $import->update(['status' => PayrollImportStatus::Cancelled]);
            $this->audit->log('payroll_import.cancelled', $import, ['file_hash' => $import->file_hash]);

            return $import;
        });
    }

    /**
     * Error report without any salary amount.
     *
     * @return list<array<string, string>>
     */
    public function report(PayrollImport $import): array
    {
        return $import->rows()->get()->map(fn (PayrollImportRow $row): array => [
            'Row' => (string) $row->row_number,
            'Employee Code' => (string) ($row->mapped_values['employee_code'] ?? ''),
            'Status' => $row->is_excluded ? 'Excluded' : ($row->hasErrors() ? 'Error' : (empty($row->warnings) ? 'OK' : 'Warning')),
            'Errors' => implode(' | ', $row->errors ?? []),
            'Warnings' => implode(' | ', $row->warnings ?? []),
        ])->all();
    }

    /** @return array{total_rows: int, excluded_rows: int, error_rows: int, warning_rows: int, formula_warning_rows: int, ready_rows: int} */
    public function counts(PayrollImport $import): array
    {
        $rows = $import->rows()->get(['id', 'errors', 'warnings', 'has_formula_warning', 'is_excluded']);
        $included = $rows->where('is_excluded', false);

        return [
            'total_rows' => $rows->count(), 'excluded_rows' => $rows->count() - $included->count(),
            'error_rows' => $included->filter(fn (PayrollImportRow $row): bool => $row->hasErrors())->count(),
            'warning_rows' => $included->filter(fn (PayrollImportRow $row): bool => ! empty($row->warnings))->count(),
            'formula_warning_rows' => $included->where('has_formula_warning', true)->count(),
            'ready_rows' => $included->filter(fn (PayrollImportRow $row): bool => ! $row->hasErrors())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{employee_code: string, employee_name: ?string, stated_gross: ?string, stated_net: ?string, pay_date: ?string, components: array<int, string>, required: list<int>}
     */
    private function normaliseMapping(PayrollImport $import, array $input): array
    {
        $columns = array_keys($import->detected_headers ?? []);
        $pick = function (mixed $value, string $field) use ($columns): ?string {
            if (blank($value)) {
                return null;
            }
            if (! in_array($value, $columns, true)) {
                throw ValidationException::withMessages([$field => 'The selected column does not exist in the uploaded sheet.']);
            }

            return (string) $value;
        };

        $mapping = ['components' => [], 'required' => []];
        foreach (self::FIELDS as $field) {
            $mapping[$field] = $pick($input[$field] ?? null, $field);
        }
        if ($mapping['employee_code'] === null) {
            throw ValidationException::withMessages(['employee_code' => 'Map the column that holds the employee code.']);
        }
        $components = PayrollComponent::query()->where('is_active', true)->get()->keyBy('id');
        foreach ((array) ($input['components'] ?? []) as $componentId => $column) {
            $column = $pick($column, "components.{$componentId}");
            if ($column === null) {
                continue;
            }
            if (! $components->has((int) $componentId)) {
                throw ValidationException::withMessages(["components.{$componentId}" => 'Only active payroll components can be mapped.']);
            }
            $mapping['components'][(int) $componentId] = $column;
        }
        if ($mapping['components'] === []) {
            throw ValidationException::withMessages(['components' => 'Map at least one payroll component to a column.']);
        }
        $mapping['required'] = array_values(array_intersect(array_map('intval', (array) ($input['required'] ?? [])), array_keys($mapping['components'])));

        $used = array_filter([...array_map(fn (string $field): ?string => $mapping[$field], self::FIELDS), ...array_values($mapping['components'])]);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['components' => 'Each spreadsheet column can be mapped only once.']);
        }

        return $mapping;
    }

    /**
     * Re-read the stored file and rebuild every row with its errors and
     * warnings.
     *
     * @param  list<int>  $excludedRowNumbers
     */
    private function rebuildRows(PayrollImport $import, array $excludedRowNumbers): void
    {
        $mapping = $import->column_mapping;
        $components = PayrollComponent::query()->whereKey(array_keys($mapping['components']))->get()->keyBy('id');
        $headers = $import->detected_headers ?? [];
        $sheetRows = array_filter($this->readSheet($import)['rows'], fn (int $row): bool => $row >= $import->data_start_row, ARRAY_FILTER_USE_KEY);

        $built = [];
        foreach ($sheetRows as $number => $cells) {
            $built[$number] = $this->buildRow($cells, $mapping, $components, $headers);
            $built[$number]['is_excluded'] = in_array($number, $excludedRowNumbers, true);
        }

        $included = collect($built)->where('is_excluded', false);
        $codes = $included->pluck('values.employee_code')->filter()->map(fn (string $code): string => Str::lower($code));
        $duplicates = $codes->countBy()->filter(fn (int $count): bool => $count > 1)->keys()->all();
        $employees = Employee::query()->where('branch_id', $import->branch_id)
            ->whereIn(DB::raw('lower(employee_code)'), collect($built)->pluck('values.employee_code')->filter()->map(fn (string $code): string => Str::lower($code))->unique()->values()->all())
            ->get()->keyBy(fn (Employee $employee): string => Str::lower($employee->employee_code));

        $import->rows()->delete();
        foreach ($built as $number => $row) {
            $code = $row['values']['employee_code'];
            $employee = $code === null ? null : $employees->get(Str::lower($code));
            if ($code !== null && in_array(Str::lower($code), $duplicates, true) && ! $row['is_excluded']) {
                $row['errors'][] = 'Duplicate employee code in the file.';
            }
            if ($code !== null && ! $employee) {
                $row['errors'][] = 'Unknown employee: this code is not in the employee master of the selected branch. Employees are never created by an import.';
            }
            if ($employee && $employee->employment_status !== EmploymentStatus::Active) {
                $row['errors'][] = 'The employee is not active.';
            }
            $name = $row['values']['employee_name'];
            if ($employee && $name !== null && Str::of($name)->lower()->squish()->toString() !== Str::of($employee->name)->lower()->squish()->toString()) {
                $row['warnings'][] = 'The employee name in the file does not match the employee master.';
            }
            $import->rows()->create([
                'row_number' => $number, 'employee_id' => $employee?->id, 'mapped_values' => $row['values'], 'formula_cells' => $row['formula_cells'],
                'errors' => array_values(array_unique($row['errors'])), 'warnings' => array_values(array_unique($row['warnings'])),
                'has_formula_warning' => $row['has_formula_warning'], 'is_excluded' => $row['is_excluded'],
            ]);
        }

        $importErrors = [];
        if (PayrollRun::query()->where('branch_id', $import->branch_id)->where('accounting_period_id', $import->accounting_period_id)->whereNotNull('active_key')->exists()) {
            $importErrors[] = 'Duplicate payroll: a payroll run already exists for this branch and period.';
        }
        $period = $import->period()->with('fiscalYear')->first();
        if ($period->status !== AccountingPeriodStatus::Open || $period->fiscalYear->status !== FiscalYearStatus::Open) {
            $importErrors[] = 'The accounting period and its fiscal year must both be open.';
        }
        $import->update(['status' => PayrollImportStatus::Validated, 'summary' => [...$this->counts($import), 'import_errors' => $importErrors]]);
    }

    /**
     * @param  array<string, array{value: string|null, formula: bool, unsafe: bool}>  $cells
     * @param  array<string, mixed>  $mapping
     * @param  SupportCollection<int, PayrollComponent>  $components
     * @param  array<string, string>  $headers
     * @return array{values: array<string, mixed>, formula_cells: list<array{column: string, unsafe: bool}>, errors: list<string>, warnings: list<string>, has_formula_warning: bool}
     */
    private function buildRow(array $cells, array $mapping, SupportCollection $components, array $headers): array
    {
        $errors = [];
        $warnings = [];
        $formulaCells = [];
        $hasFormulaWarning = false;

        // Formula flags apply to every mapped cell, text or amount.
        $read = function (?string $column) use ($cells, $headers, &$errors, &$warnings, &$formulaCells, &$hasFormulaWarning): ?string {
            $cell = $column === null ? null : ($cells[$column] ?? null);
            if ($cell === null) {
                return null;
            }
            $label = $headers[$column] ?? 'Column '.$column;
            if ($cell['formula']) {
                $formulaCells[] = ['column' => $column, 'unsafe' => $cell['unsafe']];
                if ($cell['unsafe']) {
                    $errors[] = "{$label}: the cell uses a volatile or indirect spreadsheet function (".implode(', ', PayrollWorkbookReader::UNSAFE_FUNCTIONS).') and cannot be imported. Replace it with a fixed value.';

                    return null;
                }
                if ($cell['value'] === null) {
                    $errors[] = "{$label}: the formula has no stored result. Replace it with a fixed value.";

                    return null;
                }
                $warnings[] = "{$label}: computed in the spreadsheet — verify.";
                $hasFormulaWarning = true;
            }

            return $cell['value'];
        };
        $amount = function (?string $column) use ($read, $headers, &$errors): ?BigDecimal {
            $value = $read($column);
            if ($value === null) {
                return null;
            }
            $label = $headers[$column] ?? 'Column '.$column;
            $plain = preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value) === 1 ? str_replace(',', '', $value) : $value;
            if (preg_match('/^-?\d+(\.\d+)?$/', $plain) !== 1) {
                $errors[] = "{$label}: the value is not a valid number.";

                return null;
            }
            if (str_starts_with($plain, '-') && preg_match('/[1-9]/', $plain) === 1) {
                $errors[] = "{$label}: negative amounts are not allowed.";

                return null;
            }
            if (strlen((string) strrchr($plain, '.')) > 5) {
                $errors[] = "{$label}: amounts may have at most four decimals.";

                return null;
            }

            return AccountingDecimal::of(ltrim($plain, '-'));
        };

        $code = $read($mapping['employee_code']);
        if ($code === null) {
            $errors[] = 'Missing employee code.';
        }
        $name = $read($mapping['employee_name']);

        $zero = BigDecimal::zero()->toScale(4);
        [$gross, $deductions, $amounts, $valued] = [$zero, $zero, [], 0];
        foreach ($mapping['components'] as $componentId => $column) {
            $component = $components->get((int) $componentId);
            $errorsBefore = count($errors);
            $value = $amount($column);
            if ($value === null) {
                if (count($errors) === $errorsBefore && in_array((int) $componentId, $mapping['required'], true)) {
                    $errors[] = "{$component->name}: a value is required by the column mapping but the cell is empty.";
                }

                continue;
            }
            $valued++;
            if (! $value->isZero()) {
                $amounts[(int) $componentId] = AccountingDecimal::string($value);
            }
            if ($component->isEarning()) {
                $gross = $gross->plus($value);
            } else {
                $deductions = $deductions->plus($value);
            }
        }
        $net = $gross->minus($deductions);
        if ($valued === 0) {
            $errors[] = 'No mapped payroll component has a value in this row.';
        } elseif ($net->isNegative()) {
            $errors[] = 'Net pay is negative: deductions exceed earnings.';
        }

        $stated = [];
        foreach (['stated_gross' => $gross, 'stated_net' => $net] as $field => $computed) {
            $value = $amount($mapping[$field]);
            $stated[$field] = $value === null ? null : AccountingDecimal::string($value);
            if ($value !== null && $valued > 0 && ! $value->isEqualTo($computed)) {
                $errors[] = ($field === 'stated_gross' ? 'Gross' : 'Net').' stated in the file ('.AccountingDecimal::string($value).') does not equal the '.($field === 'stated_gross' ? 'gross' : 'net').' computed from the mapped components ('.AccountingDecimal::string($computed).').';
            }
        }

        $payDate = $read($mapping['pay_date']);
        if ($payDate !== null) {
            $parsed = $this->parseDate($payDate);
            if ($parsed === null) {
                $errors[] = ($headers[$mapping['pay_date']] ?? 'Date').': the value is not a valid date.';
            }
            $payDate = $parsed;
        }

        return [
            'values' => [
                'employee_code' => $code, 'employee_name' => $name, 'amounts' => $amounts,
                'gross' => AccountingDecimal::string($gross), 'deductions' => AccountingDecimal::string($deductions), 'net' => AccountingDecimal::string($net),
                'stated_gross' => $stated['stated_gross'], 'stated_net' => $stated['stated_net'], 'pay_date' => $payDate,
            ],
            'formula_cells' => $formulaCells, 'errors' => $errors, 'warnings' => $warnings, 'has_formula_warning' => $hasFormulaWarning,
        ];
    }

    /** ISO date from an Excel serial number or an unambiguous date string. */
    private function parseDate(string $value): ?string
    {
        try {
            if (is_numeric($value)) {
                $serial = (float) $value;

                return $serial >= 1 && $serial < 2958466 ? Date::excelToDateTimeObject($serial)->format('Y-m-d') : null;
            }
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd M Y', 'd-M-Y', 'd.m.Y'] as $format) {
                if (Carbon::canBeCreatedFromFormat($value, $format)) {
                    return Carbon::createFromFormat($format, $value)->toDateString();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return array{sheet: string, header_row: int|null, data_start_row: int|null, headers: array<string, string>, rows: array<int, array<string, array{value: string|null, formula: bool, unsafe: bool}>>} */
    private function readSheet(PayrollImport $import): array
    {
        if (! Storage::disk(self::DISK)->exists($import->stored_path)) {
            throw ValidationException::withMessages(['workbook' => 'The uploaded file is no longer available. Upload it again.']);
        }
        try {
            return $this->reader->read(Storage::disk(self::DISK)->path($import->stored_path), pathinfo($import->stored_path, PATHINFO_EXTENSION));
        } catch (Throwable) {
            throw ValidationException::withMessages(['workbook' => 'The uploaded file could not be read as a spreadsheet.']);
        }
    }

    private function assertOpenPeriod(AccountingPeriod $period): void
    {
        if ($period->status !== AccountingPeriodStatus::Open || $period->fiscalYear->status !== FiscalYearStatus::Open) {
            throw ValidationException::withMessages(['accounting_period_id' => 'The accounting period and its fiscal year must both be open.']);
        }
    }

    private function assertOpen(PayrollImport $import): void
    {
        if (! $import->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This import is '.$import->status->value.' and can no longer be changed.']);
        }
    }

    private function lock(PayrollImport $import, User $actor): PayrollImport
    {
        $import = PayrollImport::query()->with(['branch', 'period'])->lockForUpdate()->findOrFail($import->id);
        abort_unless($this->branchAccess->canAccess($actor, $import->branch), 403);

        return $import;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('payroll.import'), 403);
    }
}
