<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OperationalTransactionService;
use App\Services\PettyCash\ImprestFloatService;
use App\Services\PettyCash\ImprestLedgerService;
use App\Services\PettyCash\ImprestReplenishmentService;
use App\Services\PettyCash\ImprestStatementService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->pettyLedger = Account::factory()->create(['code' => '111150']);
    $this->bankLedger = Account::factory()->create(['code' => '111201']);
    $this->expenseLedger = Account::factory()->create(['code' => '511150', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->pettyCash = FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Petty cash box', 'branch_id' => $this->branch, 'account_id' => $this->pettyLedger]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Main bank', 'branch_id' => $this->branch, 'account_id' => $this->bankLedger]);
    $this->actingAs($this->maker);
});

function imprestFloat(object $test, string $amount = '5000.0000'): ImprestFloat
{
    return app(ImprestFloatService::class)->create([
        'branch_id' => $test->branch->id, 'account_id' => $test->pettyLedger->id, 'float_amount' => $amount,
        'source_financial_account_id' => $test->bank->id, 'custodian_name' => 'Front desk custodian',
    ], $test->maker);
}

/** A petty cash voucher taken through approval, disbursement and posting by two users. */
function postedPettyVoucher(object $test, string $amount, string $date = '2026-01-10'): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $voucher = $service->create([
        'type' => OperationalTransactionType::PettyCash->value, 'branch_id' => $test->branch->id, 'transaction_date' => $date,
        'amount' => $amount, 'financial_account_id' => $test->pettyCash->id, 'contra_account_id' => $test->expenseLedger->id, 'narration' => 'Office supplies',
    ], $test->maker);
    $service->submit($voucher, $test->maker);
    $service->approve($voucher->fresh(), $test->checker);
    $service->disburse($voucher->fresh(), $test->checker);

    return $service->post($voucher->fresh(), $test->checker);
}

/** Submit, approve and post the replenishment journal the way the journal screens do. */
function postReplenishment(object $test, ImprestReplenishment $replenishment): void
{
    $journal = $replenishment->journalEntry;
    app(JournalWorkflowService::class)->submit($journal, $test->maker);
    app(JournalWorkflowService::class)->approve($journal->fresh(), $test->checker);
    app(JournalPostingService::class)->post($journal->fresh(), $test->checker);
}

test('float setup requires a petty cash or cash ledger and a bank or cash source of the same branch', function () {
    $service = app(ImprestFloatService::class);
    $base = ['branch_id' => $this->branch->id, 'account_id' => $this->pettyLedger->id, 'float_amount' => '5000.0000', 'source_financial_account_id' => $this->bank->id, 'custodian_name' => 'Custodian'];
    $otherCash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->otherBranch, 'account_id' => Account::factory()]);
    $otherBank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->otherBranch, 'account_id' => Account::factory()]);

    expect(fn () => $service->create([...$base, 'account_id' => $this->bankLedger->id], $this->maker))->toThrow(ValidationException::class, 'petty cash ledger must be')
        ->and(fn () => $service->create([...$base, 'account_id' => $this->expenseLedger->id], $this->maker))->toThrow(ValidationException::class, 'petty cash ledger must be')
        ->and(fn () => $service->create([...$base, 'account_id' => $otherCash->account_id], $this->maker))->toThrow(ValidationException::class, 'petty cash ledger must be')
        ->and(fn () => $service->create([...$base, 'source_financial_account_id' => $otherBank->id], $this->maker))->toThrow(ValidationException::class, 'same branch')
        ->and(fn () => $service->create([...$base, 'source_financial_account_id' => $this->pettyCash->id], $this->maker))->toThrow(ValidationException::class, 'petty cash ledger itself')
        ->and(fn () => $service->create([...$base, 'float_amount' => '0'], $this->maker))->toThrow(ValidationException::class);

    $float = $service->create($base, $this->maker);
    expect($float->float_amount)->toBe('5000.0000')
        ->and(AuditLog::query()->where('event', 'imprest.float_created')->where('auditable_id', $float->id)->exists())->toBeTrue()
        ->and(fn () => $service->create($base, $this->maker))->toThrow(ValidationException::class, 'already has an active imprest float');

    // The petty cash mapping ledger is accepted for any branch.
    $mapped = Account::factory()->create();
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::PettyCash->value, 'account_id' => $mapped->id, 'updated_by' => $this->maker->id]);
    expect($service->create([...$base, 'account_id' => $mapped->id], $this->maker)->account_id)->toBe($mapped->id);

    // Deactivating frees the ledger; changes are audited with old and new values.
    $service->update($float, ['float_amount' => '6000', 'source_financial_account_id' => $this->bank->id, 'custodian_name' => 'Custodian', 'is_active' => false], $this->maker);
    $log = AuditLog::query()->where('event', 'imprest.float_updated')->latest('id')->first();
    expect($log->properties['old'])->toBe(['float_amount' => '5000.0000', 'is_active' => 'yes'])
        ->and($log->properties['new'])->toBe(['float_amount' => '6000.0000', 'is_active' => 'no'])
        ->and($service->create($base, $this->maker)->is_active)->toBeTrue();

    $this->post(route('imprest.store'), [...$base, 'account_id' => $this->bankLedger->id])->assertSessionHasErrors('account_id');
    $this->get(route('imprest.create', ['branch_id' => $this->branch->id]))->assertOk()->assertSee('111150')->assertDontSee('111201 —', false);
    $this->get(route('imprest.edit', $float))->assertOk()->assertSee('Main bank');
});

test('balance and replenishment due come from posted vouchers only', function () {
    $float = imprestFloat($this);
    $ledger = app(ImprestLedgerService::class);
    $replenishment = app(ImprestReplenishmentService::class)->request($float, ['transaction_date' => '2026-01-02'], $this->maker);
    expect($replenishment->amount)->toBe('5000.0000');
    postReplenishment($this, $replenishment);

    postedPettyVoucher($this, '1200.5000');
    postedPettyVoucher($this, '300.2500', '2026-01-12');
    app(OperationalTransactionService::class)->create([
        'type' => 'petty_cash', 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-13', 'amount' => '99.0000',
        'financial_account_id' => $this->pettyCash->id, 'contra_account_id' => $this->expenseLedger->id, 'narration' => 'Not yet approved',
    ], $this->maker);

    $summary = $ledger->summary($float->fresh());
    expect($summary['balance'])->toBe('3499.2500')
        ->and($summary['due'])->toBe('1500.7500')
        ->and($summary['posted_vouchers'])->toHaveCount(2)
        ->and($summary['posted_total'])->toBe('1500.7500')
        ->and($summary['pending_vouchers'])->toHaveCount(1)
        ->and($summary['pending_total'])->toBe('99.0000')
        ->and($summary['warnings'])->toBe([]);

    // Lowering the float below the balance never gives a negative due, and warns.
    app(ImprestFloatService::class)->update($float, ['float_amount' => '3000', 'source_financial_account_id' => $this->bank->id, 'custodian_name' => 'Custodian', 'is_active' => true], $this->maker);
    $summary = $ledger->summary($float->fresh());
    expect($summary['due'])->toBe('0.0000')->and($summary['warnings'])->toHaveCount(1);

    $this->get(route('imprest.show', $float))->assertOk()->assertSee('3,499.25')->assertSee('above the imprest float');
});

test('replenishment prepares a draft CV journal that follows maker checker and restores the float when posted', function () {
    $float = imprestFloat($this);
    postReplenishment($this, app(ImprestReplenishmentService::class)->request($float, ['transaction_date' => '2026-01-02'], $this->maker));
    $first = postedPettyVoucher($this, '1200.5000');
    $second = postedPettyVoucher($this, '300.2500', '2026-01-12');

    $this->post(route('imprest.replenishments.store', $float), ['transaction_date' => '2026-01-20'])->assertRedirect(route('imprest.show', $float));
    $replenishment = ImprestReplenishment::query()->latest('id')->first();
    $journal = $replenishment->journalEntry->load(['lines', 'voucherType']);

    expect($replenishment->status)->toBe('draft')
        ->and($replenishment->amount)->toBe('1500.7500')
        ->and($replenishment->covered_voucher_ids)->toBe([$first->id, $second->id])
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->voucher_number)->toBeNull()
        ->and($journal->voucherType->code)->toBe('CV')
        ->and($journal->branch_id)->toBe($this->branch->id)
        ->and($journal->transaction_date->toDateString())->toBe('2026-01-20')
        ->and($journal->narration)->toContain($first->journalEntry->voucher_number)
        ->and($journal->lines->map(fn ($line) => [$line->account_id, $line->debit, $line->credit])->all())->toBe([
            [$this->pettyLedger->id, '1500.7500', '0.0000'],
            [$this->bankLedger->id, '0.0000', '1500.7500'],
        ]);

    // Maker-checker: the preparer can submit but not approve; posting needs approval first.
    app(JournalWorkflowService::class)->submit($journal, $this->maker);
    expect(fn () => app(JournalWorkflowService::class)->approve($journal->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => app(JournalPostingService::class)->post($journal->fresh(), $this->checker))->toThrow(ValidationException::class);
    app(JournalWorkflowService::class)->approve($journal->fresh(), $this->checker);
    app(JournalPostingService::class)->post($journal->fresh(), $this->checker);

    $summary = app(ImprestLedgerService::class)->summary($float->fresh());
    expect($summary['balance'])->toBe('5000.0000')
        ->and($summary['due'])->toBe('0.0000')
        ->and($summary['posted_vouchers'])->toHaveCount(0)
        ->and($replenishment->fresh()->status)->toBe('posted');
});

test('a second open replenishment and an amount above the due are refused', function () {
    $float = imprestFloat($this);
    $service = app(ImprestReplenishmentService::class);

    expect(fn () => $service->request($float, ['transaction_date' => '2026-01-02', 'amount' => '5000.0001'], $this->maker))->toThrow(ValidationException::class, 'cannot exceed');
    $replenishment = $service->request($float, ['transaction_date' => '2026-01-02', 'amount' => '2000'], $this->maker);
    expect($replenishment->amount)->toBe('2000.0000')
        ->and(fn () => $service->request($float, ['transaction_date' => '2026-01-03'], $this->maker))->toThrow(ValidationException::class, 'still open');

    // Returned for correction is still open.
    app(JournalWorkflowService::class)->submit($replenishment->journalEntry, $this->maker);
    app(JournalWorkflowService::class)->reject($replenishment->journalEntry->fresh(), $this->checker, 'Wrong amount');
    expect(fn () => $service->request($float, ['transaction_date' => '2026-01-03'], $this->maker))->toThrow(ValidationException::class, 'still open');

    // Discarding the draft cancels it and frees the float; a closed period is refused.
    app(JournalWorkflowService::class)->discard($replenishment->journalEntry->fresh(), $this->maker, 'Prepared in error');
    expect(fn () => $service->request($float, ['transaction_date' => '2026-02-03'], $this->maker))->toThrow(ValidationException::class, 'No open accounting period');
    $again = $service->request($float, ['transaction_date' => '2026-01-03'], $this->maker);
    expect($replenishment->fresh()->status)->toBe('cancelled')->and($again->amount)->toBe('5000.0000');

    $this->post(route('imprest.replenishments.store', $float), ['transaction_date' => '2026-01-04'])->assertSessionHasErrors('float');
});

test('imprest screens, replenishment and statement are branch scoped and permission gated', function () {
    $float = imprestFloat($this);
    $outsider = User::factory()->create();
    $outsider->assignRole(SystemRole::Accountant->value);
    $outsider->branches()->attach($this->otherBranch);

    $this->actingAs($outsider)->get(route('imprest.index'))->assertOk()->assertDontSee('Front desk custodian');
    $this->get(route('imprest.show', $float))->assertForbidden();
    $this->get(route('imprest.edit', $float))->assertForbidden();
    $this->get(route('imprest.statement', $float))->assertForbidden();
    $this->post(route('imprest.replenishments.store', $float), ['transaction_date' => '2026-01-05'])->assertForbidden();
    $this->post(route('imprest.store'), ['branch_id' => $this->branch->id, 'account_id' => $this->pettyLedger->id, 'float_amount' => '100', 'source_financial_account_id' => $this->bank->id, 'custodian_name' => 'X'])->assertForbidden();
    expect(ImprestReplenishment::query()->count())->toBe(0);

    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $viewer->branches()->attach($this->branch);
    $this->actingAs($viewer)->get(route('imprest.show', $float))->assertOk()->assertSee('Front desk custodian')->assertDontSee('Prepare draft CV journal');
    $this->post(route('imprest.replenishments.store', $float), ['transaction_date' => '2026-01-05'])->assertForbidden();
    $this->get(route('imprest.create'))->assertForbidden();
    $this->get(route('dashboard'))->assertSee('Petty Cash Imprest');

    $cashier = User::factory()->create();
    $cashier->branches()->attach($this->branch);
    $this->actingAs($cashier)->get(route('imprest.index'))->assertForbidden();
});

test('imprest statement shows opening, vouchers, replenishments and closing and exports', function () {
    $float = imprestFloat($this);
    postReplenishment($this, app(ImprestReplenishmentService::class)->request($float, ['transaction_date' => '2026-01-02'], $this->maker));
    postedPettyVoucher($this, '1200.5000', '2026-01-10');
    postedPettyVoucher($this, '300.2500', '2026-01-15');

    $statement = app(ImprestStatementService::class)->build($float, Carbon::parse('2026-01-05'), Carbon::parse('2026-01-31'));
    expect($statement['opening'])->toBe('5000.0000')
        ->and($statement['vouchers_total'])->toBe('1500.7500')
        ->and($statement['replenishments_total'])->toBe('0.0000')
        ->and($statement['closing'])->toBe('3499.2500')
        ->and($statement['rows'])->toHaveCount(2);

    $query = ['float' => $float->id, 'from' => '2026-01-01', 'to' => '2026-01-31'];
    $this->get(route('imprest.statement', $query))->assertOk()->assertSee('Imprest replenishment')->assertSee('3,499.25');
    $csv = $this->get(route('imprest.statement.export', [...$query, 'format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain('Petty Cash Imprest Statement')->toContain('Opening balance')->toContain('Closing balance')->toContain('3499.2500');
    $this->get(route('imprest.statement.export', [...$query, 'format' => 'xlsx']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->get(route('imprest.statement.export', [...$query, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $viewerWithoutExport = User::factory()->create();
    $viewerWithoutExport->assignRole(SystemRole::Cashier->value);
    $viewerWithoutExport->branches()->attach($this->branch);
    $this->actingAs($viewerWithoutExport)->get(route('imprest.statement', $query))->assertOk();
    $this->get(route('imprest.statement.export', [...$query, 'format' => 'csv']))->assertForbidden();
});
