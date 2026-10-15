<?php

use App\Enums\SystemRole;
use App\Models\AccountImport;
use App\Models\User;
use App\Services\AccountImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->actingAs($this->admin);
});

/** A preview stored before review classification existed: its rows carry no classification yet. */
function legacyAccountImport(User $actor): AccountImport
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Chart of Accounts')->fromArray([
        ['Account Code', 'Account Name', 'Level', 'Parent Code', 'Account Type', 'Normal Balance', 'Financial Statement', 'Entity', 'Posting Allowed', 'Notes'],
        ['100000', 'Assets', 'Class', '', 'Asset', 'Dr', 'Balance Sheet', 'Both', 'No', ''],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'coa-readonly-').'.xlsx';
    (new Xlsx($book))->save($path);
    $import = app(AccountImportService::class)->preview(new UploadedFile($path, 'legacy.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), $actor);
    $import->rows()->update(['classification' => null, 'proposed_decision' => null, 'proposed_reason' => null]);
    $import->update(['summary' => ['legacy' => true]]);

    return $import->fresh();
}

test('viewing and exporting an import preview never writes to it', function () {
    $import = legacyAccountImport($this->admin);
    $rowsBefore = $import->rows()->get()->map->getAttributes()->all();

    $this->get(route('account-imports.show', $import))->assertOk();
    $this->get(route('account-imports.export', $import))->assertOk()->streamedContent();

    expect($import->fresh()->summary)->toBe(['legacy' => true])
        ->and($import->rows()->get()->map->getAttributes()->all())->toBe($rowsBefore)
        ->and($import->rows()->whereNotNull('classification')->count())->toBe(0);
});

test('a legacy preview is classified by the review decision, not by viewing it', function () {
    $import = legacyAccountImport($this->admin);
    $row = $import->rows()->firstOrFail();

    $this->post(route('account-imports.decide', $import), ['rows' => [$row->id], 'decision' => 'approved', 'notes' => 'Confirmed by the accountant'])->assertRedirect()->assertSessionHasNoErrors();

    expect($row->fresh()->classification)->not->toBeNull()
        ->and($row->fresh()->proposed_decision)->not->toBeNull()
        ->and($row->fresh()->review_status)->toBe('approved')
        ->and($import->fresh()->summary)->toHaveKey('classification');
});
