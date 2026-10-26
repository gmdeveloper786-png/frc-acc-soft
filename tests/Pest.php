<?php

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\ImprestFloat;
use App\Models\User;
use App\Models\VoucherType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Make $cash the cash account of an active imprest float and fund it with a posted
 * journal, so petty cash vouchers paid from it pass the float limit.
 */
function fundedPettyCashFloat(FinancialAccount $cash, string $balance = '1000000.0000'): ImprestFloat
{
    $creator = User::factory()->create();
    $float = ImprestFloat::query()->create([
        'branch_id' => $cash->branch_id, 'account_id' => $cash->account_id, 'float_amount' => $balance, 'custodian_name' => 'Test custodian', 'is_active' => true, 'active_key' => '1',
        'source_financial_account_id' => FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $cash->branch_id, 'account_id' => Account::factory()])->id,
        'created_by' => $creator->id,
    ]);
    // Funding journal written directly: test fixture only, so the posted-journal guards do not apply.
    $period = AccountingPeriod::query()->orderBy('start_date')->first()
        ?? AccountingPeriod::factory()->create(['fiscal_year_id' => FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']), 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    $journalId = DB::table('journal_entries')->insertGetId([
        'voucher_type_id' => VoucherType::query()->value('id') ?? VoucherType::factory()->create()->id, 'transaction_date' => $period->start_date->toDateString(),
        'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id, 'branch_id' => $cash->branch_id, 'narration' => 'Test float funding',
        'status' => 'posted', 'voucher_number' => 'TEST-FLOAT-'.$float->id, 'total_debit' => $balance, 'total_credit' => $balance, 'created_by' => $creator->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $journalId, 'line_number' => 1, 'account_id' => $cash->account_id, 'debit' => $balance, 'credit' => '0', 'created_at' => now(), 'updated_at' => now()],
        ['journal_entry_id' => $journalId, 'line_number' => 2, 'account_id' => Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit'])->id, 'debit' => '0', 'credit' => $balance, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $float;
}

/** A new cash account of the branch that holds a funded, active imprest float. */
function fundedPettyCashAccount(int $branchId, string $balance = '1000000.0000'): FinancialAccount
{
    $cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $branchId, 'account_id' => Account::factory()]);
    fundedPettyCashFloat($cash, $balance);

    return $cash;
}
