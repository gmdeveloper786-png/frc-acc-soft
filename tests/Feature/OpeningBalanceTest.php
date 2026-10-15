<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\OpeningBalanceImportService;
use App\Services\OpeningBalancePostingService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
