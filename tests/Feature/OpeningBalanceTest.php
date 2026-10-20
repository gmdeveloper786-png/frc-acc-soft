<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\OpeningBalanceImportService;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Support\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->branch = Branch::factory()->create();
    $this->admin->branches()->attach($this->branch);
});

function openingRowsUpload(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['Account Code', 'Debit', 'Credit', 'Source Reference'], ...$rows]);
    $path = tempnam(sys_get_temp_dir(), 'opening-test-').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'opening.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function openingCsvUpload(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['Account Code', 'Debit', 'Credit', 'Source Reference'], ...$rows]);
    $path = tempnam(sys_get_temp_dir(), 'opening-test-').'.csv';
    (new Csv($book))->save($path);

    return new UploadedFile($path, 'opening.csv', 'text/csv', null, true);
}

test('balanced opening amounts are saved as draft only', function () {
    $debit = Account::factory()->create(['code' => '111001']);
    $credit = Account::factory()->create(['code' => '211001', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->actingAs($this->admin)->post(route('opening-balances.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01', 'lines' => [['account_id' => $debit->id, 'debit' => '100.10', 'credit' => '0'], ['account_id' => $credit->id, 'debit' => '0', 'credit' => '100.10']]])->assertRedirect();
    expect(OpeningBalanceBatch::query()->first()->status)->toBe('draft')->and(OpeningBalanceBatch::query()->first()->total_debit)->toBe('100.10');
});

test('imbalanced opening amount is rejected', function () {
    $debit = Account::factory()->create();
    $this->actingAs($this->admin)->post(route('opening-balances.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01', 'lines' => [['account_id' => $debit->id, 'debit' => '100.00', 'credit' => '0']]])->assertSessionHasErrors('lines');
    expect(OpeningBalanceBatch::query()->count())->toBe(0);
});

test('inactive account is unavailable for opening draft', function () {
    $account = Account::factory()->create(['is_active' => false]);
    $this->actingAs($this->admin)->post(route('opening-balances.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01', 'lines' => [['account_id' => $account->id, 'debit' => '1', 'credit' => '0']]])->assertSessionHasErrors('lines.0.account_id');
});

test('opening balance form lists only eligible balance sheet ledgers', function () {
    $subGroup = Account::factory()->create(['name' => 'Cash and Bank', 'level' => 'sub_group', 'is_postable' => false]);
    $eligible = Account::factory()->create(['name' => 'Eligible balance sheet ledger', 'parent_id' => $subGroup->id]);
    $income = Account::factory()->create(['name' => 'Blocked income ledger', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $group = Account::factory()->create(['name' => 'Blocked group account', 'level' => 'group', 'is_postable' => false]);

    $this->actingAs($this->admin)->get(route('opening-balances.create'))
        ->assertOk()
        ->assertSee($eligible->name)
        ->assertSee($subGroup->name)
        ->assertDontSee($income->name)
        ->assertDontSee($group->name)
        ->assertSee('data-opening-search', false)
        ->assertSee('data-opening-type-filter="asset"', false)
        ->assertSee('data-opening-entered-only', false)
        ->assertSee('data-opening-group-toggle', false)
        ->assertSee('data-opening-total-debit', false);
});

test('validation failure restores every submitted opening balance field', function () {
    $asset = Account::factory()->create(['code' => '111001']);
    $equity = Account::factory()->create(['code' => '311001', 'account_type' => 'equity', 'normal_balance' => 'credit']);

    $this->actingAs($this->admin)
        ->from(route('opening-balances.create'))
        ->post(route('opening-balances.store'), [
            'fiscal_year_id' => $this->year->id,
            'branch_id' => $this->branch->id,
            'opening_date' => '2026-01-01',
            'source_reference' => 'AUDITED-TB-2026',
            'lines' => [
                ['account_id' => $asset->id, 'debit' => '12.34', 'credit' => '', 'source_reference' => 'ASSET-SCHEDULE'],
                ['account_id' => $equity->id, 'debit' => '', 'credit' => '12.33', 'source_reference' => 'FUND-SCHEDULE'],
            ],
        ])
        ->assertRedirect(route('opening-balances.create'))
        ->assertSessionHasErrors('lines');

    $this->get(route('opening-balances.create'))
        ->assertOk()
        ->assertSee('value="12.34"', false)
        ->assertSee('value="12.33"', false)
        ->assertSee('value="ASSET-SCHEDULE"', false)
        ->assertSee('value="FUND-SCHEDULE"', false)
        ->assertSee('value="AUDITED-TB-2026"', false);
});

test('forged income and expense opening balance lines are rejected', function (string $type, string $normalBalance) {
    $blocked = Account::factory()->create(['account_type' => $type, 'normal_balance' => $normalBalance, 'financial_statement' => 'income_expenditure']);
    $offset = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);

    $this->actingAs($this->admin)->post(route('opening-balances.store'), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
        'lines' => [
            ['account_id' => $blocked->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $offset->id, 'debit' => '0', 'credit' => '10.00'],
        ],
    ])->assertSessionHasErrors('lines.0.account_id');

    expect(OpeningBalanceBatch::query()->count())->toBe(0);
})->with([
    'income' => ['income', 'credit'],
    'expense' => ['expense', 'debit'],
]);

test('opening date must match both fiscal year start and configured opening date', function () {
    $asset = Account::factory()->create();
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $payload = [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-02',
        'lines' => [
            ['account_id' => $asset->id, 'debit' => '10.00', 'credit' => '0'],
            ['account_id' => $equity->id, 'debit' => '0', 'credit' => '10.00'],
        ],
    ];
    $this->actingAs($this->admin)->post(route('opening-balances.store'), $payload)->assertSessionHasErrors('opening_date');

    $otherYear = FiscalYear::factory()->create(['name' => 'FY 2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31']);
    $payload['fiscal_year_id'] = $otherYear->id;
    $payload['opening_date'] = '2027-04-01';
    $this->post(route('opening-balances.store'), $payload)->assertSessionHasErrors('opening_date');

    AppSetting::query()->where('key', 'fiscal.initial_opening_date')->update(['value' => '2027-04-01']);
    Cache::forget(AppSettings::cacheKey('fiscal.initial_opening_date'));
    $this->post(route('opening-balances.store'), $payload)->assertRedirect();

    expect(OpeningBalanceBatch::query()->sole()->opening_date->toDateString())->toBe('2027-04-01');
});

test('all zero and over precision manual opening amounts are rejected', function (array $lines, string $error) {
    $asset = Account::factory()->create();
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    foreach ($lines as $index => $line) {
        $lines[$index]['account_id'] = $index === 0 ? $asset->id : $equity->id;
    }

    $this->actingAs($this->admin)->post(route('opening-balances.store'), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01', 'lines' => $lines,
    ])->assertSessionHasErrors($error);
    expect(OpeningBalanceBatch::query()->count())->toBe(0);
})->with([
    'all zero' => [[['debit' => '0', 'credit' => '0'], ['debit' => '0', 'credit' => '0']], 'lines'],
    'over precision' => [[['debit' => '10.001', 'credit' => '0'], ['debit' => '0', 'credit' => '10.001']], 'lines.0.debit'],
]);

test('repeated draft save updates the unique branch and fiscal year batch', function () {
    $asset = Account::factory()->create();
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $payload = [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
        'lines' => [['account_id' => $asset->id, 'debit' => '10', 'credit' => '0'], ['account_id' => $equity->id, 'debit' => '0', 'credit' => '10']],
    ];

    $this->actingAs($this->admin)->post(route('opening-balances.store'), $payload)->assertRedirect();
    $this->post(route('opening-balances.store'), $payload)->assertRedirect();

    expect(OpeningBalanceBatch::query()->count())->toBe(1)
        ->and(OpeningBalanceBatch::query()->first()->lines()->count())->toBe(2);
});

test('cross branch opening balance submission is rejected', function () {
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Accountant->value);
    $blocked = Branch::factory()->create();
    $account = Account::factory()->create();
    $this->actingAs($user)->post(route('opening-balances.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $blocked->id, 'opening_date' => '2026-01-01', 'lines' => [['account_id' => $account->id, 'debit' => '1', 'credit' => '1']]])->assertForbidden();
});

test('opening balance posting route requires dedicated permission', function () {
    $user = User::factory()->create();
    $batch = OpeningBalanceBatch::factory()->create(['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id]);

    $this->actingAs($user)->post(route('opening-balances.post', $batch))->assertForbidden();
});

test('opening balance workbook is previewed then applied to draft without posting', function () {
    $debit = Account::factory()->create(['code' => '111001']);
    $credit = Account::factory()->create(['code' => '211001', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([[$debit->code, '25.50', '0', 'TB-1'], [$credit->code, '0', '25.50', 'TB-1']]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $this->admin);
    expect($import->difference)->toBe('0.00')->and($import->rows->every(fn ($row) => empty($row->validation_errors)))->toBeTrue();
    $batch = $service->apply($import, $this->admin);
    expect($batch->status)->toBe('draft')
        ->and($batch->journal_entry_id)->toBeNull()
        ->and($import->fresh()->status)->toBe('applied');
});

test('import preview displays exact invalid decimals and formats valid amounts without including invalid rows in totals', function () {
    $accounts = collect([
        ['11102', 'asset', 'debit'],
        ['11103', 'asset', 'debit'],
        ['11104', 'asset', 'debit'],
        ['11105', 'asset', 'debit'],
        ['11106', 'asset', 'debit'],
        ['11107', 'asset', 'debit'],
        ['31101', 'equity', 'credit'],
    ])->map(fn (array $data) => Account::factory()->create([
        'code' => $data[0],
        'account_type' => $data[1],
        'normal_balance' => $data[2],
    ]));

    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingCsvUpload([
        [$accounts[0]->code, '100.125', '0.00', '<script>alert(1)</script>'],
        [$accounts[1]->code, '500.999', '0.00', null],
        [$accounts[2]->code, '0.001', '0.00', null],
        [$accounts[3]->code, '100.12', '0.00', null],
        [$accounts[4]->code, '100.00', '0.00', null],
        [$accounts[5]->code, '100', '0.00', null],
        [$accounts[6]->code, '0.00', '300.12', null],
    ]), [
        'fiscal_year_id' => $this->year->id,
        'branch_id' => $this->branch->id,
        'opening_date' => '2026-01-01',
    ], $this->admin);
    $display = $service->displayAmounts($import);

    expect($import->total_debit)->toBe('300.12')
        ->and($import->total_credit)->toBe('300.12')
        ->and($import->difference)->toBe('0.00')
        ->and($display['source_available'])->toBeTrue()
        ->and($display['rows'][2]['debit'])->toBe('100.125')
        ->and($display['rows'][3]['debit'])->toBe('500.999')
        ->and($display['rows'][4]['debit'])->toBe('0.001')
        ->and($display['rows'][5]['debit'])->toBe('100.12')
        ->and($display['rows'][6]['debit'])->toBe('100.00')
        ->and($display['rows'][7]['debit'])->toBe('100.00')
        ->and($import->rows->filter(fn ($row) => ! empty($row->validation_errors))->count())->toBe(3)
        ->and(fn () => $service->apply($import, $this->admin))->toThrow(ValidationException::class)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0);

    $this->actingAs($this->admin)->get(route('opening-balance-imports.show', $import))
        ->assertOk()
        ->assertSee('100.125')
        ->assertSee('500.999')
        ->assertSee('0.001')
        ->assertSee('100.12')
        ->assertSee('100.00')
        ->assertSee('Amounts may have no more than two decimal places.')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('import preview requests re-upload when the verified source is unavailable', function () {
    $asset = Account::factory()->create(['code' => '11102']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingCsvUpload([[$asset->code, '100.125', '0.00', null]]), [
        'fiscal_year_id' => $this->year->id,
        'branch_id' => $this->branch->id,
        'opening_date' => '2026-01-01',
    ], $this->admin);
    Storage::disk('local')->delete($import->stored_path);

    $this->actingAs($this->admin)->get(route('opening-balance-imports.show', $import))
        ->assertOk()
        ->assertSee('Exact invalid input values cannot be reconstructed')
        ->assertSee('100.13');
});

test('xlsx import preview also retains over-precision source values', function () {
    $asset = Account::factory()->create(['code' => '11102']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([[$asset->code, '500.999', '0.00', null]]), [
        'fiscal_year_id' => $this->year->id,
        'branch_id' => $this->branch->id,
        'opening_date' => '2026-01-01',
    ], $this->admin);

    expect($service->displayAmounts($import)['rows'][2]['debit'])->toBe('500.999')
        ->and($import->rows->first()->validation_errors)->toContain('Amounts may have no more than two decimal places.');
});

test('import preview marks ineligible over precision duplicate and invalid amount rows', function () {
    $income = Account::factory()->create(['code' => '421001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $asset = Account::factory()->create(['code' => '111001']);
    $inactive = Account::factory()->create(['code' => '111002', 'is_active' => false]);
    $nonPostable = Account::factory()->create(['code' => '111003', 'is_postable' => false]);
    $group = Account::factory()->create(['code' => '111004', 'level' => 'group']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([
        [$income->code, '10.00', '0', null],
        [$asset->code, '1.001', '0', null],
        [$asset->code, '-1', '2', null],
        [$inactive->code, '2', '0', null],
        [$nonPostable->code, '2', '0', null],
        [$group->code, '2', '0', null],
        ['UNKNOWN', '0', '10.00', null],
    ]), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01'], $this->admin);

    expect($import->rows->every(fn ($row) => ! empty($row->validation_errors)))->toBeTrue()
        ->and(fn () => $service->apply($import, $this->admin))->toThrow(ValidationException::class)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0);
});

test('import preview rejects wrong date and apply rejects all zero or unbalanced data', function () {
    $asset = Account::factory()->create(['code' => '111001']);
    $equity = Account::factory()->create(['code' => '311001', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $service = app(OpeningBalanceImportService::class);
    expect(fn () => $service->preview(openingRowsUpload([[$asset->code, '10', '0', null], [$equity->code, '0', '10', null]]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-02',
    ], $this->admin))->toThrow(ValidationException::class);

    $zero = $service->preview(openingRowsUpload([[$asset->code, '0', '0', null]]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $this->admin);
    $unbalanced = $service->preview(openingRowsUpload([[$asset->code, '10', '0', null], [$equity->code, '0', '9', null]]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $this->admin);

    expect(fn () => $service->apply($zero, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $service->apply($unbalanced, $this->admin))->toThrow(ValidationException::class)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0);
});

test('import apply independently revalidates account eligibility', function () {
    $asset = Account::factory()->create(['code' => '111001']);
    $equity = Account::factory()->create(['code' => '311001', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([[$asset->code, '10.00', '0', null], [$equity->code, '0', '10.00', null]]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $this->admin);
    $asset->update(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);

    expect(fn () => $service->apply($import, $this->admin))->toThrow(ValidationException::class)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0);
});

test('invalid stored opening balance cannot be approved or posted', function () {
    $income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $asset = Account::factory()->create();
    $checker = User::factory()->create();
    $checker->assignRole(SystemRole::Administrator->value);
    $checker->branches()->attach($this->branch);
    $batch = OpeningBalanceBatch::factory()->create([
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'created_by' => $this->admin->id,
        'total_debit' => '10.00', 'total_credit' => '10.00',
    ]);
    $batch->lines()->createMany([
        ['account_id' => $asset->id, 'debit' => '10.00', 'credit' => '0'],
        ['account_id' => $income->id, 'debit' => '0', 'credit' => '10.00'],
    ]);
    $service = app(OpeningBalancePostingService::class);

    expect(fn () => $service->approve($batch, $checker))->toThrow(ValidationException::class);
    $batch->update(['status' => 'approved', 'approved_by' => $checker->id, 'approved_at' => now()]);
    expect(fn () => $service->post($batch->fresh(), $checker))->toThrow(ValidationException::class)
        ->and($batch->fresh()->journal_entry_id)->toBeNull();
});

test('valid asset liability and equity opening workflow approves and posts', function () {
    $this->year->update(['status' => FiscalYearStatus::Open]);
    AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open,
    ]);
    $asset = Account::factory()->create();
    $liability = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $batch = app(OpeningBalanceService::class)->saveDraft([
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
        'lines' => [
            ['account_id' => $asset->id, 'debit' => '30.00', 'credit' => '0'],
            ['account_id' => $liability->id, 'debit' => '0', 'credit' => '10.00'],
            ['account_id' => $equity->id, 'debit' => '0', 'credit' => '20.00'],
        ],
    ], $this->admin);
    $checker = User::factory()->create();
    $checker->assignRole(SystemRole::Administrator->value);
    $checker->branches()->attach($this->branch);
    $service = app(OpeningBalancePostingService::class);
    $approved = $service->approve($batch, $checker);
    // Three-person rule: a third authorized user posts.
    $poster = User::factory()->create();
    $poster->assignRole(SystemRole::Administrator->value);
    $poster->branches()->attach($this->branch);
    $posted = $service->post($approved, $poster);

    expect($posted->status)->toBe('posted')
        ->and($posted->journal_entry_id)->not->toBeNull()
        ->and($posted->journalEntry->total_debit)->toBe('30.0000')
        ->and($posted->journalEntry->total_credit)->toBe('30.0000');
});

test('the uploader cannot approve the batch another user applied from the import', function () {
    $debit = Account::factory()->create(['code' => '111001']);
    $credit = Account::factory()->create(['code' => '211001', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $uploader = User::factory()->create();
    $uploader->assignRole(SystemRole::Administrator->value);
    $uploader->branches()->attach($this->branch);
    $checker = User::factory()->create();
    $checker->assignRole(SystemRole::Administrator->value);
    $checker->branches()->attach($this->branch);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([[$debit->code, '40.00', '0', 'TB-1'], [$credit->code, '0', '40.00', 'TB-1']]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $uploader);
    $batch = $service->apply($import, $this->admin);

    expect(fn () => app(OpeningBalancePostingService::class)->approve($batch, $uploader))->toThrow(ValidationException::class, 'separate authorized user')
        ->and(fn () => app(OpeningBalancePostingService::class)->approve($batch, $this->admin))->toThrow(ValidationException::class, 'separate authorized user');
    $this->actingAs($uploader)->post(route('opening-balances.approve', $batch))->assertSessionHasErrors('status');
    expect($batch->fresh()->status)->toBe('draft')
        ->and(app(OpeningBalancePostingService::class)->approve($batch, $checker)->status)->toBe('approved');
});

test('applying an import needs the prepare permission and cannot run twice', function () {
    $debit = Account::factory()->create(['code' => '111001']);
    $credit = Account::factory()->create(['code' => '211001', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview(openingRowsUpload([[$debit->code, '10.00', '0', 'TB-1'], [$credit->code, '0', '10.00', 'TB-1']]), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
    ], $this->admin);
    $importer = User::factory()->create();
    $importer->givePermissionTo(['opening_balances.import', 'opening_balances.view']);
    $importer->branches()->attach($this->branch);

    $this->actingAs($importer)->post(route('opening-balance-imports.apply', $import))->assertForbidden();
    expect(fn () => $service->apply($import, $importer))->toThrow(HttpException::class)
        ->and(OpeningBalanceBatch::query()->count())->toBe(0)
        ->and($import->fresh()->status)->toBe('preview');

    $importer->givePermissionTo('opening_balances.prepare');
    $this->actingAs($importer->fresh())->post(route('opening-balance-imports.apply', $import))->assertRedirect();
    // A stale copy of the import still reports "preview"; the locked re-read refuses it.
    expect($import->status)->toBe('preview')
        ->and(fn () => $service->apply($import, $this->admin))->toThrow(ValidationException::class, 'already applied')
        ->and(OpeningBalanceBatch::query()->count())->toBe(1);
});

/**
 * An XLSX upload whose Debit cells are written exactly as given: a float or int
 * becomes a numeric cell (optionally with a number format), ['text' => ...] a
 * text cell, ['formula' => ...] a formula and ['bool' => ...] a boolean.
 *
 * @param  array<string, mixed>  $debits  account code => debit cell
 */
function typedOpeningXlsxUpload(array $debits): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['Account Code', 'Debit', 'Credit', 'Source Reference']]);
    $row = 2;
    foreach ($debits as $code => $cell) {
        $sheet->setCellValueExplicit("A{$row}", (string) $code, DataType::TYPE_STRING);
        $target = $sheet->getCell("B{$row}");
        match (true) {
            is_array($cell) && isset($cell['text']) => $target->setValueExplicit($cell['text'], DataType::TYPE_STRING),
            is_array($cell) && isset($cell['formula']) => $target->setValueExplicit($cell['formula'], DataType::TYPE_FORMULA),
            is_array($cell) && array_key_exists('bool', $cell) => $target->setValueExplicit($cell['bool'], DataType::TYPE_BOOL),
            is_array($cell) => $target->setValueExplicit($cell['number'], DataType::TYPE_NUMERIC),
            default => $target->setValueExplicit($cell, DataType::TYPE_NUMERIC),
        };
        if (is_array($cell) && isset($cell['format'])) {
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($cell['format']);
        }
        $sheet->setCellValueExplicit("C{$row}", 0, DataType::TYPE_NUMERIC);
        $row++;
    }
    $path = tempnam(sys_get_temp_dir(), 'opening-typed-').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'opening-typed.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** Preview the upload and key each row by account code: validated debit, display text and errors. */
function previewOpeningRows(object $test, UploadedFile $upload): array
{
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview($upload, ['fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'opening_date' => '2026-01-01'], $test->admin);
    $display = $service->displayAmounts($import)['rows'];

    return $import->rows->mapWithKeys(fn ($row) => [$row->account_code => [
        'debit' => (string) $row->debit, 'display' => $display[$row->row_number]['debit'], 'errors' => $row->validation_errors,
    ]])->all();
}

test('xlsx numeric cells are validated on their stored value whatever their number format', function () {
    $cells = [
        '11201' => 500.999,
        '11202' => ['number' => 500.999, 'format' => '0.00'],
        '11203' => ['number' => 100.125, 'format' => '#,##0'],
        '11204' => 1234567.89,
        '11205' => 890000.12,
        '11206' => 100.35,
        '11207' => 9999999.99,
        '11208' => ['number' => 1234.5, 'format' => '#,##0.00'],
        '11209' => ['number' => 890000, 'format' => '#,##0.00'],
        '11210' => 99999999999.99,
        '11211' => -100.0,
    ];
    foreach (array_keys($cells) as $code) {
        Account::factory()->create(['code' => (string) $code]);
    }
    $rows = previewOpeningRows($this, typedOpeningXlsxUpload($cells));
    $precision = 'Amounts may have no more than two decimal places.';

    // Over-precision values are rejected and shown exactly, never rounded by their display format.
    foreach (['11201' => '500.999', '11202' => '500.999', '11203' => '100.125'] as $code => $exact) {
        expect($rows[$code]['errors'])->toContain($precision)->and($rows[$code]['display'])->toBe($exact);
    }
    // Valid two-decimal amounts are accepted unchanged, including large ones and formatted ones.
    foreach (['11204' => '1234567.89', '11205' => '890000.12', '11206' => '100.35', '11207' => '9999999.99', '11208' => '1234.50', '11209' => '890000.00', '11210' => '99999999999.99'] as $code => $amount) {
        expect($rows[$code]['errors'])->toBe([], $code)->and($rows[$code]['debit'])->toBe($amount)->and($rows[$code]['display'])->toBe($amount);
    }
    expect($rows['11211']['errors'])->toContain('Amounts cannot be negative.');
});

test('precision beyond what a spreadsheet number can show is rejected rather than silently cut', function () {
    $cells = [
        '11301' => 1234567890123.001,
        '11302' => ['number' => 1.0E16, 'format' => '0.00E+00'],
        '11303' => 0.004,
    ];
    foreach (array_keys($cells) as $code) {
        Account::factory()->create(['code' => (string) $code]);
    }
    $rows = previewOpeningRows($this, typedOpeningXlsxUpload($cells));

    expect($rows['11301']['errors'])->toContain('Amounts may have no more than two decimal places.')
        ->and($rows['11301']['display'])->toBe('1234567890123.001')
        // A large whole number shown in scientific format is a number, not scientific-notation text:
        // it is judged on its value (here above the journal ceiling), never as notation.
        ->and($rows['11302']['errors'])->toBe([AccountingDecimal::maximumMessage()])
        ->and($rows['11302']['display'])->toBe('10000000000000000.00')
        ->and($rows['11303']['errors'])->toContain('Amounts may have no more than two decimal places.');
});

test('scientific notation typed as text is rejected in xlsx and csv', function () {
    foreach (['11401', '11402', '11403', '11404'] as $code) {
        Account::factory()->create(['code' => $code]);
    }
    $message = 'Amounts must be plain decimal numbers; scientific notation such as 1e3 is not accepted.';

    $xlsx = previewOpeningRows($this, typedOpeningXlsxUpload(['11401' => ['text' => '1e3'], '11402' => ['text' => '1E+3'], '11403' => ['text' => '2.5e2'], '11404' => ['text' => '100.50']]));
    foreach (['11401', '11402', '11403'] as $code) {
        expect($xlsx[$code]['errors'])->toContain($message)->and($xlsx[$code]['debit'])->toBe('0.00');
    }
    expect($xlsx['11404']['errors'])->toBe([])->and($xlsx['11404']['debit'])->toBe('100.50');

    // Written as raw text: building the CSV through a spreadsheet would already turn 1e3 into 1000.
    $csvPath = tempnam(sys_get_temp_dir(), 'opening-raw-').'.csv';
    file_put_contents($csvPath, "Account Code,Debit,Credit,Source Reference\n11401,1e3,0,\n11402,2.5E2,0,\n11403,0,1E-2,\n11404,1234567.89,0,\n");
    $csv = previewOpeningRows($this, new UploadedFile($csvPath, 'opening-raw.csv', 'text/csv', null, true));
    foreach (['11401', '11402', '11403'] as $code) {
        expect($csv[$code]['errors'])->toContain($message);
    }
    expect($csv['11404']['errors'])->toBe([])->and($csv['11404']['debit'])->toBe('1234567.89');
});

test('formula and boolean cells are validated on their computed value and cannot bypass the rules', function () {
    $cells = [
        '11501' => ['formula' => '=100.1+0.2'],
        '11502' => ['formula' => '=500.999*1'],
        '11503' => ['formula' => '=1/0'],
        '11504' => ['formula' => '="1e3"'],
        '11505' => ['formula' => '=ROUND(500.999,2)'],
        '11506' => ['bool' => true],
    ];
    foreach (array_keys($cells) as $code) {
        Account::factory()->create(['code' => (string) $code]);
    }
    $rows = previewOpeningRows($this, typedOpeningXlsxUpload($cells));

    // Excel shows =100.1+0.2 as 100.3; the float noise of the sum is not mistaken for extra precision.
    expect($rows['11501']['errors'])->toBe([])->and($rows['11501']['debit'])->toBe('100.30')
        ->and($rows['11502']['errors'])->toContain('Amounts may have no more than two decimal places.')
        ->and($rows['11503']['errors'])->toContain('Invalid decimal amount.')
        ->and($rows['11504']['errors'])->toContain('Amounts must be plain decimal numbers; scientific notation such as 1e3 is not accepted.')
        // A formula that itself rounds is the workbook's explicit choice; its result is a valid amount.
        ->and($rows['11505']['errors'])->toBe([])->and($rows['11505']['debit'])->toBe('501.00')
        ->and($rows['11506']['errors'])->toContain('Invalid decimal amount.');
});
