<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialStatement;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\AccountImport;
use App\Models\AccountImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AccountImportService
{
    public function __construct(private AccountService $accounts, private AuditLogger $audit) {}

    public function preview(UploadedFile $file, User $actor): AccountImport
    {
        $path = $file->store('account-imports', 'local');
        $import = AccountImport::query()->create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => 'preview',
            'created_by' => $actor->id,
        ]);

        try {
            $spreadsheet = IOFactory::load(Storage::disk('local')->path($path));
            $sheet = $spreadsheet->getSheetByName('Chart of Accounts');
            if (! $sheet) {
                throw ValidationException::withMessages(['workbook' => 'Worksheet "Chart of Accounts" was not found.']);
            }
            $rows = $sheet->toArray(null, true, true, false);
            // Release the workbook's circular references before validating rows.
            $spreadsheet->disconnectWorksheets();
            unset($sheet, $spreadsheet);
            $headers = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?: []);
            $required = ['Account Code', 'Account Name', 'Level', 'Parent Code', 'Account Type', 'Normal Balance', 'Financial Statement', 'Entity', 'Posting Allowed', 'Notes'];
            foreach ($required as $header) {
                if (! in_array($header, $headers, true)) {
                    throw ValidationException::withMessages(['workbook' => "Required column '{$header}' is missing."]);
                }
            }

            $mapped = [];
            foreach ($rows as $offset => $values) {
                $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
                $source = array_combine($headers, $values);
                if (! $source || collect($source)->every(fn ($value) => trim((string) $value) === '')) {
                    continue;
                }
                $mapped[] = $this->mapRow($source, $offset + 2);
            }

            $duplicates = collect($mapped)->pluck('code')->filter()->countBy()->filter(fn (int $count) => $count > 1)->keys()->map(fn ($code): string => (string) $code)->all();
            $sourceByCode = collect($mapped)->keyBy('code');
            $validated = [];
            foreach ($mapped as $row) {
                $errors = $row['validation_errors'];
                if (in_array($row['code'], $duplicates, true)) {
                    $errors[] = 'Duplicate account code in workbook.';
                }
                if ($row['parent_code']) {
                    $parent = $sourceByCode->get($row['parent_code']);
                    $existingParent = Account::query()->where('code', $row['parent_code'])->first();
                    if (! $parent && ! $existingParent) {
                        $errors[] = 'Parent code does not exist in workbook or current chart.';
                    } elseif ($parent && ($parent['account_type'] !== $row['account_type'] || $parent['financial_statement'] !== $row['financial_statement'])) {
                        $errors[] = 'Parent classification does not match.';
                    } elseif ($parent && $row['level'] && $parent['level'] !== AccountLevel::from($row['level'])->parentLevel()?->value) {
                        $errors[] = 'Parent is not at the required hierarchy level.';
                    } elseif ($existingParent && $row['level'] && $existingParent->level !== AccountLevel::from($row['level'])->parentLevel()) {
                        $errors[] = 'Existing parent is not at the required hierarchy level.';
                    }
                }
                $validated[] = [...$row, 'validation_errors' => array_values(array_unique($errors))];
            }
            foreach ($this->classifyRows($validated) as $index => $classification) {
                $import->rows()->create([...$validated[$index], ...$classification]);
            }

            $summary = $this->summary($import->rows()->get());
            $import->update(['summary' => $summary]);
            $this->audit->log('account_import.previewed', $import, $summary);

            return $import->load('rows');
        } catch (\Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['workbook' => 'The workbook could not be read safely. Please verify its format.']);
        }
    }

    /**
     * Record the reviewer's explicit decision for the selected rows. Nothing
     * is imported here.
     *
     * @param  list<int>  $rowIds
     */
    public function decide(AccountImport $import, array $rowIds, string $decision, ?string $notes, User $actor): int
    {
        abort_unless($actor->can('accounts.import'), 403);
        if ($import->status === 'confirmed') {
            throw ValidationException::withMessages(['rows' => 'This preview has already been imported and can no longer be reviewed.']);
        }
        $this->ensureClassified($import);
        if (! in_array($decision, ['approved', 'excluded', 'pending'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve, exclude or reset.']);
        }
        $rows = $import->rows()->whereKey($rowIds)->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Select at least one row.']);
        }
        $notes = trim((string) $notes) ?: null;
        $all = $import->rows()->get()->keyBy('code');
        $selectedCodes = $rows->pluck('code');

        if ($decision === 'approved') {
            if ($rows->contains(fn (AccountImportRow $row): bool => filled($row->validation_errors))) {
                throw ValidationException::withMessages(['rows' => 'Structurally invalid rows cannot be approved.']);
            }
            if ($notes === null && $rows->contains(fn (AccountImportRow $row): bool => $row->needsDocumentedApproval())) {
                throw ValidationException::withMessages(['notes' => 'School-only, manual-review and other flagged rows need a documented approval reason.']);
            }
            foreach ($rows as $row) {
                $parent = $row->parent_code ? $all->get($row->parent_code) : null;
                $parentReady = ! $row->parent_code || Account::query()->where('code', $row->parent_code)->exists()
                    || ($parent && ($parent->review_status === 'approved' || $selectedCodes->contains($parent->code)));
                if (! $parentReady) {
                    throw ValidationException::withMessages(['rows' => "Approve required parent {$row->parent_code} together with or before {$row->code}."]);
                }
            }
        } else {
            $dependants = $all->filter(fn (AccountImportRow $row): bool => $row->review_status === 'approved' && $selectedCodes->contains($row->parent_code) && ! $selectedCodes->contains($row->code));
            if ($dependants->isNotEmpty()) {
                throw ValidationException::withMessages(['rows' => 'Approved child accounts still depend on a selected parent: '.$dependants->pluck('code')->take(5)->implode(', ').'.']);
            }
        }

        return DB::transaction(function () use ($import, $rows, $decision, $notes, $actor): int {
            foreach ($rows as $row) {
                $row->update(['review_status' => $decision, 'review_notes' => $decision === 'pending' ? null : $notes, 'reviewed_by' => $decision === 'pending' ? null : $actor->id, 'reviewed_at' => $decision === 'pending' ? null : now()]);
            }
            $import->update(['summary' => $this->summary($import->rows()->get())]);
            $this->audit->log('account_import.rows_reviewed', $import, ['decision' => $decision, 'rows' => $rows->count(), 'codes' => $rows->pluck('code')->take(50)->all()]);

            return $rows->count();
        });
    }

    /**
     * Classify previews that were created before review decisions existed.
     * Called only from the write actions (review decision and confirmation);
     * previews are classified at upload and the GET pages stay read-only.
     */
    public function ensureClassified(AccountImport $import): void
    {
        $rows = $import->rows()->get();
        if ($rows->isEmpty() || $rows->every(fn (AccountImportRow $row): bool => $row->classification !== null)) {
            return;
        }
        $source = $rows->map(fn (AccountImportRow $row): array => $row->only(['code', 'name', 'level', 'parent_code', 'source_entity', 'notes', 'validation_errors']))->values()->all();
        foreach ($this->classifyRows($source) as $index => $classification) {
            $rows[$index]->update($classification);
        }
        $import->update(['summary' => $this->summary($import->rows()->get())]);
    }

    /**
     * Rows of the approval summary export, including each account's hierarchy path.
     *
     * @return list<array<string, string>>
     */
    public function approvalSummary(AccountImport $import): array
    {
        $rows = $import->rows()->get();
        $byCode = $rows->keyBy('code');

        return $rows->map(fn (AccountImportRow $row): array => [
            'Account Code' => (string) $row->code,
            'Account Name' => (string) $row->name,
            'Level' => str((string) $row->level)->replace('_', ' ')->title()->toString(),
            'Parent Code' => (string) $row->parent_code,
            'Hierarchy' => $this->hierarchyPath($row, $byCode),
            'Account Type' => ucfirst((string) $row->account_type),
            'Normal Balance' => ucfirst((string) $row->normal_balance),
            'Posting Allowed' => $row->is_postable ? 'Yes' : 'No',
            'Source Entity' => (string) $row->source_entity,
            'Classification' => str((string) $row->classification)->replace('_', ' ')->title()->toString(),
            'Manual Review' => $row->requires_manual_review ? 'Yes' : 'No',
            'Already In Chart' => $row->is_existing ? 'Yes' : 'No',
            'Proposed Decision' => ucfirst((string) $row->proposed_decision),
            'Reason / Notes' => (string) $row->proposed_reason,
            'Structural Errors' => implode(' ', $row->validation_errors ?? []),
            'Approval Status' => ucfirst((string) $row->review_status),
            'Reviewer Notes' => (string) $row->review_notes,
            'Client Decision (Approve / Exclude)' => '',
        ])->all();
    }

    /** @param Collection<string, AccountImportRow> $byCode */
    public function hierarchyPath(AccountImportRow $row, Collection $byCode): string
    {
        $path = [];
        $seen = [];
        $current = $row;
        while ($current && ! isset($seen[$current->code])) {
            $seen[$current->code] = true;
            array_unshift($path, $current->name);
            $current = $current->parent_code ? $byCode->get($current->parent_code) : null;
        }

        return implode(' › ', $path);
    }

    /** @param list<int> $rowIds */
    public function confirm(AccountImport $import, array $rowIds, User $actor): int
    {
        if ($import->status === 'confirmed') {
            return 0;
        }
        $this->ensureClassified($import);
        $query = $rowIds === [] ? $import->rows()->where('review_status', 'approved') : $import->rows()->whereKey($rowIds);
        $rows = $query->reorder()->orderByRaw("CASE level WHEN 'class' THEN 1 WHEN 'group' THEN 2 WHEN 'sub_group' THEN 3 ELSE 4 END")->orderBy('row_number')->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Select or approve at least one valid row to import.']);
        }
        if ($rows->contains(fn (AccountImportRow $row) => filled($row->validation_errors))) {
            throw ValidationException::withMessages(['rows' => 'Invalid rows cannot be imported.']);
        }
        if ($rows->contains(fn (AccountImportRow $row): bool => $row->review_status === 'excluded')) {
            throw ValidationException::withMessages(['rows' => 'Excluded rows cannot be imported. Reset the decision first.']);
        }
        $undocumented = $rows->filter(fn (AccountImportRow $row): bool => ! $row->is_existing && $row->needsDocumentedApproval() && $row->review_status !== 'approved');
        if ($undocumented->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'These flagged rows need an explicit documented approval before import: '.$undocumented->pluck('code')->take(8)->implode(', ').'.']);
        }
        $selectedCodes = $rows->pluck('code');
        foreach ($rows as $row) {
            if ($row->parent_code && ! Account::query()->where('code', $row->parent_code)->exists() && ! $selectedCodes->contains($row->parent_code)) {
                throw ValidationException::withMessages(['rows' => "Select parent {$row->parent_code} before importing {$row->code}."]);
            }
        }

        return DB::transaction(function () use ($import, $rows, $actor): int {
            $created = 0;
            foreach ($rows as $row) {
                $row->update(['is_selected' => true, 'review_status' => 'approved', 'reviewed_by' => $row->reviewed_by ?? $actor->id, 'reviewed_at' => $row->reviewed_at ?? now()]);
                if (Account::query()->where('code', $row->code)->exists()) {
                    continue;
                }
                $parentId = $row->parent_code ? Account::query()->where('code', $row->parent_code)->value('id') : null;
                $this->accounts->create([
                    'code' => $row->code, 'name' => $row->name, 'parent_id' => $parentId,
                    'account_type' => $row->account_type, 'level' => $row->level,
                    'normal_balance' => $row->normal_balance, 'financial_statement' => $row->financial_statement,
                    'is_postable' => $row->is_postable, 'is_active' => true,
                    'description' => $row->notes, 'sort_order' => $row->row_number,
                ], $actor);
                $created++;
            }
            $import->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'summary' => $this->summary($import->rows()->get())]);
            $this->audit->log('account_import.confirmed', $import, ['created' => $created, 'selected' => $rows->count()]);

            return $created;
        });
    }

    /** @param array<string, mixed> $source */
    private function mapRow(array $source, int $rowNumber): array
    {
        $code = trim((string) $source['Account Code']);
        $name = trim((string) $source['Account Name']);
        $level = match (strtolower(trim((string) $source['Level']))) {
            'class' => AccountLevel::ClassLevel->value, 'group' => AccountLevel::Group->value,
            'sub-group', 'sub group', 'subgroup' => AccountLevel::SubGroup->value, 'ledger' => AccountLevel::Ledger->value,
            default => null,
        };
        $type = match (strtolower(trim((string) $source['Account Type']))) {
            'asset', 'assets' => AccountType::Asset->value, 'liability', 'liabilities' => AccountType::Liability->value,
            'fund / equity', 'fund/equity', 'equity' => AccountType::Equity->value, 'income', 'revenue' => AccountType::Income->value,
            'expense', 'expenses' => AccountType::Expense->value, default => null,
        };
        $normal = match (strtolower(trim((string) $source['Normal Balance']))) {
            'dr', 'debit' => NormalBalance::Debit->value, 'cr', 'credit' => NormalBalance::Credit->value, default => null
        };
        $statement = match (strtolower(trim((string) $source['Financial Statement']))) {
            'balance sheet' => FinancialStatement::BalanceSheet->value, 'income & expenditure', 'income and expenditure' => FinancialStatement::IncomeExpenditure->value, default => null
        };
        $postingRaw = strtolower(trim((string) $source['Posting Allowed']));
        $posting = match ($postingRaw) {
            'yes', 'y', '1', 'true' => true, 'no', 'n', '0', 'false' => false, default => null
        };
        $errors = [];
        if ($code === '') {
            $errors[] = 'Account code is required.';
        }
        if ($name === '') {
            $errors[] = 'Account name is required.';
        }
        if (! $level) {
            $errors[] = 'Invalid account level.';
        }
        if (! $type) {
            $errors[] = 'Invalid account type.';
        }
        if (! $normal) {
            $errors[] = 'Invalid normal balance.';
        }
        if (! $statement) {
            $errors[] = 'Invalid financial statement.';
        }
        if ($posting === null) {
            $errors[] = 'Invalid posting flag.';
        }
        if ($posting === true && $level !== AccountLevel::Ledger->value) {
            $errors[] = 'Only ledger rows may allow posting.';
        }
        $parentCode = trim((string) $source['Parent Code']) ?: null;
        if ($level === AccountLevel::ClassLevel->value && $parentCode) {
            $errors[] = 'Class row cannot have a parent.';
        }
        if ($level && $level !== AccountLevel::ClassLevel->value && ! $parentCode) {
            $errors[] = 'Parent code is required.';
        }
        $entity = trim((string) $source['Entity']);
        $notes = trim((string) $source['Notes']);
        $review = [];
        if ($entity !== '' && strcasecmp($entity, 'Both') !== 0) {
            $review[] = "Legacy entity classification: {$entity}.";
        }
        if (str_contains(strtolower($notes), '[confirm]')) {
            $review[] = 'Client confirmation is requested in source notes.';
        }
        if (str_contains(strtolower($name.' '.$notes), 'inter-unit')) {
            $review[] = 'Legacy inter-unit account requires review.';
        }
        if (preg_match('/owner|private/i', $name.' '.$notes)) {
            $review[] = 'Owner/private account requires review.';
        }

        return [
            'row_number' => $rowNumber, 'code' => $code ?: null, 'name' => $name ?: null, 'level' => $level,
            'parent_code' => $parentCode, 'account_type' => $type, 'normal_balance' => $normal,
            'financial_statement' => $statement, 'source_entity' => $entity ?: null, 'is_postable' => $posting,
            'notes' => $notes ?: null, 'validation_errors' => $errors, 'review_reasons' => $review,
            'is_existing' => $code !== '' && Account::query()->where('code', $code)->exists(), 'is_selected' => false,
        ];
    }

    private function summary($rows): array
    {
        return [
            'total_rows' => $rows->count(),
            'valid_rows' => $rows->filter(fn ($row) => empty($row->validation_errors))->count(),
            'invalid_rows' => $rows->filter(fn ($row) => ! empty($row->validation_errors))->count(),
            'duplicate_rows' => $rows->filter(fn ($row) => collect($row->validation_errors)->contains(fn ($error) => str_contains($error, 'Duplicate')))->count(),
            'already_existing' => $rows->where('is_existing', true)->count(),
            'proposed_new' => $rows->where('is_existing', false)->filter(fn ($row) => empty($row->validation_errors))->count(),
            'excluded_unselected' => $rows->where('is_selected', false)->count(),
            'manual_review' => $rows->filter(fn ($row) => ! empty($row->review_reasons))->count(),
            'classification' => collect(['shared', 'rehab', 'school_only', 'unclassified'])->mapWithKeys(fn (string $class): array => [$class => [
                'rows' => $rows->where('classification', $class)->count(),
                'postable' => $rows->where('classification', $class)->where('is_postable', true)->count(),
            ]])->all(),
            'manual_review_flagged' => $rows->where('requires_manual_review', true)->count(),
            'postable_ledgers' => $rows->where('is_postable', true)->count(),
            'proposed' => collect(['include', 'review', 'exclude'])->mapWithKeys(fn (string $decision): array => [$decision => $rows->where('proposed_decision', $decision)->count()])->all(),
            'review' => collect(['pending', 'approved', 'excluded'])->mapWithKeys(fn (string $status): array => [$status => $rows->where('review_status', $status)->count()])->all(),
        ];
    }

    /**
     * Propose a treatment for every row. A proposal is never an approval:
     * each row stays pending until an authorized reviewer decides.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{classification: string, requires_manual_review: bool, proposed_decision: string, proposed_reason: string}>
     */
    private function classifyRows(array $rows): array
    {
        $entityClass = fn (array $row): string => match (strtolower(trim((string) $row['source_entity']))) {
            'both' => 'shared', 'rehab centre', 'rehab center', 'rehab' => 'rehab', 'school' => 'school_only', default => 'unclassified',
        };
        $children = [];
        foreach ($rows as $index => $row) {
            if ($row['parent_code']) {
                $children[$row['parent_code']][] = $index;
            }
        }
        $descendantLedgers = function (array $row) use ($rows, $children): array {
            $ledgers = [];
            $seen = [];
            $stack = $children[$row['code']] ?? [];
            while ($stack !== []) {
                $index = array_pop($stack);
                if (isset($seen[$index])) {
                    continue;
                }
                $seen[$index] = true;
                if ($rows[$index]['level'] === AccountLevel::Ledger->value) {
                    $ledgers[] = $rows[$index];
                }
                array_push($stack, ...($children[$rows[$index]['code']] ?? []));
            }

            return $ledgers;
        };
        $isInterUnit = fn (array $row): bool => str_contains(strtolower($row['name'].' '.$row['notes']), 'inter-unit');

        $result = [];
        foreach ($rows as $row) {
            $class = $entityClass($row);
            $text = strtolower($row['name'].' '.$row['notes']);
            $ledgers = $row['level'] === AccountLevel::Ledger->value ? [] : $descendantLedgers($row);
            $descendants = array_map($entityClass, $ledgers);
            // A heading is never proposed for exclusion while ledgers that do not depend on the two-unit model need it as their parent.
            $independent = array_values(array_filter($ledgers, fn (array $ledger): bool => ! $isInterUnit($ledger) && blank($ledger['validation_errors'])));
            [$manual, $decision, $reason] = match (true) {
                filled($row['validation_errors']) => [false, 'exclude', 'Structurally invalid row; it cannot be imported until the workbook is corrected.'],
                $isInterUnit($row) && $independent !== [] => [true, 'include', 'Heading named for inter-unit balances, but it is also the parent of '.implode(', ', array_column($independent, 'code')).', which do not depend on the former two-unit model. Import it unchanged with any of them; a heading cannot be posted to. Only its inter-unit ledgers are proposed for exclusion.'],
                $isInterUnit($row) => [true, 'exclude', 'Inter-unit balance between the former School and Rehab Centre entities; it conflicts with the approved single-organisation model.'],
                (bool) preg_match('/owner|private/', $text) => [true, 'review', 'Owner/private-use account; confirm with the accountant whether it applies to FRC.'],
                str_contains(strtolower((string) $row['notes']), '[confirm]') => [true, 'review', 'Workbook requests confirmation: '.trim((string) $row['notes'])],
                $class === 'school_only' => [false, 'review', 'Ledger of the former separate School entity. School Readiness Program is a normal FRC service, so this row is not excluded by name; approve it only if the accountant confirms FRC uses it, otherwise exclude it.'],
                $descendants !== [] && collect($descendants)->every(fn (string $value): bool => $value === 'school_only') => [false, 'review', 'Hierarchy node whose ledgers all belong to the former separate School entity; required only if one of those ledgers is approved.'],
                $class === 'rehab' => [false, 'include', 'Rehab Centre ledger used by FRC operations; subject to accountant approval.'],
                $class === 'shared' => [false, 'include', 'Shared FRC account; subject to accountant approval.'],
                default => [false, 'review', 'Source entity is not recognised.'],
            };
            $result[] = ['classification' => $class, 'requires_manual_review' => $manual, 'proposed_decision' => $decision, 'proposed_reason' => $reason];
        }

        return $result;
    }
}
