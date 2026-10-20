<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountingDecimal;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalanceService;
use App\Services\TrialBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\Blade;

test('amounts are formatted exactly at the display precision', function (string|int|null $value, int $scale, string $expected) {
    expect(AccountingDecimal::format($value, $scale))->toBe($expected);
})->with([
    'zero' => ['0', 2, '0.00'],
    'null' => [null, 2, '0.00'],
    'one cent' => ['0.01', 2, '0.01'],
    'ten thousand' => ['10000.00', 2, '10,000.00'],
    'threshold plus a cent' => ['150000.01', 2, '150,000.01'],
    'nine hundred thousand' => ['900000.00', 2, '900,000.00'],
    'millions' => ['1234567.89', 2, '1,234,567.89'],
    'hundred billion' => ['99999999999.99', 2, '99,999,999,999.99'],
    'journal maximum at four places' => ['999999999999999.9999', 4, '999,999,999,999,999.9999'],
    'two place maximum' => ['999999999999999.99', 2, '999,999,999,999,999.99'],
    'negative' => ['-1234567.89', 2, '-1,234,567.89'],
    'negative four places' => ['-0.0001', 4, '-0.0001'],
    'rounds to zero without a sign' => ['-0.004', 2, '0.00'],
    'four places' => ['150000.0100', 4, '150,000.0100'],
    'integer' => [1500, 2, '1,500.00'],
    'whole number places' => ['1234567.89', 0, '1,234,568'],
]);

test('journal amounts are shown with every stored place and never rounded into another value', function (string $value, string $expected) {
    expect(AccountingDecimal::format($value, 2, 4))->toBe($expected);
})->with([
    ['999999999999999.9999', '999,999,999,999,999.9999'],
    ['150000.0100', '150,000.01'],
    ['123.4567', '123.4567'],
    ['0.0050', '0.005'],
    ['0.0000', '0.00'],
    ['-2500.5000', '-2,500.50'],
    ['99999999999.99', '99,999,999,999.99'],
]);

test('the formatter never goes through a float and the old float path would misreport large amounts', function () {
    $source = (new ReflectionMethod(AccountingDecimal::class, 'format'))->getFileName();
    $body = implode('', array_slice(file($source), (new ReflectionMethod(AccountingDecimal::class, 'format'))->getStartLine() - 1, (new ReflectionMethod(AccountingDecimal::class, 'format'))->getEndLine() - (new ReflectionMethod(AccountingDecimal::class, 'format'))->getStartLine() + 1));

    expect($body)->not->toContain('(float)')->not->toContain('floatval')->not->toContain('number_format')
        // What the replaced code showed for the same values.
        ->and(number_format((float) '999999999999999.9999', 4))->toBe('1,000,000,000,000,000.0000')
        ->and(AccountingDecimal::format('999999999999999.9999', 4))->toBe('999,999,999,999,999.9999')
        ->and(Blade::render('{{ \App\Services\AccountingDecimal::format($v, 2, 4) }}', ['v' => '999999999999999.9999']))->toBe('999,999,999,999,999.9999');
});

test('journal opening balance and trial balance screens show exact figures', function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $branch = Branch::factory()->create();
    $users = collect([SystemRole::Accountant, SystemRole::Director, SystemRole::Administrator, SystemRole::Approver])->map(function (SystemRole $role) use ($branch): User {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->branches()->attach($branch);

        return $user;
    });
    [$maker, $director, $poster, $approver] = $users->all();
    $year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $expense = Account::factory()->create(['code' => '511001', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $income = Account::factory()->create(['code' => '411001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);

    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($maker);
    $journal = $workflow->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20', 'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branch->id, 'narration' => 'Display fixture',
        'lines' => [['account_id' => $expense->id, 'debit' => '1234567.8912', 'credit' => '0'], ['account_id' => $income->id, 'debit' => '0', 'credit' => '1234567.8912']]], $maker);
    $workflow->submit($journal, $maker);
    $workflow->approve($journal->fresh(), $director);
    $posted = app(JournalPostingService::class)->post($journal->fresh(), $poster);

    $this->actingAs($poster)->get(route('journals.show', $posted))->assertOk()->assertSee('1,234,567.8912')->assertDontSee('1,234,567.89<', false);
    $this->get(route('journals.index'))->assertOk()->assertSee('1,234,567.8912');

    $trial = app(TrialBalanceService::class)->report($year->id, '2026-01-31', [$branch->id]);
    expect($trial['total_debit'])->toBe('1234567.8912')->and($trial['total_credit'])->toBe('1234567.8912');
    $this->get(route('reports.trial-balance', ['fiscal_year_id' => $year->id, 'as_of' => '2026-01-31', 'branch_id' => $branch->id]))->assertOk()->assertSee('1234567.8912');

    $asset = Account::factory()->create(['code' => '11102']);
    $equity = Account::factory()->create(['code' => '31101', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->actingAs($maker);
    $batch = app(OpeningBalanceService::class)->saveDraft(['fiscal_year_id' => $year->id, 'branch_id' => $branch->id, 'opening_date' => '2026-01-01',
        'lines' => [['account_id' => $asset->id, 'debit' => '99999999999.99', 'credit' => '0'], ['account_id' => $equity->id, 'debit' => '0', 'credit' => '99999999999.99']]], $maker);
    $this->get(route('opening-balances.show', $batch))->assertOk()->assertSee('99,999,999,999.99');
    $this->get(route('opening-balances.index'))->assertOk()->assertSee('99,999,999,999.99');
});
