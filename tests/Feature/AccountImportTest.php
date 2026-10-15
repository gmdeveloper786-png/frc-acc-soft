<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\AccountImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->actingAs($this->admin);
});

function clientCoaUpload(): UploadedFile
{
    return new UploadedFile(base_path('docs/Chart-of-Accounts.xlsx'), 'Chart-of-Accounts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function coaRowsUpload(array $rows): UploadedFile
{
    $headers = ['Account Code', 'Account Name', 'Level', 'Parent Code', 'Account Type', 'Normal Balance', 'Financial Statement', 'Entity', 'Posting Allowed', 'Notes'];
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Chart of Accounts')->fromArray([$headers, ...$rows]);
    $path = tempnam(sys_get_temp_dir(), 'coa-test-').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'test-coa.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('client workbook creates a safe preview without importing accounts', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    expect($import->summary['total_rows'])->toBe(226)
        ->and($import->summary['manual_review'])->toBeGreaterThan(0)
        ->and($import->summary['excluded_unselected'])->toBe(226)
        ->and(Account::query()->count())->toBe(0)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0);
});

test('reviewed selection imports hierarchy and repeat confirmation is idempotent', function () {
    $service = app(AccountImportService::class);
    $import = $service->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->whereJsonLength('validation_errors', 0)->orderBy('row_number')->limit(4)->pluck('id')->all();
    $service->decide($import, $rows, 'approved', 'Accountant approved these rows for the FRC chart', $this->admin);
    expect($service->confirm($import, $rows, $this->admin))->toBe(4)
        ->and(Account::query()->count())->toBe(4)
        ->and($service->confirm($import->fresh(), $rows, $this->admin))->toBe(0);
});

test('legacy school and entity-specific rows remain unselected for manual review', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    $legacy = $import->rows()->whereNotNull('review_reasons')->get();
    expect($legacy)->not->toBeEmpty()->and($legacy->every(fn ($row) => ! $row->is_selected))->toBeTrue();
});

test('duplicate and invalid workbook rows are blocked from confirmation', function () {
    $upload = coaRowsUpload([
        ['100000', 'Assets', 'Class', '', 'Asset', 'Dr', 'Balance Sheet', 'Both', 'No', ''],
        ['100000', 'Duplicate Assets', 'Class', '', 'Asset', 'Dr', 'Balance Sheet', 'Both', 'Yes', ''],
    ]);
    $import = app(AccountImportService::class)->preview($upload, $this->admin);
    expect($import->summary['duplicate_rows'])->toBe(2)->and($import->summary['invalid_rows'])->toBe(2);
    app(AccountImportService::class)->confirm($import, $import->rows->pluck('id')->all(), $this->admin);
})->throws(ValidationException::class);

test('a second workbook confirmation skips codes that already exist', function () {
    $service = app(AccountImportService::class);
    $first = $service->preview(clientCoaUpload(), $this->admin);
    $firstRows = $first->rows()->whereJsonLength('validation_errors', 0)->orderBy('row_number')->limit(4)->pluck('id')->all();
    $service->decide($first, $firstRows, 'approved', 'Accountant approved these rows for the FRC chart', $this->admin);
    $service->confirm($first, $firstRows, $this->admin);
    $second = $service->preview(clientCoaUpload(), $this->admin);
    $secondRows = $second->rows()->whereIn('code', Account::query()->pluck('code'))->pluck('id')->all();
    expect($service->confirm($second, $secondRows, $this->admin))->toBe(0)->and(Account::query()->count())->toBe(4);
});

test('client workbook is classified exactly as the approval summary states', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    $summary = $import->summary;

    expect($summary['classification']['shared'])->toBe(['rows' => 174, 'postable' => 115])
        ->and($summary['classification']['rehab'])->toBe(['rows' => 25, 'postable' => 25])
        ->and($summary['classification']['school_only'])->toBe(['rows' => 27, 'postable' => 27])
        ->and($summary['classification']['unclassified']['rows'])->toBe(0)
        ->and($summary['manual_review_flagged'])->toBe(11)
        ->and($summary['postable_ledgers'])->toBe(167)
        ->and($summary['invalid_rows'])->toBe(0)
        ->and($summary['review'])->toBe(['pending' => 226, 'approved' => 0, 'excluded' => 0])
        ->and(Account::query()->count())->toBe(0);
});

test('school rows are proposed for review and never excluded because of their name', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->get()->keyBy('code');

    expect($import->rows()->where('classification', 'school_only')->where('proposed_decision', '!=', 'review')->count())->toBe(0)
        ->and($rows['41102']->proposed_decision)->toBe('review')
        ->and($rows['41']->proposed_decision)->toBe('review')
        ->and($rows['12503']->proposed_decision)->toBe('include')
        ->and($rows['42102']->proposed_decision)->toBe('include')
        ->and($rows['11602']->proposed_decision)->toBe('exclude')
        ->and($rows['11602']->requires_manual_review)->toBeTrue()
        ->and($rows['43102']->requires_manual_review)->toBeTrue()
        ->and($import->rows()->where('review_status', '!=', 'pending')->count())->toBe(0);
});

test('every postable ledger has a complete valid parent hierarchy', function () {
    $service = app(AccountImportService::class);
    $import = $service->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->get()->keyBy('code');

    foreach ($rows->where('is_postable', true) as $ledger) {
        $levels = [];
        for ($current = $ledger; $current; $current = $current->parent_code ? $rows->get($current->parent_code) : null) {
            $levels[] = $current->level;
        }
        expect($levels)->toBe(['ledger', 'sub_group', 'group', 'class']);
    }
    expect($service->hierarchyPath($rows['42102'], $rows))->toBe('INCOME › Rehab Centre Income › Service Income › Therapy Session Fees');
});

test('a ledger cannot be approved before its parents and a broken hierarchy is flagged', function () {
    $service = app(AccountImportService::class);
    $import = $service->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->get()->keyBy('code');

    expect(fn () => $service->decide($import, [$rows['21101']->id], 'approved', null, $this->admin))->toThrow(ValidationException::class);
    $chain = [$rows['2']->id, $rows['21']->id, $rows['211']->id, $rows['21101']->id];
    expect($service->decide($import, $chain, 'approved', null, $this->admin))->toBe(4)
        ->and(fn () => $service->decide($import->fresh(), [$rows['211']->id], 'excluded', 'Not needed', $this->admin))->toThrow(ValidationException::class);

    $orphan = $service->preview(coaRowsUpload([['21101', 'Trade Creditors', 'Ledger', '211', 'Liability', 'Cr', 'Balance Sheet', 'Both', 'Yes', '']]), $this->admin);
    expect($orphan->rows->first()->validation_errors)->toContain('Parent code does not exist in workbook or current chart.')
        ->and($orphan->rows->first()->proposed_decision)->toBe('exclude')
        ->and(fn () => $service->decide($orphan, $orphan->rows->pluck('id')->all(), 'approved', 'Force it', $this->admin))->toThrow(ValidationException::class);
});

test('flagged rows need a documented decision and excluded rows are never imported', function () {
    $service = app(AccountImportService::class);
    $import = $service->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->get()->keyBy('code');
    $chain = [$rows['1']->id, $rows['11']->id, $rows['111']->id];
    $school = $rows['11101']->id;

    expect(fn () => $service->confirm($import, [...$chain, $school], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $service->decide($import, [...$chain, $school], 'approved', null, $this->admin))->toThrow(ValidationException::class)
        ->and(Account::query()->count())->toBe(0);

    $service->decide($import, $chain, 'approved', null, $this->admin);
    $service->decide($import, [$school], 'excluded', 'Former school cash ledger is not used by FRC', $this->admin);
    expect(fn () => $service->confirm($import->fresh(), [...$chain, $school], $this->admin))->toThrow(ValidationException::class);

    expect($service->confirm($import->fresh(), [], $this->admin))->toBe(3)
        ->and(Account::query()->pluck('code')->all())->toBe(['1', '11', '111'])
        ->and(Account::query()->where('code', '11101')->exists())->toBeFalse()
        ->and($import->fresh()->summary['review'])->toBe(['pending' => 222, 'approved' => 3, 'excluded' => 1]);
});

test('review screen filters rows records decisions and exports the approval summary', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    $row = $import->rows()->where('code', '1')->first();

    $this->get(route('account-imports.show', $import))->assertOk()->assertSee('COA Approval Review')->assertSee('Proposed decision');
    $this->get(route('account-imports.show', [$import, 'classification' => 'school_only']))->assertOk()->assertSee('Tuition Fee')->assertDontSee('Therapy Session Fees');
    $this->get(route('account-imports.show', [$import, 'flag' => 'manual']))->assertOk()->assertSee('Inter-Unit Receivable')->assertDontSee('Tuition Fee');

    $this->post(route('account-imports.decide', $import), ['rows' => [$row->id], 'decision' => 'approved'])->assertRedirect()->assertSessionHasNoErrors();
    expect($row->fresh()->review_status)->toBe('approved')->and($row->fresh()->reviewed_by)->toBe($this->admin->id)->and(Account::query()->count())->toBe(0);

    $csv = $this->get(route('account-imports.export', $import))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toContain('Account Code')->toContain('Proposed Decision')->toContain('Approval Status')->toContain('Therapy Session Fees')
        ->and(substr_count($csv, "\n"))->toBe(227);

    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $this->actingAs($viewer)->post(route('account-imports.decide', $import), ['rows' => [$row->id], 'decision' => 'excluded'])->assertForbidden();
    $this->actingAs($viewer)->get(route('account-imports.export', $import))->assertForbidden();
});

test('heading 215 stays importable for its non inter-unit ledgers while 21501 is proposed for exclusion', function () {
    $import = app(AccountImportService::class)->preview(clientCoaUpload(), $this->admin);
    $rows = $import->rows()->get()->keyBy('code');

    expect($rows['215']->proposed_decision)->toBe('include')
        ->and($rows['215']->requires_manual_review)->toBeTrue()
        ->and($rows['215']->proposed_reason)->toContain('21502')->toContain('21503')
        ->and($rows['21501']->proposed_decision)->toBe('exclude')
        ->and($rows['11602']->proposed_decision)->toBe('exclude')
        ->and($rows['21502']->proposed_decision)->toBe('include')
        ->and($rows['21503']->proposed_decision)->toBe('include')
        ->and($import->summary['manual_review_flagged'])->toBe(11)
        ->and($import->rows()->where('review_status', '!=', 'pending')->count())->toBe(0)
        ->and(Account::query()->count())->toBe(0);
});

test('heading 215 cannot be skipped or excluded while its approved ledgers depend on it', function () {
    $service = app(AccountImportService::class);
    $import = $service->preview(clientCoaUpload(), $this->admin);
    $id = fn (string $code): int => $import->rows()->where('code', $code)->value('id');

    expect(fn () => $service->decide($import, [$id('2'), $id('21'), $id('21502')], 'approved', 'Accountant approved the grant ledger', $this->admin))
        ->toThrow(ValidationException::class, 'Approve required parent 215');

    $service->decide($import, [$id('2'), $id('21'), $id('215'), $id('21502'), $id('21503')], 'approved', 'Accountant approved grant and other payables under the existing heading', $this->admin);
    $service->decide($import, [$id('21501')], 'excluded', 'Inter-unit ledger; FRC is one organisation', $this->admin);

    expect(fn () => $service->decide($import->fresh(), [$id('215')], 'excluded', 'Trying to drop the heading', $this->admin))
        ->toThrow(ValidationException::class, 'Approved child accounts still depend on a selected parent');

    expect($service->confirm($import->fresh(), [], $this->admin))->toBe(5);
    $accounts = Account::query()->pluck('name', 'code');
    expect(Account::query()->orderBy('code')->pluck('code')->all())->toBe(['2', '21', '215', '21502', '21503'])
        ->and($accounts['215'])->toBe('Inter-Unit & Other')
        ->and($accounts['21502'])->toBe('Deferred Grant Income')
        ->and(Account::query()->where('code', '21502')->first()->parent->code)->toBe('215')
        ->and(Account::query()->where('code', '21501')->exists())->toBeFalse();
});
