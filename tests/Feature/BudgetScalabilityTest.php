<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\BudgetStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Http\Requests\SaveBudgetRequest;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Budgets\BudgetService;
use Brick\Math\BigDecimal;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);
    $this->approver = User::factory()->create();
    $this->approver->assignRole(SystemRole::Approver->value);
    $this->approver->branches()->attach($this->branch);
    $this->actingAs($this->maker);
});

/** @return list<Account> ledgers ordered by code */
function budgetscaleLedgers(int $count): array
{
    $ledgers = [];
    for ($i = 1; $i <= $count; $i++) {
        $ledgers[] = Account::factory()->create(['code' => sprintf('6%05d', $i), 'name' => 'Scale ledger '.$i, 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    }

    return $ledgers;
}

/**
 * Deterministic amount for ledger i (1-based) and period j (1-based): i.jjjj + j, so every line differs.
 */
function budgetscaleAmount(int $ledger, int $period): string
{
    return sprintf('%d.%04d', $ledger * 10 + $period, $ledger * 7 + $period);
}

/**
 * @param  list<Account>  $ledgers
 * @return list<array{account_id: int, period_id: int, amount: string}>
 */
function budgetscaleLines(object $test, array $ledgers): array
{
    $lines = [];
    foreach ($ledgers as $i => $ledger) {
        foreach ($test->periods as $j => $period) {
            $lines[] = ['account_id' => $ledger->id, 'period_id' => $period->id, 'amount' => budgetscaleAmount($i + 1, $j + 1)];
        }
    }

    return $lines;
}

/** @param list<array<string, mixed>> $lines */
function budgetscaleTotal(array $lines): string
{
    return (string) array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $sum->plus($line['amount']), BigDecimal::zero())->toScale(4);
}

function budgetscaleForm(object $test, mixed $lines, array $overrides = []): array
{
    return [
        'fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'name' => 'Full chart budget',
        'lines_json' => is_string($lines) ? $lines : json_encode($lines), ...$overrides,
    ];
}

/** Number of request variables PHP would count against max_input_vars. */
function budgetscaleInputVariables(array $form): int
{
    return count($form, COUNT_RECURSIVE);
}

test('a full chart budget of 250 ledgers and 12 periods is saved through one json field', function () {
    $ledgers = budgetscaleLedgers(250);
    $lines = budgetscaleLines($this, $ledgers);
    $form = budgetscaleForm($this, $lines);

    expect(count($lines))->toBe(3000)->and(budgetscaleInputVariables($form))->toBeLessThan(20);

    DB::enableQueryLog();
    $this->post(route('budgets.store'), $form)->assertSessionHasNoErrors()->assertRedirect();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $budget = Budget::query()->sole();
    $stored = DB::table('budget_lines')->where('budget_id', $budget->id)->selectRaw('COUNT(*) as lines, COUNT(DISTINCT account_id) as ledgers')->first();
    expect((int) $stored->lines)->toBe(3000)
        ->and((int) $stored->ledgers)->toBe(250)
        ->and($budget->total_amount)->toBe(budgetscaleTotal($lines))
        ->and($budget->status)->toBe(BudgetStatus::Draft)
        // Chunked inserts: far fewer statements than one per line.
        ->and($queries)->toBeLessThan(120)
        ->and(JournalEntry::query()->count())->toBe(0);

    // Every stored line equals the line that was sent.
    $byKey = BudgetLine::query()->where('budget_id', $budget->id)->get()->keyBy(fn (BudgetLine $line): string => $line->account_id.':'.$line->accounting_period_id);
    foreach ([0, 1, 1499, 2998, 2999] as $index) {
        expect($byKey[$lines[$index]['account_id'].':'.$lines[$index]['period_id']]->amount)->toBe($lines[$index]['amount']);
    }

    // The large budget still goes through the workflow with its integrity check.
    $service = app(BudgetService::class);
    $service->submit($budget, $this->maker);
    expect($service->approve($budget->fresh(), $this->approver)->status)->toBe(BudgetStatus::Approved);
});

test('updating a large budget changes only the edited lines and loses none of the others', function () {
    $ledgers = budgetscaleLedgers(250);
    $lines = budgetscaleLines($this, $ledgers);
    $this->post(route('budgets.store'), budgetscaleForm($this, $lines))->assertSessionHasNoErrors();
    $budget = Budget::query()->sole();
    $before = DB::table('budget_lines')->where('budget_id', $budget->id)->get(['id', 'account_id', 'accounting_period_id', 'amount'])->keyBy(fn (object $row): string => $row->account_id.':'.$row->accounting_period_id);

    // Edit three lines on what would be different grid pages, remove one line and add nothing else.
    $edited = $lines;
    $edited[5]['amount'] = '999.9999';
    $edited[1500]['amount'] = '0';
    $edited[2999]['amount'] = '123456789.0001';
    $removed = $edited[700];
    unset($edited[700]);
    $edited = array_values($edited);

    DB::enableQueryLog();
    $this->put(route('budgets.update', $budget), budgetscaleForm($this, $edited, ['name' => 'Full chart budget revised']))->assertSessionHasNoErrors()->assertRedirect(route('budgets.show', $budget));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $after = DB::table('budget_lines')->where('budget_id', $budget->id)->get(['id', 'account_id', 'accounting_period_id', 'amount'])->keyBy(fn (object $row): string => $row->account_id.':'.$row->accounting_period_id);
    $changedKeys = collect([5, 1500, 2999])->map(fn (int $index): string => $lines[$index]['account_id'].':'.$lines[$index]['period_id'])->all();
    $removedKey = $removed['account_id'].':'.$removed['period_id'];

    expect($after)->toHaveCount(2999)
        ->and($after->has($removedKey))->toBeFalse()
        ->and($budget->fresh()->total_amount)->toBe(budgetscaleTotal($edited))
        ->and($budget->fresh()->name)->toBe('Full chart budget revised')
        ->and(AccountingDecimal::string(AccountingDecimal::of($after[$changedKeys[0]]->amount)))->toBe('999.9999')
        ->and(AccountingDecimal::string(AccountingDecimal::of($after[$changedKeys[1]]->amount)))->toBe('0.0000')
        ->and(AccountingDecimal::string(AccountingDecimal::of($after[$changedKeys[2]]->amount)))->toBe('123456789.0001')
        ->and($queries)->toBeLessThan(60);

    // The 2,996 untouched lines are the very same rows with the very same amounts.
    $untouched = 0;
    foreach ($before as $key => $row) {
        if ($key === $removedKey || in_array($key, $changedKeys, true)) {
            continue;
        }
        expect($after[$key]->id)->toBe($row->id)->and((string) $after[$key]->amount)->toBe((string) $row->amount);
        $untouched++;
    }
    expect($untouched)->toBe(2996);

    // Saving the identical document again writes no line at all.
    $ids = $after->pluck('id')->sort()->values()->all();
    $this->put(route('budgets.update', $budget), budgetscaleForm($this, $edited))->assertSessionHasNoErrors();
    expect(DB::table('budget_lines')->where('budget_id', $budget->id)->orderBy('id')->pluck('id')->all())->toBe($ids);
});

test('a request without the json payload is rejected instead of saving an empty budget', function () {
    [$ledger] = budgetscaleLedgers(1);
    $january = $this->periods[0];
    $header = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'name' => 'No payload'];

    $this->post(route('budgets.store'), $header)->assertSessionHasErrors('lines_json');
    $this->post(route('budgets.store'), [...$header, 'lines_json' => ''])->assertSessionHasErrors('lines_json');
    // The old per-cell format is no longer accepted either.
    $this->post(route('budgets.store'), [...$header, 'rows' => [['account_id' => $ledger->id, 'amounts' => [$january->id => '10']]]])->assertSessionHasErrors('lines_json');
    $this->post(route('budgets.store'), [...$header, 'lines_json' => '[]'])->assertSessionHasErrors('lines');
    expect(Budget::query()->count())->toBe(0);

    // An existing budget keeps all of its lines when an update arrives without the payload.
    $this->post(route('budgets.store'), budgetscaleForm($this, [['account_id' => $ledger->id, 'period_id' => $january->id, 'amount' => '250.0000']]))->assertSessionHasNoErrors();
    $budget = Budget::query()->sole();
    $this->put(route('budgets.update', $budget), [...$header, 'name' => 'Changed'])->assertSessionHasErrors('lines_json');
    $this->put(route('budgets.update', $budget), [...$header, 'name' => 'Changed', 'lines_json' => '[]'])->assertSessionHasErrors('lines');

    expect($budget->fresh()->name)->toBe('Full chart budget')
        ->and($budget->fresh()->total_amount)->toBe('250.0000')
        ->and($budget->lines()->count())->toBe(1);
});

test('malformed and invalid json payloads are validation errors and nothing is saved', function (mixed $payload, string $errorKey) {
    [$ledger, $second] = budgetscaleLedgers(2);
    $january = $this->periods[0];
    $otherYearPeriod = AccountingPeriod::factory()->create(['start_date' => '2027-01-01', 'end_date' => '2027-01-31']);
    $group = Account::factory()->create(['account_type' => 'expense', 'level' => 'group', 'is_postable' => false]);
    $payload = is_callable($payload) ? $payload($ledger, $january, $second, $otherYearPeriod, $group) : $payload;

    $response = $this->from(route('budgets.create'))->post(route('budgets.store'), budgetscaleForm($this, $payload));

    $response->assertRedirect(route('budgets.create'))->assertSessionHasErrors($errorKey);
    expect(Budget::query()->count())->toBe(0)->and(BudgetLine::query()->count())->toBe(0);
})->with([
    'not json' => ['{not json', 'lines_json'],
    'truncated json' => ['[{"account_id":1,"period_id":1,"amount":"1"', 'lines_json'],
    'json object instead of list' => ['{"account_id":1,"period_id":1,"amount":"1"}', 'lines_json'],
    'json scalar' => ['"hello"', 'lines_json'],
    'nested too deep' => ['[{"account_id":[[1]],"period_id":1,"amount":"1"}]', 'lines_json'],
    'entry is not an object' => ['[5]', 'lines.0'],
    'missing key' => [fn ($l, $p) => [['account_id' => $l->id, 'amount' => '1']], 'lines.0'],
    'unexpected key' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1', 'budget_id' => 99]], 'lines.0'],
    'duplicate account and period' => [fn ($l, $p, $s) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1'], ['account_id' => $s->id, 'period_id' => $p->id, 'amount' => '1'], ['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '2']], 'lines.2'],
    'unknown account' => [fn ($l, $p) => [['account_id' => 999999, 'period_id' => $p->id, 'amount' => '1']], 'lines.0.account_id'],
    'group account' => [fn ($l, $p, $s, $o, $g) => [['account_id' => $g->id, 'period_id' => $p->id, 'amount' => '1']], 'lines.0.account_id'],
    'account id not an integer' => [fn ($l, $p) => [['account_id' => 'abc', 'period_id' => $p->id, 'amount' => '1']], 'lines.0.account_id'],
    'unknown period' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => 999999, 'amount' => '1']], 'lines.0.accounting_period_id'],
    'period of another fiscal year' => [fn ($l, $p, $s, $o) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1'], ['account_id' => $l->id, 'period_id' => $o->id, 'amount' => '1']], 'lines.1.accounting_period_id'],
    'period id zero' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => 0, 'amount' => '1']], 'lines.0.accounting_period_id'],
    'negative amount' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '-5']], 'lines.0.amount'],
    'five decimals' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1.00001']], 'lines.0.amount'],
    'not a number' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => 'abc']], 'lines.0.amount'],
    'scientific notation' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1e3']], 'lines.0.amount'],
    'empty amount' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '']], 'lines.0.amount'],
    'null amount' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => null]], 'lines.0.amount'],
    'float amount' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => 10.5]], 'lines.0.amount'],
    'amount beyond the column range' => [fn ($l, $p) => [['account_id' => $l->id, 'period_id' => $p->id, 'amount' => '1234567890123456']], 'lines.0.amount'],
]);

test('the payload size is bounded', function () {
    [$ledger] = budgetscaleLedgers(1);
    $line = ['account_id' => $ledger->id, 'period_id' => $this->periods[0]->id, 'amount' => '1'];

    $this->post(route('budgets.store'), budgetscaleForm($this, array_fill(0, SaveBudgetRequest::MAX_LINES + 1, $line)))->assertSessionHasErrors('lines_json');
    // Padding inside the document: leading and trailing whitespace would be trimmed by the framework before validation.
    $this->post(route('budgets.store'), budgetscaleForm($this, '['.str_repeat(' ', SaveBudgetRequest::MAX_PAYLOAD_BYTES).json_encode($line).']'))->assertSessionHasErrors('lines_json');
    expect(Budget::query()->count())->toBe(0);
});

test('the edit and detail screens render for a 250 ledger budget without per cell form fields', function () {
    $ledgers = budgetscaleLedgers(250);
    $lines = budgetscaleLines($this, $ledgers);
    $this->post(route('budgets.store'), budgetscaleForm($this, $lines))->assertSessionHasNoErrors();
    $budget = Budget::query()->sole();

    $response = $this->get(route('budgets.edit', $budget))->assertOk();
    $html = $response->getContent();
    preg_match('/<script type="application\/json" data-budget-data>(.*?)<\/script>/s', $html, $match);
    $data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

    expect($data['lines'])->toHaveCount(3000)
        ->and($data['ledgers'])->toHaveCount(250)
        ->and($data['periods'])->toHaveCount(12)
        ->and(collect($data['lines'])->firstWhere(fn (array $line): bool => $line['account_id'] === $ledgers[249]->id && $line['period_id'] === $this->periods[11]->id)['amount'])->toBe(budgetscaleAmount(250, 12))
        ->and(budgetscaleTotal($data['lines']))->toBe($budget->total_amount)
        // The grid posts one field: no named input exists per cell or per row.
        ->and($html)->toContain('name="lines_json"')->not->toContain('name="rows[')->not->toContain('name="lines[')
        ->and(preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname=/', $html))->toBeLessThan(20);

    $this->get(route('budgets.show', $budget))->assertOk()->assertSee('Scale ledger 250')->assertSee('3000 lines');

    // Re-posting exactly what the edit screen holds preserves every line.
    $this->put(route('budgets.update', $budget), budgetscaleForm($this, $data['lines']))->assertSessionHasNoErrors();
    expect($budget->lines()->count())->toBe(3000)->and($budget->fresh()->total_amount)->toBe(budgetscaleTotal($lines));
});

test('a failed save returns the submitted lines to the grid so no entry is lost', function () {
    [$ledger] = budgetscaleLedgers(1);
    $lines = [['account_id' => $ledger->id, 'period_id' => $this->periods[0]->id, 'amount' => '321.1234'], ['account_id' => $ledger->id, 'period_id' => $this->periods[1]->id, 'amount' => '-1']];

    // Follow the redirect back to the form: the page must show the error and still hold what was typed.
    $html = $this->followingRedirects()->from(route('budgets.create'))->post(route('budgets.store'), budgetscaleForm($this, $lines))->assertOk()->getContent();

    expect($html)->toContain('Budget line 2: enter a non-negative amount')->toContain('"amount":"321.1234"')->toContain('"amount":"-1"')
        ->and(Budget::query()->count())->toBe(0);
});

test('a revision of a large budget copies every line', function () {
    $ledgers = budgetscaleLedgers(60);
    $lines = budgetscaleLines($this, $ledgers);
    $this->post(route('budgets.store'), budgetscaleForm($this, $lines))->assertSessionHasNoErrors();
    $service = app(BudgetService::class);
    $budget = Budget::query()->sole();
    $service->submit($budget, $this->maker);
    $service->approve($budget->fresh(), $this->approver);

    $revision = $service->revise($budget->fresh(), 'Annual review', $this->maker);

    expect($revision->lines()->count())->toBe(720)
        ->and($revision->total_amount)->toBe(budgetscaleTotal($lines))
        ->and((string) BigDecimal::of((string) DB::table('budget_lines')->where('budget_id', $revision->id)->sum('amount'))->toScale(4))->toBe(budgetscaleTotal($lines))
        ->and($budget->lines()->count())->toBe(720);
});
