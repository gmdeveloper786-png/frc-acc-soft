<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\BudgetAlertLevel;
use App\Enums\BudgetStatus;
use App\Enums\BudgetUtilisationStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\BudgetApproval;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Budgets\BudgetReportService;
use App\Services\Budgets\BudgetService;
use Brick\Math\BigDecimal;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->periods = collect(range(1, 12))->map(function (int $month): AccountingPeriod {
        $start = Carbon::create(2026, $month, 1);

        return AccountingPeriod::factory()->create([
            'fiscal_year_id' => $this->year->id, 'name' => $start->format('F Y'), 'period_number' => $month,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => AccountingPeriodStatus::Open,
        ]);
    });
    [$this->january, $this->february, $this->december] = [$this->periods[0], $this->periods[1], $this->periods[11]];

    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = budgetUser(SystemRole::Accountant, $this->branch);
    $this->approver = budgetUser(SystemRole::Approver, $this->branch);
    $this->auditor = budgetUser(SystemRole::Auditor, $this->branch);
    $this->outsider = budgetUser(SystemRole::Accountant, $this->otherBranch);

    // Class > group > two sub-groups > ledgers, so roll-ups can be checked for double counting.
    $expense = ['account_type' => 'expense', 'financial_statement' => 'income_expenditure'];
    $this->expenseClass = Account::factory()->create([...$expense, 'code' => '500000', 'level' => 'class', 'is_postable' => false]);
    $this->expenseGroup = Account::factory()->create([...$expense, 'code' => '510000', 'level' => 'group', 'is_postable' => false, 'parent_id' => $this->expenseClass->id]);
    $this->premises = Account::factory()->create([...$expense, 'code' => '511000', 'level' => 'sub_group', 'is_postable' => false, 'parent_id' => $this->expenseGroup->id]);
    $this->staffing = Account::factory()->create([...$expense, 'code' => '512000', 'level' => 'sub_group', 'is_postable' => false, 'parent_id' => $this->expenseGroup->id]);
    $this->rent = Account::factory()->create([...$expense, 'code' => '511001', 'name' => 'Rent', 'parent_id' => $this->premises->id]);
    $this->utilities = Account::factory()->create([...$expense, 'code' => '511002', 'name' => 'Utilities', 'parent_id' => $this->premises->id]);
    $this->salaries = Account::factory()->create([...$expense, 'code' => '512001', 'name' => 'Salaries', 'parent_id' => $this->staffing->id]);
    $this->fees = Account::factory()->create(['code' => '411001', 'name' => 'Therapy fees', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->cash = Account::factory()->create(['code' => '111001', 'name' => 'Cash']);

    $this->service = FrcService::factory()->create();
    $this->otherService = FrcService::factory()->create();
    $this->voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();
    $this->actingAs($this->maker);
});

function budgetUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function budgetLine(Account $account, AccountingPeriod $period, string $amount): array
{
    return ['account_id' => $account->id, 'accounting_period_id' => $period->id, 'amount' => $amount];
}

function budgetPayload(object $test, array $lines = [], array $overrides = []): array
{
    return [
        'fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'service_id' => null, 'name' => 'Operating budget',
        'lines' => $lines ?: [budgetLine($test->rent, $test->january, '1000.0000')],
        ...$overrides,
    ];
}

/** Create a budget as the accountant and take it through submission and approval. */
function budgetApproved(object $test, array $lines = [], array $overrides = []): Budget
{
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($test, $lines, $overrides), $test->maker);
    $service->submit($budget, $test->maker);

    return $service->approve($budget->fresh(), $test->approver);
}

/**
 * Build a journal directly in the given status. Lines are added before the
 * journal reaches a posted status because posted journals are immutable.
 *
 * @param  list<array{0: Account, 1: string, 2: string, 3?: ?int}>  $lines  account, debit, credit, service id
 */
function budgetJournal(object $test, array $lines, JournalStatus $status = JournalStatus::Posted, ?AccountingPeriod $period = null, ?Branch $branch = null, array $overrides = []): JournalEntry
{
    $period ??= $test->january;
    $settled = in_array($status, [JournalStatus::Posted, JournalStatus::Reversed], true);
    $total = array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $sum->plus($line[1]), BigDecimal::zero())->toScale(4);
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => $test->voucherType->id, 'transaction_date' => $period->start_date->toDateString(),
        'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id, 'branch_id' => ($branch ?? $test->branch)->id,
        'narration' => 'Budget test journal', 'status' => $settled ? JournalStatus::Approved : $status,
        'total_debit' => (string) $total, 'total_credit' => (string) $total, 'created_by' => $test->maker->id, ...$overrides,
    ]);
    foreach ($lines as $index => $line) {
        $journal->lines()->create(['account_id' => $line[0]->id, 'line_number' => $index + 1, 'debit' => $line[1], 'credit' => $line[2], 'service_id' => $line[3] ?? null]);
    }
    if ($settled) {
        $journal->update(['status' => JournalStatus::Posted, 'posted_by' => $test->approver->id, 'posted_at' => now()]);
    }
    if ($status === JournalStatus::Reversed) {
        $journal->update(['status' => JournalStatus::Reversed, 'reversed_by' => $test->approver->id, 'reversed_at' => now(), 'reversal_reason' => 'Test reversal']);
    }

    return $journal->fresh();
}

/** A posted expense: debit the expense ledger, credit cash. */
function budgetSpend(object $test, Account $account, string $amount, ?AccountingPeriod $period = null, ?int $serviceId = null, ?Branch $branch = null): JournalEntry
{
    return budgetJournal($test, [[$account, $amount, '0', $serviceId], [$test->cash, '0', $amount, null]], JournalStatus::Posted, $period, $branch);
}

function budgetReport(object $test, Budget $budget, ?AccountingPeriod $from = null, ?AccountingPeriod $to = null): array
{
    return app(BudgetReportService::class)->versusActual($budget->fresh(), $test->auditor, $from?->id, $to?->id);
}

function budgetRow(array $report, Account $account, string $key = 'rows'): array
{
    return collect($report[$key])->first(fn (array $row): bool => $row['account']->id === $account->id);
}

function budgetSetWarning(int $percentage): void
{
    AppSetting::query()->where('key', 'budget.warning_percentage')->first()->update(['value' => (string) $percentage]);
}

test('budget is created as a draft with exact decimal totals and no journal entry', function () {
    $budget = app(BudgetService::class)->create(budgetPayload($this, [
        budgetLine($this->rent, $this->january, '100.1111'),
        budgetLine($this->rent, $this->february, '200.2222'),
        budgetLine($this->fees, $this->january, '0.0001'),
    ]), $this->maker);

    expect($budget->status)->toBe(BudgetStatus::Draft)
        ->and($budget->version)->toBe(1)
        ->and($budget->root_budget_id)->toBeNull()
        ->and($budget->total_amount)->toBe('300.3334')
        ->and($budget->service_scope)->toBe('all')
        ->and($budget->active_key)->toBeNull()
        ->and($budget->lines)->toHaveCount(3)
        ->and($budget->approvals()->pluck('action')->all())->toBe(['created'])
        ->and(AuditLog::query()->where('event', 'budgets.created')->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('spread helper divides an annual amount exactly and puts the remainder in the last period', function (string $annual, string $share, string $last) {
    $spread = app(BudgetService::class)->spreadAnnual($this->year, $annual);
    $sum = array_reduce($spread, fn (BigDecimal $carry, string $amount): BigDecimal => $carry->plus($amount), BigDecimal::zero());

    expect(array_keys($spread))->toBe($this->periods->pluck('id')->all())
        ->and(array_values(array_unique(array_slice($spread, 0, 11))))->toBe([$share])
        ->and($spread[$this->december->id])->toBe($last)
        ->and($sum->isEqualTo($annual))->toBeTrue();
})->with([
    'uneven' => ['1000.0000', '83.3333', '83.3337'],
    'even' => ['1200', '100.0000', '100.0000'],
    'tiny' => ['0.0005', '0.0000', '0.0005'],
]);

test('spread helper rejects negative and over precise amounts', function (string $annual) {
    expect(fn () => app(BudgetService::class)->spreadAnnual($this->year, $annual))->toThrow(ValidationException::class);
})->with(['-1', '10.12345', 'abc']);

// Adapted for the JSON grid format: the helper now returns the period amounts of one row as JSON to the form script.
test('spread endpoint returns the exact period amounts for one grid row and saves nothing', function () {
    $this->postJson(route('budgets.spread'), ['fiscal_year_id' => $this->year->id, 'annual_amount' => '1000'])->assertOk()
        ->assertJsonCount(12, 'amounts')
        ->assertJsonPath('amounts.0', ['period_id' => $this->january->id, 'amount' => '83.3333'])
        ->assertJsonPath('amounts.11', ['period_id' => $this->december->id, 'amount' => '83.3337']);
    $this->postJson(route('budgets.spread'), ['fiscal_year_id' => $this->year->id, 'annual_amount' => '10.12345'])->assertStatus(422)->assertJsonValidationErrors('annual_amount');
    $this->postJson(route('budgets.spread'), ['fiscal_year_id' => $this->year->id, 'annual_amount' => '-1'])->assertStatus(422);

    expect(Budget::query()->count())->toBe(0);
});

test('only active postable income or expense ledgers can be budgeted', function (array $attributes) {
    $blocked = Account::factory()->create($attributes);

    expect(fn () => app(BudgetService::class)->create(budgetPayload($this, [budgetLine($blocked, $this->january, '10')]), $this->maker))
        ->toThrow(ValidationException::class);
    expect(Budget::query()->count())->toBe(0);
})->with([
    'group account' => [['account_type' => 'expense', 'level' => 'group', 'is_postable' => false]],
    'sub group account' => [['account_type' => 'expense', 'level' => 'sub_group', 'is_postable' => false]],
    'non postable ledger' => [['account_type' => 'expense', 'is_postable' => false]],
    'inactive ledger' => [['account_type' => 'expense', 'is_active' => false]],
    'asset ledger' => [['account_type' => 'asset']],
    'liability ledger' => [['account_type' => 'liability', 'normal_balance' => 'credit']],
]);

test('periods outside the fiscal year negative amounts and duplicate lines are rejected', function () {
    $otherYear = FiscalYear::factory()->create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
    $foreignPeriod = AccountingPeriod::factory()->create(['fiscal_year_id' => $otherYear->id, 'start_date' => '2027-01-01', 'end_date' => '2027-01-31']);
    $service = app(BudgetService::class);

    expect(fn () => $service->create(budgetPayload($this, [budgetLine($this->rent, $foreignPeriod, '10')]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(budgetPayload($this, [budgetLine($this->rent, $this->january, '-0.0001')]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(budgetPayload($this, [budgetLine($this->rent, $this->january, '1.00001')]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(budgetPayload($this, [budgetLine($this->rent, $this->january, '1'), budgetLine($this->rent, $this->january, '2')]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(budgetPayload($this, [], ['lines' => []]), $this->maker))->toThrow(ValidationException::class)
        ->and(Budget::query()->count())->toBe(0);
});

test('a closed fiscal year cannot be budgeted', function () {
    $closed = FiscalYear::factory()->create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed]);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $closed->id, 'start_date' => '2025-01-01', 'end_date' => '2025-01-31']);

    expect(fn () => app(BudgetService::class)->create(budgetPayload($this, [budgetLine($this->rent, $period, '10')], ['fiscal_year_id' => $closed->id]), $this->maker))
        ->toThrow(ValidationException::class);
});

test('budget is submitted and approved by a different authorized user', function () {
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($this), $this->maker);
    $service->submit($budget, $this->maker);

    expect($budget->fresh()->status)->toBe(BudgetStatus::Submitted)
        ->and($service->canApprove($budget->fresh(), $this->maker))->toBeFalse()
        ->and($service->canApprove($budget->fresh(), $this->approver))->toBeTrue();

    $approved = $service->approve($budget->fresh(), $this->approver, 'Agreed with the board');

    expect($approved->status)->toBe(BudgetStatus::Approved)
        ->and($approved->active_key)->toBe('1')
        ->and($approved->approved_by)->toBe($this->approver->id)
        ->and($approved->submitted_by)->toBe($this->maker->id)
        ->and($approved->approvals()->pluck('action')->all())->toBe(['created', 'submitted', 'approved'])
        ->and(AuditLog::query()->whereIn('event', ['budgets.submitted', 'budgets.approved'])->count())->toBe(2);
});

test('maker checker stops the creator and the submitter from approving or rejecting', function () {
    $service = app(BudgetService::class);
    $creator = budgetUser(SystemRole::Administrator, $this->branch);
    $submitter = budgetUser(SystemRole::Administrator, $this->branch);
    $budget = $service->create(budgetPayload($this), $creator);
    $service->submit($budget, $submitter);

    expect(fn () => $service->approve($budget->fresh(), $creator))->toThrow(ValidationException::class)
        ->and(fn () => $service->approve($budget->fresh(), $submitter))->toThrow(ValidationException::class)
        ->and(fn () => $service->reject($budget->fresh(), 'Not acceptable', $creator))->toThrow(ValidationException::class)
        ->and($budget->fresh()->status)->toBe(BudgetStatus::Submitted);

    expect($service->approve($budget->fresh(), $this->approver)->status)->toBe(BudgetStatus::Approved);
});

test('a draft cannot be approved and a submitted budget cannot be edited', function () {
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($this), $this->maker);

    expect(fn () => $service->approve($budget, $this->approver))->toThrow(ValidationException::class);
    $service->submit($budget, $this->maker);
    expect(fn () => $service->update($budget->fresh(), budgetPayload($this), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->submit($budget->fresh(), $this->maker))->toThrow(ValidationException::class);
});

test('a rejected budget needs a reason and can be corrected and resubmitted', function () {
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($this), $this->maker);
    $service->submit($budget, $this->maker);

    $this->actingAs($this->approver)->post(route('budgets.reject', $budget), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->approver)->post(route('budgets.reject', $budget), ['reason' => 'Rent is understated'])->assertSessionHasNoErrors();
    expect($budget->fresh()->status)->toBe(BudgetStatus::Rejected)
        ->and($budget->fresh()->rejection_reason)->toBe('Rent is understated');

    $updated = $service->update($budget->fresh(), budgetPayload($this, [budgetLine($this->rent, $this->january, '1500.5000')]), $this->maker);
    expect($updated->status)->toBe(BudgetStatus::Draft)
        ->and($updated->rejection_reason)->toBeNull()
        ->and($updated->total_amount)->toBe('1500.5000');

    $service->submit($updated, $this->maker);
    expect($service->approve($updated->fresh(), $this->approver)->status)->toBe(BudgetStatus::Approved)
        ->and($budget->approvals()->pluck('action')->all())->toBe(['created', 'submitted', 'rejected', 'updated', 'submitted', 'approved']);
});

test('an approved budget is immutable in the service and in the models', function () {
    $budget = budgetApproved($this);
    $line = $budget->lines()->first();

    expect(fn () => app(BudgetService::class)->update($budget->fresh(), budgetPayload($this, [budgetLine($this->rent, $this->january, '5')]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $budget->fresh()->update(['name' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $budget->fresh()->update(['total_amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $budget->fresh()->update(['status' => BudgetStatus::Draft]))->toThrow(LogicException::class)
        ->and(fn () => $budget->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->update(['amount' => '5.0000']))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class)
        ->and(fn () => $budget->lines()->create(budgetLine($this->salaries, $this->january, '5.0000')))->toThrow(LogicException::class)
        ->and($budget->fresh()->total_amount)->toBe('1000.0000')
        ->and($budget->lines()->count())->toBe(1);

    $this->actingAs($this->maker)->get(route('budgets.edit', $budget))->assertForbidden();
});

test('approval history is append only', function () {
    $budget = budgetApproved($this);
    $entry = BudgetApproval::query()->where('budget_id', $budget->id)->firstOrFail();

    expect(fn () => $entry->update(['comments' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(BudgetApproval::query()->where('budget_id', $budget->id)->count())->toBe(3);
});

test('revision creates version two and approving it supersedes version one with history preserved', function () {
    $service = app(BudgetService::class);
    $first = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000'), budgetLine($this->salaries, $this->january, '500')]);

    expect(fn () => $service->revise($first->fresh(), '   ', $this->maker))->toThrow(ValidationException::class);
    $second = $service->revise($first->fresh(), 'Rent increased from March', $this->maker);

    expect($second->version)->toBe(2)
        ->and($second->root_budget_id)->toBe($first->id)
        ->and($second->status)->toBe(BudgetStatus::Draft)
        ->and($second->revision_reason)->toBe('Rent increased from March')
        ->and($second->total_amount)->toBe('1500.0000')
        ->and($second->lines)->toHaveCount(2)
        ->and($first->fresh()->status)->toBe(BudgetStatus::Approved)
        ->and(fn () => $service->revise($first->fresh(), 'A second revision at once', $this->maker))->toThrow(ValidationException::class);

    $service->update($second, budgetPayload($this, [budgetLine($this->rent, $this->january, '1200'), budgetLine($this->salaries, $this->january, '500')], ['branch_id' => $this->otherBranch->id]), $this->maker);
    $service->submit($second->fresh(), $this->maker);
    // The approved version stays in force until the revision itself is approved.
    expect($first->fresh()->status)->toBe(BudgetStatus::Approved)
        ->and(app(BudgetReportService::class)->approvedBudget($this->year->id, $this->branch->id, null)->id)->toBe($first->id);

    $approved = $service->approve($second->fresh(), $this->approver);
    $first = $first->fresh();

    expect($approved->status)->toBe(BudgetStatus::Approved)
        ->and($approved->branch_id)->toBe($this->branch->id)
        ->and($approved->total_amount)->toBe('1700.0000')
        ->and($first->status)->toBe(BudgetStatus::Superseded)
        ->and($first->active_key)->toBeNull()
        ->and($first->total_amount)->toBe('1500.0000')
        ->and($first->lines()->count())->toBe(2)
        ->and($first->approvals()->pluck('action')->all())->toBe(['created', 'submitted', 'approved', 'revised', 'superseded'])
        ->and($approved->versions()->pluck('version')->all())->toBe([1, 2])
        ->and(Budget::query()->where('status', 'approved')->count())->toBe(1)
        ->and(app(BudgetReportService::class)->approvedBudget($this->year->id, $this->branch->id, null)->id)->toBe($approved->id)
        ->and(AuditLog::query()->whereIn('event', ['budgets.revised', 'budgets.superseded'])->count())->toBe(2)
        ->and(fn () => $first->update(['name' => 'Rewritten history']))->toThrow(LogicException::class)
        ->and(fn () => $service->revise($first, 'Revising a superseded version', $this->maker))->toThrow(ValidationException::class);

    $third = $service->revise($approved->fresh(), 'Second revision of the year', $this->maker);
    expect($third->version)->toBe(3)->and($third->root_budget_id)->toBe($first->id);
});

test('only one approved budget can exist per fiscal year branch and service scope', function () {
    $service = app(BudgetService::class);
    budgetApproved($this);
    $competing = $service->create(budgetPayload($this, [], ['name' => 'Second chain']), $this->maker);

    expect(fn () => $service->submit($competing, $this->maker))->toThrow(ValidationException::class);

    // A service budget and another branch are different scopes.
    $serviceBudget = budgetApproved($this, [], ['service_id' => $this->service->id, 'name' => 'Service budget']);
    $this->maker->branches()->attach($this->otherBranch);
    $this->approver->branches()->attach($this->otherBranch);
    $branchBudget = budgetApproved($this, [], ['branch_id' => $this->otherBranch->id, 'name' => 'Other branch budget']);

    expect($serviceBudget->service_scope)->toBe('service:'.$this->service->id)
        ->and($branchBudget->status)->toBe(BudgetStatus::Approved)
        ->and(Budget::query()->where('status', 'approved')->count())->toBe(3);
});

test('the database itself refuses a second approved budget in the same scope', function () {
    $attributes = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'created_by' => $this->maker->id];
    Budget::factory()->count(2)->create([...$attributes, 'status' => BudgetStatus::Draft]);
    Budget::factory()->create([...$attributes, 'status' => BudgetStatus::Approved]);
    Budget::factory()->create([...$attributes, 'status' => BudgetStatus::Approved, 'service_id' => $this->service->id]);

    expect(fn () => Budget::factory()->create([...$attributes, 'status' => BudgetStatus::Approved]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Budget::factory()->create([...$attributes, 'status' => BudgetStatus::Approved, 'service_id' => $this->service->id]))->toThrow(UniqueConstraintViolationException::class);
});

test('actual comes from posted journals only', function () {
    $budget = budgetApproved($this);
    budgetSpend($this, $this->rent, '300.2500');
    foreach ([JournalStatus::Draft, JournalStatus::Submitted, JournalStatus::Approved, JournalStatus::Rejected] as $status) {
        budgetJournal($this, [[$this->rent, '55.0000', '0'], [$this->cash, '0', '55.0000']], $status);
    }

    $row = budgetRow(budgetReport($this, $budget), $this->rent);

    expect($row['actual'])->toBe('300.2500')
        ->and($row['budget'])->toBe('1000.0000')
        ->and(JournalEntry::query()->count())->toBe(5);
});

test('a reversed journal and its reversal net to zero in actual', function () {
    $budget = budgetApproved($this);
    budgetSpend($this, $this->rent, '120.0000');
    $original = budgetJournal($this, [[$this->rent, '400.0000', '0'], [$this->cash, '0', '400.0000']], JournalStatus::Reversed);
    budgetJournal($this, [[$this->rent, '0', '400.0000'], [$this->cash, '400.0000', '0']], JournalStatus::Posted, null, null, ['reversal_of_id' => $original->id]);

    expect($original->status)->toBe(JournalStatus::Reversed)
        ->and(budgetRow(budgetReport($this, $budget), $this->rent)['actual'])->toBe('120.0000');
});

test('actual is limited to the branch the periods and the service of the budget', function () {
    $whole = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000'), budgetLine($this->rent, $this->february, '1000')]);
    $forService = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000')], ['service_id' => $this->service->id, 'name' => 'Service budget']);
    budgetSpend($this, $this->rent, '100.0000', $this->january, $this->service->id);
    budgetSpend($this, $this->rent, '40.0000', $this->january, $this->otherService->id);
    budgetSpend($this, $this->rent, '7.0000', $this->january);
    budgetSpend($this, $this->rent, '30.0000', $this->february, $this->service->id);
    budgetSpend($this, $this->rent, '999.0000', $this->january, $this->service->id, $this->otherBranch);

    expect(budgetRow(budgetReport($this, $whole, $this->january, $this->january), $this->rent)['actual'])->toBe('147.0000')
        ->and(budgetRow(budgetReport($this, $whole), $this->rent)['actual'])->toBe('177.0000')
        ->and(budgetRow(budgetReport($this, $whole), $this->rent)['budget'])->toBe('2000.0000')
        ->and(budgetRow(budgetReport($this, $forService, $this->january, $this->january), $this->rent)['actual'])->toBe('100.0000')
        ->and(budgetRow(budgetReport($this, $forService), $this->rent)['actual'])->toBe('130.0000');
});

test('remaining variance and utilisation are calculated exactly', function () {
    $budget = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000'), budgetLine($this->fees, $this->january, '3000')]);
    budgetSpend($this, $this->rent, '250.5000');
    budgetJournal($this, [[$this->rent, '0', '0.5000'], [$this->cash, '0.5000', '0']]);
    budgetJournal($this, [[$this->cash, '1000.0000', '0'], [$this->fees, '0', '1000.0000']]);

    $report = budgetReport($this, $budget);
    $rent = budgetRow($report, $this->rent);
    $fees = budgetRow($report, $this->fees);

    expect($rent)->toMatchArray(['budget' => '1000.0000', 'actual' => '250.0000', 'remaining' => '750.0000', 'variance' => '-750.0000', 'utilisation' => '25.00', 'status' => BudgetUtilisationStatus::Within])
        ->and($fees)->toMatchArray(['budget' => '3000.0000', 'actual' => '1000.0000', 'remaining' => '2000.0000', 'variance' => '-2000.0000', 'utilisation' => '33.33', 'status' => BudgetUtilisationStatus::IncomeTarget])
        ->and($report['totals']['expense'])->toMatchArray(['budget' => '1000.0000', 'actual' => '250.0000', 'remaining' => '750.0000', 'variance' => '-750.0000', 'utilisation' => '25.00'])
        ->and($report['totals']['income'])->toMatchArray(['budget' => '3000.0000', 'actual' => '1000.0000'])
        ->and($rent['periods'])->toHaveCount(1)
        ->and($rent['periods'][0]['period']->id)->toBe($this->january->id);
});

test('the warning threshold comes from the setting and is not hardcoded', function () {
    $budget = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000'), budgetLine($this->salaries, $this->january, '1000')]);
    budgetSpend($this, $this->rent, '800.0000');
    budgetSpend($this, $this->salaries, '799.9900');

    $report = app(BudgetReportService::class)->versusActual($budget, $this->auditor, null, null, false);
    expect($report['warning_percentage'])->toBe(80)
        ->and(budgetRow($report, $this->rent)['status'])->toBe(BudgetUtilisationStatus::Warning)
        ->and(budgetRow($report, $this->salaries)['status'])->toBe(BudgetUtilisationStatus::Within);

    budgetSetWarning(90);
    $report = app(BudgetReportService::class)->versusActual($budget, $this->auditor, null, null, false);
    expect($report['warning_percentage'])->toBe(90)
        ->and(budgetRow($report, $this->rent)['status'])->toBe(BudgetUtilisationStatus::Within);

    budgetSetWarning(50);
    $report = app(BudgetReportService::class)->versusActual($budget, $this->auditor, null, null, false);
    expect(budgetRow($report, $this->rent)['status'])->toBe(BudgetUtilisationStatus::Warning)
        ->and(budgetRow($report, $this->salaries)['status'])->toBe(BudgetUtilisationStatus::Warning)
        ->and(BudgetAlert::query()->count())->toBe(0);
});

test('over budget starts above one hundred percent and zero budget spend is flagged without dividing by zero', function () {
    $budget = budgetApproved($this, [
        budgetLine($this->rent, $this->january, '1000'), budgetLine($this->salaries, $this->january, '1000'), budgetLine($this->utilities, $this->january, '0'),
    ]);
    budgetSpend($this, $this->rent, '1000.0000');
    budgetSpend($this, $this->salaries, '1000.0100');
    budgetSpend($this, $this->utilities, '25.0000');

    $report = budgetReport($this, $budget);

    expect(budgetRow($report, $this->rent))->toMatchArray(['utilisation' => '100.00', 'status' => BudgetUtilisationStatus::Warning])
        ->and(budgetRow($report, $this->salaries))->toMatchArray(['utilisation' => '100.00', 'status' => BudgetUtilisationStatus::Over, 'remaining' => '-0.0100', 'variance' => '0.0100'])
        ->and(budgetRow($report, $this->utilities))->toMatchArray(['budget' => '0.0000', 'actual' => '25.0000', 'utilisation' => null, 'status' => BudgetUtilisationStatus::Unbudgeted]);
});

test('spend in a period without a budget line is reported as unbudgeted', function () {
    $budget = budgetApproved($this);
    budgetSpend($this, $this->rent, '60.0000', $this->february);

    $report = budgetReport($this, $budget, $this->february, $this->february);
    $row = budgetRow($report, $this->rent);

    expect($row)->toMatchArray(['budget' => '0.0000', 'actual' => '60.0000', 'status' => BudgetUtilisationStatus::Unbudgeted, 'utilisation' => null])
        ->and($row['periods'][0]['has_budget_line'])->toBeFalse();
});

test('an overrun is recorded once in budget alerts with an audit entry', function () {
    $budget = budgetApproved($this, [budgetLine($this->rent, $this->january, '100'), budgetLine($this->salaries, $this->january, '100')]);
    budgetSpend($this, $this->rent, '150.0000');

    $first = budgetReport($this, $budget, $this->january, $this->january);
    $second = budgetReport($this, $budget, $this->january, $this->january);
    $alert = BudgetAlert::query()->sole();

    expect($first['alerts_recorded'])->toBe(1)
        ->and($second['alerts_recorded'])->toBe(0)
        ->and($alert->level)->toBe(BudgetAlertLevel::Overrun)
        ->and($alert->account_id)->toBe($this->rent->id)
        ->and($alert->accounting_period_id)->toBe($this->january->id)
        ->and($alert->utilisation_percentage)->toBe('150.00')
        ->and($alert->actual_amount)->toBe('150.0000')
        ->and($alert->budget_amount)->toBe('100.0000')
        ->and($alert->warning_percentage)->toBe(80)
        ->and($alert->detected_by)->toBe($this->auditor->id)
        ->and(AuditLog::query()->where('event', 'budgets.alert_recorded')->count())->toBe(1)
        ->and(fn () => $alert->update(['actual_amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $alert->delete())->toThrow(LogicException::class);

    // A range from the first period also records one year-to-date alert, with no period.
    budgetReport($this, $budget);
    budgetReport($this, $budget);
    $yearToDate = BudgetAlert::query()->whereNull('accounting_period_id')->sole();

    expect(BudgetAlert::query()->count())->toBe(2)
        ->and($yearToDate->period_scope)->toBe('ytd:'.$this->december->id)
        ->and($yearToDate->level)->toBe(BudgetAlertLevel::Overrun);
});

test('a warning and a later overrun are separate alerts and nothing is ever blocked', function () {
    $budget = budgetApproved($this, [budgetLine($this->rent, $this->january, '100')]);
    budgetSpend($this, $this->rent, '85.0000');
    budgetReport($this, $budget, $this->january, $this->january);

    expect(BudgetAlert::query()->sole()->level)->toBe(BudgetAlertLevel::Warning);

    // Spending past the budget is still accepted by the ledger: there is no hard stop.
    budgetSpend($this, $this->rent, '500.0000');
    budgetReport($this, $budget, $this->january, $this->january);

    expect(BudgetAlert::query()->orderBy('id')->pluck('level')->all())->toBe([BudgetAlertLevel::Warning, BudgetAlertLevel::Overrun])
        ->and(budgetRow(budgetReport($this, $budget, $this->january, $this->january), $this->rent)['actual'])->toBe('585.0000');
});

test('hierarchy summaries add each ledger exactly once', function () {
    $budget = budgetApproved($this, [
        budgetLine($this->rent, $this->january, '100'), budgetLine($this->rent, $this->february, '100'),
        budgetLine($this->utilities, $this->january, '50'), budgetLine($this->salaries, $this->january, '200'),
    ]);
    budgetSpend($this, $this->rent, '40.0000');
    budgetSpend($this, $this->salaries, '10.0000', $this->february);

    $report = budgetReport($this, $budget);
    $figures = fn (Account $account): array => array_map(fn (string $key): mixed => budgetRow($report, $account, 'hierarchy')[$key], ['budget' => 'budget', 'actual' => 'actual', 'depth' => 'depth', 'is_ledger' => 'is_ledger']);
    $ledgerBudget = collect($report['rows'])->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['budget']), BigDecimal::zero());

    expect($figures($this->expenseClass))->toBe(['budget' => '450.0000', 'actual' => '50.0000', 'depth' => 0, 'is_ledger' => false])
        ->and($figures($this->expenseGroup))->toBe(['budget' => '450.0000', 'actual' => '50.0000', 'depth' => 1, 'is_ledger' => false])
        ->and($figures($this->premises))->toBe(['budget' => '250.0000', 'actual' => '40.0000', 'depth' => 2, 'is_ledger' => false])
        ->and($figures($this->staffing))->toBe(['budget' => '200.0000', 'actual' => '10.0000', 'depth' => 2, 'is_ledger' => false])
        ->and($figures($this->rent))->toBe(['budget' => '200.0000', 'actual' => '40.0000', 'depth' => 3, 'is_ledger' => true])
        ->and($report['totals']['expense']['budget'])->toBe('450.0000')
        ->and($report['totals']['expense']['actual'])->toBe('50.0000')
        ->and((string) $ledgerBudget->toScale(4))->toBe($budget->total_amount)
        ->and(collect($report['hierarchy'])->pluck('account.code')->all())->toBe(['500000', '510000', '511000', '511001', '511002', '512000', '512001'])
        ->and(budgetRow($report, $this->expenseClass, 'hierarchy')['ledger_count'])->toBe(3);
});

test('purchase order commitments are shown separately and never added to actual', function () {
    $budget = budgetApproved($this);
    $makeOrder = function (string $status, Branch $branch, string $quantity = '10.0000'): PurchaseOrderLine {
        $order = PurchaseOrder::factory()->create(['branch_id' => $branch->id, 'po_date' => '2026-03-05', 'total_amount' => '500.0000', 'subtotal' => '500.0000', 'created_by' => $this->maker->id]);
        $line = $order->lines()->create(['line_number' => 1, 'description' => 'Rent deposit', 'quantity' => $quantity, 'unit_price' => '50.0000', 'line_total' => '500.0000', 'account_id' => $this->rent->id]);
        $order->update(['status' => $status]);

        return $line;
    };
    $approved = $makeOrder('approved', $this->branch);
    $makeOrder('issued', $this->branch);
    $makeOrder('draft', $this->branch);
    $makeOrder('cancelled', $this->branch);
    $makeOrder('approved', $this->otherBranch);

    $row = budgetRow(budgetReport($this, $budget), $this->rent);
    expect($row['commitments'])->toBe('1000.0000')
        ->and($row['actual'])->toBe('0.0000')
        ->and($row['remaining'])->toBe('1000.0000')
        ->and($row['status'])->toBe(BudgetUtilisationStatus::Within);

    // Billing four of ten units leaves six tenths of the line as commitment.
    $bill = PurchaseBill::factory()->create(['branch_id' => $this->branch->id, 'purchase_order_id' => $approved->purchase_order_id, 'status' => 'posted', 'total_amount' => '200.0000', 'created_by' => $this->maker->id]);
    PurchaseBillLine::query()->create(['purchase_bill_id' => $bill->id, 'account_id' => $this->rent->id, 'purchase_order_line_id' => $approved->id, 'line_number' => 1, 'description' => 'Rent deposit', 'quantity' => '4.0000', 'unit_amount' => '50.0000', 'line_total' => '200.0000']);

    $report = budgetReport($this, $budget);
    expect(budgetRow($report, $this->rent)['commitments'])->toBe('800.0000')
        ->and(budgetRow($report, $this->rent)['actual'])->toBe('0.0000')
        ->and($report['totals']['expense']['commitments'])->toBe('800.0000')
        ->and($report['totals']['expense']['actual'])->toBe('0.0000')
        ->and(BudgetAlert::query()->count())->toBe(0);
});

test('users only see and act on budgets of branches they can access', function () {
    $budget = budgetApproved($this);
    $service = app(BudgetService::class);
    $reports = app(BudgetReportService::class);
    $outsideAuditor = budgetUser(SystemRole::Auditor, $this->otherBranch);
    $outsideApprover = budgetUser(SystemRole::Approver, $this->otherBranch);
    $pending = $service->create(budgetPayload($this, [], ['service_id' => $this->service->id, 'name' => 'Pending service budget']), $this->maker);
    $service->submit($pending, $this->maker);

    $this->actingAs($this->outsider)->get(route('budgets.show', $budget))->assertForbidden();
    $this->actingAs($this->outsider)->get(route('budgets.index'))->assertOk()->assertDontSee('Operating budget');
    $this->actingAs($this->maker)->get(route('budgets.index'))->assertOk()->assertSee('Operating budget');
    $this->actingAs($this->outsider)->post(route('budgets.revise', $budget), ['reason' => 'Not my branch'])->assertForbidden();
    $this->actingAs($outsideApprover)->post(route('budgets.approve', $pending))->assertForbidden();
    $this->actingAs($outsideAuditor)->get(route('budget-reports.versus-actual', ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id]))->assertForbidden();
    $this->actingAs($outsideAuditor)->get(route('budget-reports.branch-summary', ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id]))->assertForbidden();
    $this->actingAs($outsideAuditor)->get(route('budget-reports.branch-summary', ['fiscal_year_id' => $this->year->id]))->assertOk()->assertDontSee('Operating budget')->assertDontSee($this->branch->name);

    expect(fn () => $service->create(budgetPayload($this), $this->outsider))->toThrow(HttpException::class)
        ->and(fn () => $service->submit($pending->fresh(), $this->outsider))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($pending->fresh(), $outsideApprover))->toThrow(HttpException::class)
        ->and(fn () => $service->revise($budget->fresh(), 'Not my branch', $this->outsider))->toThrow(HttpException::class)
        ->and(fn () => $reports->versusActual($budget->fresh(), $outsideAuditor))->toThrow(HttpException::class)
        ->and($reports->summary($this->year, $outsideAuditor, Branch::query()->get(), false))->toHaveCount(1)
        ->and($pending->fresh()->status)->toBe(BudgetStatus::Submitted)
        ->and(Budget::query()->count())->toBe(2);
});

test('users without the permission are rejected', function () {
    $budget = budgetApproved($this);
    $service = app(BudgetService::class);
    $noRole = User::factory()->create();
    $noRole->branches()->attach($this->branch);
    $cashier = budgetUser(SystemRole::Cashier, $this->branch);
    $draft = $service->create(budgetPayload($this, [], ['service_id' => $this->service->id]), $this->maker);

    foreach ([$noRole, $cashier] as $user) {
        $this->actingAs($user)->get(route('budgets.index'))->assertForbidden();
        $this->actingAs($user)->get(route('budgets.show', $budget))->assertForbidden();
        $this->actingAs($user)->get(route('budget-reports.versus-actual'))->assertForbidden();
        $this->actingAs($user)->get(route('budget-reports.utilisation'))->assertForbidden();
    }
    // Read-only and approving roles cannot prepare; the preparer cannot approve.
    $this->actingAs($this->auditor)->get(route('budgets.create'))->assertForbidden();
    $this->actingAs($this->auditor)->post(route('budgets.store'), [])->assertForbidden();
    $this->actingAs($this->auditor)->post(route('budgets.revise', $budget), ['reason' => 'Auditor revision'])->assertForbidden();
    $this->actingAs($this->approver)->post(route('budgets.submit', $draft))->assertForbidden();
    $this->actingAs($this->maker)->post(route('budgets.approve', $draft))->assertForbidden();

    expect(fn () => $service->create(budgetPayload($this), $this->auditor))->toThrow(HttpException::class)
        ->and(fn () => $service->update($draft->fresh(), budgetPayload($this), $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->submit($draft->fresh(), $this->auditor))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($draft->fresh(), $this->maker))->toThrow(HttpException::class)
        ->and(fn () => $service->reject($draft->fresh(), 'No authority', $this->maker))->toThrow(HttpException::class)
        ->and(fn () => $service->revise($budget->fresh(), 'No authority', $this->approver))->toThrow(HttpException::class)
        ->and(fn () => app(BudgetReportService::class)->versusActual($budget->fresh(), $cashier))->toThrow(HttpException::class);

    $this->maker->update(['is_active' => false]);
    expect(fn () => $service->create(budgetPayload($this, [], ['name' => 'Inactive maker']), $this->maker->fresh()))->toThrow(HttpException::class);
});

test('no budget action or report ever creates a journal entry', function () {
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($this), $this->maker);
    $service->update($budget, budgetPayload($this, [budgetLine($this->rent, $this->january, '900')]), $this->maker);
    $service->submit($budget->fresh(), $this->maker);
    $service->reject($budget->fresh(), 'Please correct', $this->approver);
    $service->update($budget->fresh(), budgetPayload($this, [budgetLine($this->rent, $this->january, '950')]), $this->maker);
    $service->submit($budget->fresh(), $this->maker);
    $service->approve($budget->fresh(), $this->approver);
    $revision = $service->revise($budget->fresh(), 'Mid-year revision', $this->maker);
    $service->submit($revision, $this->maker);
    $service->approve($revision->fresh(), $this->approver);
    budgetReport($this, $revision);
    $this->actingAs($this->auditor)->get(route('budget-reports.utilisation', ['fiscal_year_id' => $this->year->id]))->assertOk();

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0)
        ->and(BudgetApproval::query()->count())->toBe(12);
});

// Adapted for the JSON grid format: the grid is posted as one `lines_json` document instead of rows[..][amounts][..].
test('budget is saved and updated through the grid form with server side totals', function () {
    $grid = fn (array $lines): string => json_encode(array_map(fn (array $line): array => ['account_id' => $line[0]->id, 'period_id' => $line[1]->id, 'amount' => $line[2]], $lines));
    $this->post(route('budgets.store'), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'name' => 'Grid budget', 'total_amount' => '999999',
        'lines_json' => $grid([[$this->rent, $this->january, '100.5000'], [$this->rent, $this->december, '0'], [$this->salaries, $this->february, '200.2500']]),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $budget = Budget::query()->sole();
    expect($budget->total_amount)->toBe('300.7500')
        ->and($budget->service_id)->toBe($this->service->id)
        ->and($budget->created_by)->toBe($this->maker->id)
        ->and($budget->lines()->count())->toBe(3);

    $this->put(route('budgets.update', $budget), [
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'name' => 'Grid budget revised',
        'lines_json' => $grid([[$this->rent, $this->january, '75.0001']]),
    ])->assertSessionHasNoErrors()->assertRedirect(route('budgets.show', $budget));

    expect($budget->fresh()->total_amount)->toBe('75.0001')
        ->and($budget->fresh()->name)->toBe('Grid budget revised')
        ->and($budget->fresh()->service_id)->toBeNull()
        ->and(BudgetLine::query()->count())->toBe(1);

    $this->post(route('budgets.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'name' => 'Bad', 'lines_json' => $grid([[$this->cash, $this->january, '10']])])
        ->assertSessionHasErrors('lines.0.account_id');
    $this->post(route('budgets.store'), ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'name' => 'Bad', 'lines_json' => $grid([[$this->rent, $this->january, '-5']])])
        ->assertSessionHasErrors('lines.0.amount');
    expect(Budget::query()->count())->toBe(1);
});

test('reports show an explicit empty state when no approved budget exists', function () {
    $draft = app(BudgetService::class)->create(budgetPayload($this), $this->maker);
    budgetSpend($this, $this->rent, '75.0000');
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];

    expect(app(BudgetReportService::class)->approvedBudget($this->year->id, $this->branch->id, null))->toBeNull();
    $this->actingAs($this->auditor)->get(route('budget-reports.versus-actual', $query))->assertOk()
        ->assertSee('No approved budget for this selection')->assertDontSee('Budget allocation')->assertDontSee('75.00');
    $this->actingAs($this->auditor)->get(route('budget-reports.versus-actual'))->assertOk()->assertSee('Select a fiscal year and branch');
    $this->actingAs($this->auditor)->get(route('budget-reports.branch-summary', $query))->assertOk()->assertSee('No approved branch budget')->assertDontSee('75.00');
    $this->actingAs($this->auditor)->get(route('budget-reports.service-summary', $query))->assertOk()->assertSee('No approved service budget');
    $this->actingAs($this->auditor)->get(route('budget-reports.utilisation', $query))->assertOk()->assertSee('No approved budget for FY 2026');

    expect($draft->fresh()->status)->toBe(BudgetStatus::Draft)->and(BudgetAlert::query()->count())->toBe(0);
});

test('budget screens render for authorized users', function () {
    $budget = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000'), budgetLine($this->fees, $this->january, '2500')]);
    $serviceBudget = budgetApproved($this, [budgetLine($this->salaries, $this->january, '400')], ['service_id' => $this->service->id, 'name' => 'Therapy service budget']);
    $draft = app(BudgetService::class)->revise($budget->fresh(), 'Rent renegotiated', $this->maker);
    budgetSpend($this, $this->rent, '1250.0000');
    budgetSpend($this, $this->salaries, '100.0000', $this->january, $this->service->id);
    $unbudgeted = Branch::factory()->create(['status' => 'publish']);
    $this->auditor->branches()->attach($unbudgeted);
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];

    $this->actingAs($this->maker)->get(route('budgets.index', ['status' => 'approved', 'search' => 'Operating']))->assertOk()->assertSee('Operating budget')->assertDontSee('Therapy service budget');
    $this->get(route('budgets.index', ['service_id' => $this->service->id]))->assertOk()->assertSee('Therapy service budget')->assertDontSee('Operating budget');
    $this->get(route('budgets.create'))->assertOk()->assertSee('Budget lines')->assertSee('January 2026')->assertSee('Spread annual amount');
    $this->get(route('budgets.edit', $draft))->assertOk()->assertSee('Rent renegotiated')->assertSee('1000.0000');
    $this->get(route('budgets.show', $budget))->assertOk()->assertSee('Version history')->assertSee('Approval history')->assertSee('A revision is already in progress');
    $this->get(route('budgets.show', $draft))->assertOk()->assertSee('Submit for approval')->assertSee('Rent renegotiated');

    $this->actingAs($this->auditor)->get(route('budget-reports.versus-actual', $query))->assertOk()
        ->assertSee('Budget allocation')->assertSee('Remaining')->assertSee('Variance')->assertSee('Utilisation %')
        ->assertSee('PO commitments (not in Actual)')->assertSee('Over budget')->assertSee('125.00%')->assertSee('Total expenses (each ledger once)')->assertSee('Total income (each ledger once)');
    $this->get(route('budget-reports.versus-actual', [...$query, 'service_id' => $this->service->id, 'from_period_id' => $this->january->id, 'to_period_id' => $this->february->id]))->assertOk()->assertSee('Therapy service budget')->assertSee('25.00%');
    $this->get(route('budget-reports.branch-summary', ['fiscal_year_id' => $this->year->id]))->assertOk()->assertSee('Operating budget')->assertSee('Over budget')->assertSee('No approved whole-branch budget')->assertSee($unbudgeted->name)->assertDontSee($this->otherBranch->name);
    $this->get(route('budget-reports.service-summary'))->assertOk()->assertSee('Therapy service budget')->assertSee($this->service->name);
    $this->get(route('budget-reports.utilisation'))->assertOk()->assertSee('Ledgers needing attention')->assertSee('Alert log')->assertSee('Overrun')->assertSee('Rent');

    $this->actingAs($this->approver)->get(route('budgets.show', $serviceBudget))->assertOk()->assertDontSee('Revise budget');
    expect(BudgetAlert::query()->where('level', 'overrun')->where('account_id', $this->rent->id)->exists())->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(2);
});

// --- Milestone 8: version selection, hierarchy layout and exports -------------

function budgetExport(object $test, string $route, array $query, string $format = 'csv'): string
{
    $response = $test->get(route($route, ['format' => $format, ...$query]))->assertOk();

    return $response->streamedContent();
}

/** An approved whole-branch budget of the other branch, prepared and approved by that branch's own users. */
function budgetForeignApproved(object $test, string $amount): Budget
{
    $service = app(BudgetService::class);
    $budget = $service->create(budgetPayload($test, [budgetLine($test->rent, $test->january, $amount)], ['branch_id' => $test->otherBranch->id, 'name' => 'Other branch budget']), $test->outsider);
    $service->submit($budget, $test->outsider);

    return $service->approve($budget->fresh(), budgetUser(SystemRole::Approver, $test->otherBranch));
}

/** The cells of the first CSV row whose first cell (trimmed) equals the label. */
function budgetCsvRow(string $csv, string $label): ?array
{
    foreach (preg_split('/\r\n|\n/', $csv) as $line) {
        $cells = str_getcsv($line);
        if (trim((string) ($cells[0] ?? '')) === $label) {
            return $cells;
        }
    }

    return null;
}

test('budget vs actual defaults to the approved version and can show a labelled superseded version', function () {
    $service = app(BudgetService::class);
    $first = budgetApproved($this, [budgetLine($this->rent, $this->january, '1000')]);
    $revision = $service->revise($first->fresh(), 'Rent renegotiated', $this->maker);
    $service->update($revision, budgetPayload($this, [budgetLine($this->rent, $this->january, '1500')]), $this->maker);
    $service->submit($revision->fresh(), $this->maker);
    $service->approve($revision->fresh(), $this->approver);
    $draft = $service->revise($revision->fresh(), 'Next draft revision', $this->maker);
    $foreign = budgetForeignApproved($this, '7777');
    budgetSpend($this, $this->rent, '1200.0000');
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];
    $reports = app(BudgetReportService::class);

    expect($reports->reportableVersions($this->year->id, $this->branch->id, null)->pluck('id')->all())->toBe([$revision->id, $first->id]);

    $this->actingAs($this->auditor)->get(route('budget-reports.versus-actual', $query))->assertOk()
        ->assertSee('Approved version')->assertSee('1,500.00')->assertSee('80.00%')->assertDontSee('Superseded version.')->assertDontSee('Over budget');
    expect(BudgetAlert::query()->where('budget_id', $revision->id)->where('level', BudgetAlertLevel::Warning->value)->count())->toBeGreaterThan(0);
    $alerts = BudgetAlert::query()->count();

    $this->get(route('budget-reports.versus-actual', [...$query, 'budget_id' => $first->id]))->assertOk()
        ->assertSee('Superseded version.')->assertSee('Superseded budget')->assertSee('1,000.00')->assertSee('120.00%')->assertSee('Over budget')->assertSee('1,200.00');

    // Actual is identical for both versions and a superseded version records no alert.
    expect(budgetRow(budgetReport($this, $first), $this->rent)['actual'])->toBe('1200.0000')
        ->and(budgetRow(budgetReport($this, $revision), $this->rent)['actual'])->toBe('1200.0000')
        ->and(BudgetAlert::query()->count())->toBe($alerts)
        ->and(BudgetAlert::query()->where('budget_id', $first->id)->count())->toBe(0);

    // Neither an unapproved version nor a budget of another branch can be selected.
    $this->get(route('budget-reports.versus-actual', [...$query, 'budget_id' => $draft->id]))->assertNotFound();
    $this->get(route('budget-reports.versus-actual', [...$query, 'budget_id' => $foreign->id]))->assertNotFound();
    $this->get(route('budget-reports.versus-actual.export', ['format' => 'csv', ...$query, 'budget_id' => $foreign->id]))->assertNotFound();

    $csv = budgetExport($this, 'budget-reports.versus-actual.export', [...$query, 'budget_id' => $first->id]);
    expect($csv)->toContain('(Superseded version)')->toContain('1000.0000')->not->toContain('1500.0000')->not->toContain('7777');
});

test('hierarchy export rolls every ledger up once and the ledger layout omits group rows', function () {
    budgetApproved($this, [
        budgetLine($this->rent, $this->january, '100'), budgetLine($this->rent, $this->february, '100'),
        budgetLine($this->utilities, $this->january, '50'), budgetLine($this->salaries, $this->january, '200'), budgetLine($this->fees, $this->january, '900'),
    ]);
    budgetSpend($this, $this->rent, '40.0000');
    budgetSpend($this, $this->salaries, '10.0000', $this->february);
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];
    $this->actingAs($this->auditor);

    $csv = budgetExport($this, 'budget-reports.versus-actual.export', $query);
    // Account, Approved budget, Actual (posted), Variance, Utilisation %, Remaining, PO commitments, Status
    expect($csv)->toContain('Account,"Approved budget","Actual (posted)",Variance,"Utilisation %",Remaining,"PO commitments (not in Actual)",Status')
        ->and(array_slice(budgetCsvRow($csv, '500000 '.$this->expenseClass->name), 1, 5))->toBe(['450.0000', '50.0000', '-400.0000', '11.11', '400.0000'])
        ->and(array_slice(budgetCsvRow($csv, '510000 '.$this->expenseGroup->name), 1, 2))->toBe(['450.0000', '50.0000'])
        ->and(array_slice(budgetCsvRow($csv, '511000 '.$this->premises->name), 1, 2))->toBe(['250.0000', '40.0000'])
        ->and(array_slice(budgetCsvRow($csv, '512000 '.$this->staffing->name), 1, 2))->toBe(['200.0000', '10.0000'])
        ->and(array_slice(budgetCsvRow($csv, '511001 Rent'), 1, 5))->toBe(['200.0000', '40.0000', '-160.0000', '20.00', '160.0000'])
        ->and(array_slice(budgetCsvRow($csv, 'Total expenses (each ledger once)'), 1, 2))->toBe(['450.0000', '50.0000'])
        ->and(array_slice(budgetCsvRow($csv, 'Total income (each ledger once)'), 1, 2))->toBe(['900.0000', '0.0000'])
        ->and($csv)->toContain('Layout: Account hierarchy');

    $flat = budgetExport($this, 'budget-reports.versus-actual.export', [...$query, 'layout' => 'ledgers', 'from_period_id' => $this->january->id, 'to_period_id' => $this->january->id]);
    expect(budgetCsvRow($flat, '500000 '.$this->expenseClass->name))->toBeNull()
        ->and(array_slice(budgetCsvRow($flat, '511001 Rent'), 1, 2))->toBe(['100.0000', '40.0000'])
        ->and(array_slice(budgetCsvRow($flat, 'Total expenses (each ledger once)'), 1, 2))->toBe(['350.0000', '40.0000'])
        ->and($flat)->toContain('Periods: January 2026 to January 2026');

    $this->get(route('budget-reports.versus-actual', $query))->assertOk()->assertSee('3 budgeted ledgers')->assertSee('Approved budget')->assertSee('Actual (posted)');
    $this->get(route('budget-reports.versus-actual', [...$query, 'layout' => 'ledgers']))->assertOk()->assertDontSee('3 budgeted ledgers')->assertSee('Rent');
    $this->get(route('budget-reports.versus-actual', [...$query, 'layout' => 'sideways']))->assertSessionHasErrors('layout');
});

test('exports keep purchase order commitments in their own column and out of actual', function () {
    budgetApproved($this);
    $order = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'po_date' => '2026-03-05', 'total_amount' => '500.0000', 'subtotal' => '500.0000', 'created_by' => $this->maker->id]);
    $order->lines()->create(['line_number' => 1, 'description' => 'Rent deposit', 'quantity' => '10.0000', 'unit_price' => '50.0000', 'line_total' => '500.0000', 'account_id' => $this->rent->id]);
    $order->update(['status' => 'approved']);
    budgetSpend($this, $this->rent, '125.0000');
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];
    $this->actingAs($this->auditor);

    $rent = budgetCsvRow(budgetExport($this, 'budget-reports.versus-actual.export', $query), '511001 Rent');
    expect(array_slice($rent, 1))->toBe(['1000.0000', '125.0000', '-875.0000', '12.50', '875.0000', '500.0000', 'Within budget']);

    $summary = budgetExport($this, 'budget-reports.branch-summary.export', ['fiscal_year_id' => $this->year->id]);
    expect(array_slice(budgetCsvRow($summary, $this->branch->name), 1))->toBe(['Operating budget v1', 'Expenses', '1000.0000', '125.0000', '-875.0000', '12.50', '875.0000', '500.0000', 'Within budget']);

    $path = tempnam(sys_get_temp_dir(), 'bva').'.xlsx';
    file_put_contents($path, budgetExport($this, 'budget-reports.versus-actual.export', $query, 'xlsx'));
    $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
    $ledger = $rows->first(fn (array $row): bool => trim((string) $row[0]) === '511001 Rent');
    unlink($path);
    expect($ledger[1])->toEqual(1000)->and($ledger[2])->toEqual(125)->and($ledger[6])->toEqual(500);

    $pdf = budgetExport($this, 'budget-reports.versus-actual.export', $query, 'pdf');
    expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()->and($pdf)->toContain('(Budget vs Actual)')->toContain('(1,000.00)')->toContain('(125.00)');

    // Exports are read-only: they record no alert and are audited.
    expect(BudgetAlert::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'report.exported')->count())->toBe(4);
});

test('budget exports need both the report and the export permission', function () {
    budgetApproved($this);
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('budget_reports.view');
    $viewer->branches()->attach($this->branch);
    $exportOnly = User::factory()->create();
    $exportOnly->givePermissionTo('budget_reports.export');
    $exportOnly->branches()->attach($this->branch);

    foreach (['budget-reports.versus-actual.export', 'budget-reports.branch-summary.export', 'budget-reports.service-summary.export'] as $route) {
        $this->actingAs($viewer)->get(route($route, ['format' => 'csv', ...$query]))->assertForbidden();
        $this->actingAs($exportOnly)->get(route($route, ['format' => 'csv', ...$query]))->assertForbidden();
        $this->actingAs($this->approver)->get(route($route, ['format' => 'csv', ...$query]))->assertForbidden();
        $this->actingAs($this->auditor)->get(route($route, ['format' => 'csv', ...$query]))->assertOk();
        $this->actingAs($this->auditor)->get(route($route, ['format' => 'docx', ...$query]))->assertNotFound();
    }
    $this->actingAs($viewer)->get(route('budget-reports.versus-actual', $query))->assertOk()->assertDontSee('/export/csv');
    $this->actingAs($this->auditor)->get(route('budget-reports.versus-actual', $query))->assertOk()->assertSee('/export/csv');
    $report = app(BudgetReportService::class)->versusActual(Budget::query()->sole(), $viewer, null, null, false);
    expect(fn () => app(BudgetReportService::class)->versusActualTable($report, $viewer, true))->toThrow(HttpException::class);
});

test('budget exports never contain another branch or another service', function () {
    budgetApproved($this, [budgetLine($this->rent, $this->january, '1000')]);
    budgetApproved($this, [budgetLine($this->salaries, $this->january, '400')], ['service_id' => $this->service->id, 'name' => 'Therapy service budget']);
    budgetForeignApproved($this, '8888');
    budgetSpend($this, $this->salaries, '100.0000', $this->january, $this->service->id);
    budgetSpend($this, $this->salaries, '55.0000', $this->january, $this->otherService->id);
    budgetSpend($this, $this->salaries, '33.0000');
    budgetSpend($this, $this->rent, '6666.0000', $this->january, null, $this->otherBranch);
    budgetSpend($this, $this->salaries, '4444.0000', $this->january, $this->service->id, $this->otherBranch);
    $query = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id];
    $this->actingAs($this->auditor);

    // A service budget counts only journal lines tagged with that service, in its own branch.
    $service = budgetExport($this, 'budget-reports.versus-actual.export', [...$query, 'service_id' => $this->service->id]);
    expect(array_slice(budgetCsvRow($service, '512001 Salaries'), 1, 2))->toBe(['400.0000', '100.0000'])
        ->and($service)->not->toContain('55.0000')->not->toContain('4444')->not->toContain('188.0000');
    // The whole-branch budget has no salaries line and never picks up the other branch's rent.
    $whole = budgetExport($this, 'budget-reports.versus-actual.export', $query);
    expect(array_slice(budgetCsvRow($whole, '511001 Rent'), 1, 2))->toBe(['1000.0000', '0.0000'])->and($whole)->not->toContain('6666')->not->toContain('8888');

    $serviceSummary = budgetExport($this, 'budget-reports.service-summary.export', ['fiscal_year_id' => $this->year->id]);
    expect(array_slice(budgetCsvRow($serviceSummary, $this->branch->name), 1, 5))->toBe([$this->service->name, 'Therapy service budget v1', 'Expenses', '400.0000', '100.0000'])
        ->and($serviceSummary)->not->toContain($this->otherBranch->name);
    $branchSummary = budgetExport($this, 'budget-reports.branch-summary.export', ['fiscal_year_id' => $this->year->id]);
    expect($branchSummary)->toContain('Operating budget v1')->not->toContain('Other branch budget')->not->toContain($this->otherBranch->name)->not->toContain('8888');

    // A branch outside the user's scope is refused, on screen and in every export.
    foreach (['budget-reports.versus-actual.export', 'budget-reports.branch-summary.export', 'budget-reports.service-summary.export'] as $route) {
        $this->get(route($route, ['format' => 'csv', 'fiscal_year_id' => $this->year->id, 'branch_id' => $this->otherBranch->id]))->assertForbidden();
    }
    $this->get(route('budget-reports.versus-actual', ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->actingAs($this->outsider)->get(route('budget-reports.versus-actual', $query))->assertForbidden();
});
