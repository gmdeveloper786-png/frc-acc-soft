<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\OpeningBalanceImportService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class]);
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
    expect($batch->status)->toBe('draft')->and($import->fresh()->status)->toBe('applied');
});
