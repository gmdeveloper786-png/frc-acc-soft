<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceImport;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class OpeningBalanceImportService
{
    public function __construct(
        private OpeningBalanceService $balances,
        private OpeningBalanceValidationService $validator,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $context */
    public function preview(UploadedFile $file, array $context, User $actor): OpeningBalanceImport
    {
        $year = FiscalYear::query()->findOrFail($context['fiscal_year_id']);
        $this->validator->assertOpeningDate($year, (string) $context['opening_date']);

        $path = $file->store('opening-balance-imports', 'local');
        $import = OpeningBalanceImport::query()->create([
            ...$context, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path,
            'file_hash' => hash_file('sha256', $file->getRealPath()), 'status' => 'preview', 'created_by' => $actor->id,
        ]);
        try {
            $analysis = $this->analyseWorkbook(Storage::disk('local')->path($path));
            $import->rows()->createMany($this->persistableRows($analysis['rows']));
            $import->update(['total_debit' => $analysis['total_debit'], 'total_credit' => $analysis['total_credit'], 'difference' => $analysis['difference']]);
            $this->audit->log('opening_balance_import.previewed', $import, ['rows' => count($analysis['rows']), 'difference' => $analysis['difference']]);

            return $import->load('rows.account');
        } catch (\Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['workbook' => 'The opening balance file could not be read safely.']);
        }
    }

    /**
     * Turn a balanced preview into the draft opening balance batch. Applying
     * prepares a draft, so it needs the prepare permission as well as import;
     * the uploader stays a maker of the batch (see OpeningBalancePostingService).
     */
    public function apply(OpeningBalanceImport $import, User $actor): OpeningBalanceBatch
    {
        abort_unless($actor->can('opening_balances.import') && $actor->can('opening_balances.prepare'), 403);

        return DB::transaction(function () use ($import, $actor): OpeningBalanceBatch {
            $import = OpeningBalanceImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($import->status === 'applied') {
                throw ValidationException::withMessages(['import' => 'This import was already applied.']);
            }
            $path = Storage::disk('local')->path($import->stored_path);
            if (! is_file($path) || ! hash_equals($import->file_hash, hash_file('sha256', $path))) {
                throw ValidationException::withMessages(['import' => 'The source workbook is missing or has changed. Upload a new preview.']);
            }
            $analysis = $this->analyseWorkbook($path);
            if (collect($analysis['rows'])->contains(fn (array $row): bool => $row['validation_errors'] !== [])) {
                throw ValidationException::withMessages(['import' => 'Correct every invalid row before applying this preview.']);
            }
            if (! BigDecimal::of($analysis['difference'])->isZero()) {
                throw ValidationException::withMessages(['import' => 'Opening balance import must be balanced.']);
            }
            $batch = $this->balances->saveDraft([
                'fiscal_year_id' => $import->fiscal_year_id, 'branch_id' => $import->branch_id,
                'opening_date' => $import->opening_date->toDateString(), 'source_reference' => self::sourceReference($import->id),
                'lines' => collect($analysis['rows'])->map(fn (array $row): array => [
                    'account_id' => $row['account_id'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'source_reference' => $row['source_reference'],
                ])->all(),
            ], $actor);
            $import->update(['status' => 'applied', 'applied_at' => now()]);
            $this->audit->log('opening_balance_import.applied', $import, ['journal_posting' => 'not_performed', 'opening_balance_batch_id' => $batch->id], $actor);

            return $batch;
        });
    }

    /** The batch source reference that links a batch to the import applied to it. */
    public static function sourceReference(int $importId): string
    {
        return 'Import #'.$importId;
    }

    /**
     * Return display-safe amounts from the verified source workbook.
     *
     * Invalid decimals retain the exact uploaded text so the preview does not
     * hide a precision error behind the import-row table's two-decimal columns.
     *
     * @return array{rows:array<int, array{debit:string,credit:string}>,source_available:bool}
     */
    public function displayAmounts(OpeningBalanceImport $import): array
    {
        $path = Storage::disk('local')->path($import->stored_path);
        $currentHash = is_file($path) ? hash_file('sha256', $path) : false;
        if (! is_string($currentHash) || ! hash_equals($import->file_hash, $currentHash)) {
            return ['rows' => [], 'source_available' => false];
        }

        try {
            $analysis = $this->analyseWorkbook($path);
        } catch (\Throwable) {
            return ['rows' => [], 'source_available' => false];
        }

        return [
            'rows' => collect($analysis['rows'])->mapWithKeys(fn (array $row): array => [
                $row['row_number'] => [
                    'debit' => $this->displayAmount($row['raw_debit']),
                    'credit' => $this->displayAmount($row['raw_credit']),
                ],
            ])->all(),
            'source_available' => true,
        ];
    }

    /**
     * Uploader of the import whose rows the batch currently holds, if any.
     */
    public function uploaderOf(OpeningBalanceBatch $batch): ?int
    {
        if (! preg_match('/^Import #(\d+)$/', (string) $batch->source_reference, $matches)) {
            return null;
        }
        $createdBy = OpeningBalanceImport::query()->whereKey((int) $matches[1])->where('fiscal_year_id', $batch->fiscal_year_id)
            ->where('branch_id', $batch->branch_id)->where('status', 'applied')->value('created_by');

        return $createdBy === null ? null : (int) $createdBy;
    }

    /** @return array{rows:array<int, array<string, mixed>>,total_debit:string,total_credit:string,difference:string} */
    private function analyseWorkbook(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if ($reader instanceof CsvReader) {
            $reader->setValueBinder(new StringValueBinder);
        }
        $sheet = $reader->load($path)->getActiveSheet();
        // Stored values, not display text: a number format such as "0.00" or "#,##0" must never round an amount before validation.
        $sourceRows = $sheet->toArray(null, true, false, false);
        $headers = array_map(fn ($value): string => trim((string) $value), array_shift($sourceRows) ?: []);
        foreach (['Account Code', 'Debit', 'Credit'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages(['workbook' => "Required column '{$required}' is missing."]);
            }
        }

        $rows = [];
        $seen = [];
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();

        foreach ($sourceRows as $offset => $values) {
            $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
            $source = array_combine($headers, $values);
            $code = trim((string) ($source['Account Code'] ?? ''));
            $rawDebit = $this->amountText($source['Debit'] ?? null);
            $rawCredit = $this->amountText($source['Credit'] ?? null);
            if ($code === '' && $rawDebit === '' && $rawCredit === '') {
                continue;
            }

            $errors = [];
            $account = Account::query()->where('code', $code)->first();
            if (! $account) {
                $errors[] = 'Account code does not exist.';
            } elseif (! $this->validator->isEligibleAccount($account)) {
                $errors[] = 'Account is not an eligible active, postable Balance Sheet ledger.';
            }
            if (isset($seen[$code])) {
                $errors[] = 'Duplicate account code in file.';
            }
            $seen[$code] = true;

            try {
                if (self::isScientificNotation($rawDebit) || self::isScientificNotation($rawCredit)) {
                    throw new \InvalidArgumentException('Scientific notation');
                }
                $debit = BigDecimal::of($rawDebit ?: '0');
                $credit = BigDecimal::of($rawCredit ?: '0');
                if ($debit->getScale() > 2 || $credit->getScale() > 2) {
                    $errors[] = 'Amounts may have no more than two decimal places.';
                }
                if ($debit->isNegative() || $credit->isNegative()) {
                    $errors[] = 'Amounts cannot be negative.';
                }
                if (! $debit->isZero() && ! $credit->isZero()) {
                    $errors[] = 'Use debit or credit, not both.';
                }
                if ($debit->isZero() && $credit->isZero()) {
                    $errors[] = 'An amount is required.';
                }
            } catch (\Throwable) {
                $debit = BigDecimal::zero();
                $credit = BigDecimal::zero();
                $errors[] = self::isScientificNotation($rawDebit) || self::isScientificNotation($rawCredit)
                    ? 'Amounts must be plain decimal numbers; scientific notation such as 1e3 is not accepted.'
                    : 'Invalid decimal amount.';
            }

            if ($errors === []) {
                $totalDebit = $totalDebit->plus($debit);
                $totalCredit = $totalCredit->plus($credit);
            }
            $rows[] = [
                'row_number' => $offset + 2, 'account_code' => $code ?: null, 'account_id' => $account?->id,
                'debit' => (string) $debit, 'credit' => (string) $credit,
                'raw_debit' => $rawDebit, 'raw_credit' => $rawCredit,
                'source_reference' => trim((string) ($source['Source Reference'] ?? '')) ?: null, 'validation_errors' => $errors,
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['workbook' => 'The workbook contains no opening balance rows.']);
        }

        return [
            'rows' => $rows,
            'total_debit' => (string) $totalDebit,
            'total_credit' => (string) $totalCredit,
            'difference' => (string) $totalDebit->minus($totalCredit),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function persistableRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'row_number' => $row['row_number'],
            'account_code' => $row['account_code'],
            'account_id' => $row['account_id'],
            'debit' => $row['debit'],
            'credit' => $row['credit'],
            'source_reference' => $row['source_reference'],
            'validation_errors' => $row['validation_errors'],
        ])->all();
    }

    /**
     * The amount text to validate for one cell.
     *
     * Text (and every CSV value) is used as typed. A numeric spreadsheet cell
     * holds a binary float, so it is written back as the decimal Excel shows:
     * 15 significant digits, which recovers any amount typed with up to 15
     * digits exactly (500.999 stays 500.999, 1234567.89 stays 1234567.89).
     * When the float carries real digits beyond that, the full value is kept
     * so the amount is rejected for its precision instead of being rounded.
     */
    private function amountText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_float($value)) {
            return trim((string) $value);
        }
        if (! is_finite($value)) {
            return 'NaN';
        }

        $excel = sprintf('%.15G', $value);
        // A gap of at most one unit in the last place is float noise (0.1 + 0.2); anything larger is real precision.
        $unitInLastPlace = $value == 0.0 ? PHP_FLOAT_MIN : 2 ** (floor(log(abs($value), 2)) - 52);
        $text = abs($value - (float) $excel) <= $unitInLastPlace ? $excel : sprintf('%.17G', $value);

        // Plain notation for very large or small cells, so only text input is judged as scientific notation.
        return (string) BigDecimal::of($text);
    }

    /** Amounts like 1e3, 1E+3 or 2.5e2 typed as text. */
    private static function isScientificNotation(string $text): bool
    {
        return preg_match('/^[+-]?(\d+\.?\d*|\.\d+)[eE][+-]?\d+$/', $text) === 1;
    }

    private function displayAmount(string $raw): string
    {
        if ($raw === '') {
            return '0.00';
        }

        try {
            $amount = BigDecimal::of($raw);
        } catch (\Throwable) {
            return $raw;
        }

        if ($amount->getScale() > 2 || $amount->isNegative()) {
            return $raw;
        }

        return (string) $amount->toScale(2, RoundingMode::Unnecessary);
    }
}
