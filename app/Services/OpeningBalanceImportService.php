<?php

namespace App\Services;

use App\Models\Account;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceImport;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OpeningBalanceImportService
{
    public function __construct(private OpeningBalanceService $balances, private AuditLogger $audit) {}

    /** @param array<string, mixed> $context */
    public function preview(UploadedFile $file, array $context, User $actor): OpeningBalanceImport
    {
        $path = $file->store('opening-balance-imports', 'local');
        $import = OpeningBalanceImport::query()->create([
            ...$context, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path,
            'file_hash' => hash_file('sha256', $file->getRealPath()), 'status' => 'preview', 'created_by' => $actor->id,
        ]);
        try {
            $sheet = IOFactory::load(Storage::disk('local')->path($path))->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
            $headers = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?: []);
            foreach (['Account Code', 'Debit', 'Credit'] as $required) {
                if (! in_array($required, $headers, true)) {
                    throw ValidationException::withMessages(['workbook' => "Required column '{$required}' is missing."]);
                }
            }
            $seen = [];
            $totalDebit = BigDecimal::zero();
            $totalCredit = BigDecimal::zero();
            foreach ($rows as $offset => $values) {
                $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
                $source = array_combine($headers, $values);
                $code = trim((string) ($source['Account Code'] ?? ''));
                if ($code === '' && trim((string) ($source['Debit'] ?? '')) === '' && trim((string) ($source['Credit'] ?? '')) === '') {
                    continue;
                }
                $errors = [];
                $account = Account::query()->where('code', $code)->first();
                if (! $account) {
                    $errors[] = 'Account code does not exist.';
                } elseif (! $account->is_active || ! $account->is_postable || $account->level->value !== 'ledger') {
                    $errors[] = 'Account is not an active postable ledger.';
                }
                if (isset($seen[$code])) {
                    $errors[] = 'Duplicate account code in file.';
                }
                $seen[$code] = true;
                try {
                    $debit = BigDecimal::of(trim((string) ($source['Debit'] ?? '')) ?: '0');
                    $credit = BigDecimal::of(trim((string) ($source['Credit'] ?? '')) ?: '0');
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
                    $errors[] = 'Invalid decimal amount.';
                }
                if ($errors === []) {
                    $totalDebit = $totalDebit->plus($debit);
                    $totalCredit = $totalCredit->plus($credit);
                }
                $import->rows()->create([
                    'row_number' => $offset + 2, 'account_code' => $code ?: null, 'account_id' => $account?->id,
                    'debit' => (string) $debit, 'credit' => (string) $credit,
                    'source_reference' => trim((string) ($source['Source Reference'] ?? '')) ?: null, 'validation_errors' => $errors,
                ]);
            }
            $difference = $totalDebit->minus($totalCredit);
            $import->update(['total_debit' => (string) $totalDebit, 'total_credit' => (string) $totalCredit, 'difference' => (string) $difference]);
            $this->audit->log('opening_balance_import.previewed', $import, ['rows' => $import->rows()->count(), 'difference' => (string) $difference]);

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
            $rows = $import->rows()->get();
            if ($rows->contains(fn ($row) => ! empty($row->validation_errors))) {
                throw ValidationException::withMessages(['import' => 'Correct every invalid row before applying this preview.']);
            }
            if (BigDecimal::of($import->difference)->compareTo(BigDecimal::zero()) !== 0) {
                throw ValidationException::withMessages(['import' => 'Opening balance import must be balanced.']);
            }
            $batch = $this->balances->saveDraft([
                'fiscal_year_id' => $import->fiscal_year_id, 'branch_id' => $import->branch_id,
                'opening_date' => $import->opening_date->toDateString(), 'source_reference' => self::sourceReference($import->id),
                'lines' => $rows->map(fn ($row) => ['account_id' => $row->account_id, 'debit' => $row->debit, 'credit' => $row->credit, 'source_reference' => $row->source_reference])->all(),
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
}
