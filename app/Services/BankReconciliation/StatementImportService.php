<?php

namespace App\Services\BankReconciliation;

use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Bank statement import: Upload → Column Mapping → Validation → Lines.
 *
 * The file is stored on the private disk and identified by its SHA-256 hash,
 * so the same file cannot be imported twice for one bank account. Statement
 * contents are never logged; audit entries carry only counts and totals.
 */
class StatementImportService
{
    public const DISK = 'local';

    public const DATE_FORMATS = [
        'Y-m-d' => 'YYYY-MM-DD (2026-01-31)',
        'd/m/Y' => 'DD/MM/YYYY (31/01/2026)',
        'm/d/Y' => 'MM/DD/YYYY (01/31/2026)',
        'd-m-Y' => 'DD-MM-YYYY (31-01-2026)',
        'd.m.Y' => 'DD.MM.YYYY (31.01.2026)',
        'd M Y' => 'DD Mon YYYY (31 Jan 2026)',
        'd-M-Y' => 'DD-Mon-YYYY (31-Jan-2026)',
        'd-M-y' => 'DD-Mon-YY (31-Jan-26)',
    ];

    private const MAX_REPORTED_ERRORS = 15;

    public function __construct(private BranchAccess $branchAccess, private AuditLogger $audit, private StatementFileReader $reader) {}

    /** Store the file privately and detect its headings. No line is imported yet. */
    public function upload(UploadedFile $file, FinancialAccount $account, User $actor): BankStatement
    {
        $this->authorize($account, $actor);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, StatementFileReader::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['statement_file' => 'Upload a .csv or .xlsx bank statement.']);
        }
        $hash = (string) hash_file('sha256', $file->getRealPath());
        $this->refuseDuplicate($account, $hash);

        $path = $file->storeAs('bank-statements', Str::uuid()->toString().'.'.$extension, self::DISK);
        try {
            $sheet = $this->reader->read(Storage::disk(self::DISK)->path($path), $extension);
            if ($sheet['header_row'] === null || count($sheet['headers']) < 2) {
                throw ValidationException::withMessages(['statement_file' => 'No heading row was found. The statement needs a row of column headings above the transactions.']);
            }
            if (count(array_filter(array_keys($sheet['rows']), fn (int $row): bool => $row > $sheet['header_row'])) === 0) {
                throw ValidationException::withMessages(['statement_file' => 'The statement has headings but no transaction rows.']);
            }
        } catch (ValidationException $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        } catch (Throwable) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['statement_file' => 'The file could not be read as a CSV or Excel bank statement.']);
        }

        try {
            return DB::transaction(function () use ($file, $account, $actor, $extension, $hash, $path, $sheet): BankStatement {
                $statement = BankStatement::query()->create([
                    'financial_account_id' => $account->id, 'branch_id' => $account->branch_id, 'status' => BankStatement::PENDING,
                    'original_filename' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
                    'stored_path' => $path, 'file_extension' => $extension, 'file_hash' => $hash,
                    'header_row' => $sheet['header_row'], 'detected_headers' => $sheet['headers'], 'uploaded_by' => $actor->id,
                ]);
                $this->audit->log('bank_statement.uploaded', $statement, ['financial_account_id' => $account->id, 'file_hash' => $hash, 'columns' => count($sheet['headers'])], $actor);

                return $statement;
            });
        } catch (QueryException) {
            // A concurrent upload of the same file won the unique index.
            Storage::disk(self::DISK)->delete($path);
            $this->refuseDuplicate($account, $hash);
            throw ValidationException::withMessages(['statement_file' => 'The statement could not be stored. Try again.']);
        }
    }

    /**
     * Apply the column mapping, validate every row and import the lines.
     * Any invalid row stops the whole import; nothing partial is saved.
     *
     * @param  array<string, mixed>  $input
     */
    public function import(BankStatement $statement, array $input, User $actor): BankStatement
    {
        $account = $statement->financialAccount;
        $this->authorize($account, $actor);
        if ($statement->isImported()) {
            throw ValidationException::withMessages(['mapping' => 'This statement has already been imported.']);
        }
        $mapping = $this->mapping($statement, $input);
        $periodFrom = new DateTimeImmutable($input['period_from']);
        $periodTo = new DateTimeImmutable($input['period_to']);
        $opening = AccountingDecimal::of(Amount::parse((string) $input['opening_balance']));
        $closing = AccountingDecimal::of(Amount::parse((string) $input['closing_balance']));

        $sheet = $this->readStored($statement);
        [$lines, $errors] = $this->buildLines($sheet['rows'], (int) $statement->header_row, $mapping, $statement->file_extension, $periodFrom, $periodTo);
        if ($lines === [] && $errors === []) {
            $errors[] = 'No transaction rows with an amount were found under the mapped columns.';
        }
        if ($errors === []) {
            $errors = $this->balanceErrors($lines, $opening, $closing, $mapping['balance'] !== null);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['mapping' => $this->limitErrors($errors)]);
        }

        return DB::transaction(function () use ($statement, $account, $actor, $mapping, $lines, $periodFrom, $periodTo, $opening, $closing): BankStatement {
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->isImported()) {
                throw ValidationException::withMessages(['mapping' => 'This statement has already been imported.']);
            }
            $this->refuseOverlap($account, $periodFrom, $periodTo, $statement->id);
            $now = now();
            foreach (array_chunk($lines, 500) as $chunk) {
                BankStatementLine::query()->insert(array_map(fn (array $line): array => [
                    'bank_statement_id' => $statement->id, 'financial_account_id' => $account->id, 'row_number' => $line['row'],
                    'transaction_date' => $line['date'], 'description' => $line['description'], 'reference' => $line['reference'],
                    'amount' => AccountingDecimal::string($line['amount']),
                    'running_balance' => $line['balance'] === null ? null : AccountingDecimal::string($line['balance']),
                    'status' => BankStatementLine::UNMATCHED, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            $statement->update([
                'status' => BankStatement::IMPORTED, 'column_mapping' => $mapping,
                'period_from' => $periodFrom->format('Y-m-d'), 'period_to' => $periodTo->format('Y-m-d'),
                'opening_balance' => AccountingDecimal::string($opening), 'closing_balance' => AccountingDecimal::string($closing),
                'line_count' => count($lines), 'imported_at' => $now,
            ]);
            $deposits = array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $line['amount']->isPositive() ? $sum->plus($line['amount']) : $sum, BigDecimal::zero());
            $withdrawals = array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $line['amount']->isNegative() ? $sum->minus($line['amount']) : $sum, BigDecimal::zero());
            $this->audit->log('bank_statement.imported', $statement, [
                'financial_account_id' => $account->id, 'file_hash' => $statement->file_hash, 'lines' => count($lines),
                'period_from' => $periodFrom->format('Y-m-d'), 'period_to' => $periodTo->format('Y-m-d'),
                'total_deposits' => AccountingDecimal::string($deposits), 'total_withdrawals' => AccountingDecimal::string($withdrawals),
            ], $actor);

            return $statement->refresh();
        }, 3);
    }

    /**
     * A statement is kept for the audit trail once any reconciliation refers to
     * it (cancelled ones included) or any of its lines is matched or ignored.
     */
    public function isRetained(BankStatement $statement): bool
    {
        return BankStatementLine::query()->where('bank_statement_id', $statement->id)->where('status', '!=', BankStatementLine::UNMATCHED)->exists()
            || BankReconciliation::query()->where('bank_statement_id', $statement->id)->exists();
    }

    /** Whether the user may remove this statement now, for the screen. discard() checks everything again under its lock. */
    public function canDiscard(BankStatement $statement, User $actor): bool
    {
        return $actor->can('bank_reconciliation.prepare') && $statement->financialAccount->type === 'bank'
            && $statement->financialAccount->bookVisibleTo($actor, $this->branchAccess->canAccess($actor, $statement->financialAccount->branch))
            && ! $this->isRetained($statement);
    }

    /**
     * Abandon a pending upload, or withdraw an imported statement none of whose
     * lines has been matched or ignored. The stored file is deleted.
     */
    public function discard(BankStatement $statement, User $actor): void
    {
        $this->authorize($statement->financialAccount, $actor);
        DB::transaction(function () use ($statement, $actor): void {
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($this->isRetained($statement)) {
                throw ValidationException::withMessages(['statement' => 'This statement is used by a reconciliation and cannot be removed.']);
            }
            $this->audit->log($statement->isImported() ? 'bank_statement.withdrawn' : 'bank_statement.cancelled', $statement, [
                'financial_account_id' => $statement->financial_account_id, 'file_hash' => $statement->file_hash, 'lines' => $statement->line_count,
            ], $actor);
            BankStatementLine::query()->where('bank_statement_id', $statement->id)->delete();
            Storage::disk(self::DISK)->delete($statement->stored_path);
            $statement->delete();
        });
    }

    private function authorize(FinancialAccount $account, User $actor): void
    {
        // A shared bank account's statement holds every branch's entries, so it needs access to every branch.
        abort_unless($actor->can('bank_reconciliation.prepare') && $account->type === 'bank' && $account->bookVisibleTo($actor, $this->branchAccess->canAccess($actor, $account->branch)), 403);
    }

    private function refuseDuplicate(FinancialAccount $account, string $hash): void
    {
        if (BankStatement::query()->where('financial_account_id', $account->id)->where('file_hash', $hash)->exists()) {
            $this->audit->log('bank_statement.duplicate_refused', $account, ['financial_account_id' => $account->id, 'file_hash' => $hash]);
            throw ValidationException::withMessages(['statement_file' => 'This statement file has already been uploaded for this bank account.']);
        }
    }

    private function refuseOverlap(FinancialAccount $account, DateTimeImmutable $from, DateTimeImmutable $to, int $exceptId): void
    {
        $overlap = BankStatement::query()->where('financial_account_id', $account->id)->where('status', BankStatement::IMPORTED)->whereKeyNot($exceptId)
            ->whereDate('period_from', '<=', $to->format('Y-m-d'))->whereDate('period_to', '>=', $from->format('Y-m-d'))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['period_from' => 'The statement period overlaps a statement already imported for this bank account.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{date: string, description: string|null, reference: string|null, amount_mode: string, amount: string|null, deposit: string|null, withdrawal: string|null, balance: string|null, date_format: string}
     */
    private function mapping(BankStatement $statement, array $input): array
    {
        $headers = $statement->detected_headers ?? [];
        $mapping = [
            'date' => $input['date_column'] ?? null,
            'description' => $input['description_column'] ?? null,
            'reference' => $input['reference_column'] ?? null,
            'amount_mode' => $input['amount_mode'] ?? 'split',
            'amount' => $input['amount_mode'] === 'signed' ? ($input['amount_column'] ?? null) : null,
            'deposit' => $input['amount_mode'] === 'split' ? ($input['deposit_column'] ?? null) : null,
            'withdrawal' => $input['amount_mode'] === 'split' ? ($input['withdrawal_column'] ?? null) : null,
            'balance' => $input['balance_column'] ?? null,
            'date_format' => $input['date_format'],
        ];
        $errors = [];
        foreach (['date', 'description', 'reference', 'amount', 'deposit', 'withdrawal', 'balance'] as $field) {
            if ($mapping[$field] !== null && ! array_key_exists($mapping[$field], $headers)) {
                $errors[$field.'_column'] = 'The selected column does not exist in this file.';
            }
        }
        $used = array_filter([$mapping['date'], $mapping['description'], $mapping['reference'], $mapping['amount'], $mapping['deposit'], $mapping['withdrawal'], $mapping['balance']]);
        if (count($used) !== count(array_unique($used))) {
            $errors['mapping'] = 'Each column can be mapped to one field only.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $mapping;
    }

    /** @return array{header_row: int|null, headers: array<string, string>, rows: array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>>} */
    private function readStored(BankStatement $statement): array
    {
        if (! Storage::disk(self::DISK)->exists($statement->stored_path)) {
            throw ValidationException::withMessages(['mapping' => 'The stored statement file is missing. Cancel this upload and upload the file again.']);
        }

        try {
            return $this->reader->read(Storage::disk(self::DISK)->path($statement->stored_path), $statement->file_extension);
        } catch (Throwable) {
            throw ValidationException::withMessages(['mapping' => 'The stored statement file could not be read.']);
        }
    }

    /**
     * @param  array<int, array<string, array{value: string|null, formula: bool, numeric: bool}>>  $rows
     * @param  array<string, string|null>  $mapping
     * @return array{0: list<array{row: int, date: string, description: string|null, reference: string|null, amount: BigDecimal, balance: BigDecimal|null}>, 1: list<string>}
     */
    private function buildLines(array $rows, int $headerRow, array $mapping, string $extension, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $lines = [];
        $errors = [];
        foreach ($rows as $number => $cells) {
            if ($number <= $headerRow) {
                continue;
            }
            $cell = fn (?string $letter): ?array => $letter === null ? null : ($cells[$letter] ?? null);
            foreach (['date', 'amount', 'deposit', 'withdrawal', 'balance'] as $field) {
                if (($cell($mapping[$field])['formula'] ?? false) === true) {
                    $errors[] = "Row {$number}: the {$field} column holds a formula. Export the statement with values only.";

                    continue 2;
                }
            }

            [$amount, $amountError] = $this->rowAmount($cell, $mapping);
            if ($amountError !== null) {
                $errors[] = "Row {$number}: {$amountError}";

                continue;
            }
            if ($amount === null || $amount->isZero()) {
                // Balance brought forward, sub-total and blank rows carry no transaction.
                continue;
            }
            $date = $this->rowDate($cell($mapping['date']), $mapping['date_format'], $extension);
            if ($date === null) {
                $errors[] = "Row {$number}: the date is missing or does not match the selected date format.";

                continue;
            }
            if ($date < $from || $date > $to) {
                $errors[] = "Row {$number}: the date is outside the statement period.";

                continue;
            }
            $balance = null;
            if ($mapping['balance'] !== null) {
                $balance = Amount::parse($cell($mapping['balance'])['value'] ?? null);
                if ($balance === null) {
                    $errors[] = "Row {$number}: the running balance is missing or is not an amount with at most two decimals.";

                    continue;
                }
            }
            $lines[] = [
                'row' => $number, 'date' => $date->format('Y-m-d'),
                'description' => $this->text($cell($mapping['description'])['value'] ?? null, 500),
                'reference' => $this->text($cell($mapping['reference'])['value'] ?? null, 191),
                'amount' => $amount, 'balance' => $balance,
            ];
        }

        return [$lines, $errors];
    }

    /**
     * @param  callable(?string): ?array{value: string|null, formula: bool, numeric: bool}  $cell
     * @param  array<string, string|null>  $mapping
     * @return array{0: BigDecimal|null, 1: string|null}
     */
    private function rowAmount(callable $cell, array $mapping): array
    {
        if ($mapping['amount_mode'] === 'signed') {
            $text = $cell($mapping['amount'])['value'] ?? null;
            if ($text === null) {
                return [null, null];
            }
            $amount = Amount::parse($text);

            return $amount === null ? [null, 'the amount is not a number with at most two decimals.'] : [$amount, null];
        }

        $depositText = $cell($mapping['deposit'])['value'] ?? null;
        $withdrawalText = $cell($mapping['withdrawal'])['value'] ?? null;
        if ($depositText === null && $withdrawalText === null) {
            return [null, null];
        }
        $deposit = $depositText === null ? BigDecimal::zero() : Amount::parse($depositText);
        $withdrawal = $withdrawalText === null ? BigDecimal::zero() : Amount::parse($withdrawalText);
        if ($deposit === null || $withdrawal === null) {
            return [null, 'the deposit or withdrawal is not a number with at most two decimals.'];
        }
        if ($deposit->isNegative() || $withdrawal->isNegative()) {
            return [null, 'deposit and withdrawal columns must hold positive amounts.'];
        }
        if (! $deposit->isZero() && ! $withdrawal->isZero()) {
            return [null, 'a row cannot hold both a deposit and a withdrawal.'];
        }

        return [$deposit->minus($withdrawal), null];
    }

    /** @param array{value: string|null, formula: bool, numeric: bool}|null $cell */
    private function rowDate(?array $cell, string $format, string $extension): ?DateTimeImmutable
    {
        $value = $cell['value'] ?? null;
        if ($value === null) {
            return null;
        }
        if ($extension === 'xlsx' && ($cell['numeric'] ?? false) && is_numeric($value)) {
            try {
                return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value))->setTime(0, 0);
            } catch (Throwable) {
                return null;
            }
        }
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $problems = DateTimeImmutable::getLastErrors();
        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function text(?string $value, int $limit): ?string
    {
        return $value === null ? null : Str::limit($value, $limit - 3);
    }

    /**
     * The statement must agree with itself: opening + lines = closing, and a
     * mapped running balance must follow the amounts row by row.
     *
     * @param  list<array{row: int, date: string, description: string|null, reference: string|null, amount: BigDecimal, balance: BigDecimal|null}>  $lines
     * @return list<string>
     */
    private function balanceErrors(array $lines, BigDecimal $opening, BigDecimal $closing, bool $hasBalance): array
    {
        $total = array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $sum->plus($line['amount']), BigDecimal::zero());
        $errors = [];
        if ($opening->plus($total)->compareTo($closing) !== 0) {
            $errors[] = 'Opening balance '.Amount::format($opening).' plus the imported lines ('.Amount::format($total).') does not equal the closing balance '.Amount::format($closing).'. Check the balances and the column mapping.';
        }
        if ($hasBalance && ! $this->runningBalanceFollows($lines, $opening) && ! $this->runningBalanceFollows(array_reverse($lines), $opening)) {
            $errors[] = 'The running balance column does not follow the imported amounts. Check the deposit, withdrawal and balance columns.';
        }

        return $errors;
    }

    /** @param list<array{amount: BigDecimal, balance: BigDecimal|null}> $lines */
    private function runningBalanceFollows(array $lines, BigDecimal $opening): bool
    {
        $running = $opening;
        foreach ($lines as $line) {
            $running = $running->plus($line['amount']);
            if ($line['balance'] === null || $running->compareTo($line['balance']) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function limitErrors(array $errors): array
    {
        $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
        if (count($errors) > self::MAX_REPORTED_ERRORS) {
            $shown[] = (count($errors) - self::MAX_REPORTED_ERRORS).' more rows have errors.';
        }

        return $shown;
    }
}
