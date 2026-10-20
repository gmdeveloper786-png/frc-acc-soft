<?php

/*
 * Real MySQL/InnoDB concurrency verification.
 *
 *   php tests/Concurrency/run.php --confirm-wipe=frc_acct_qa_concurrency_test [--rounds=5]
 *
 * Runs only on the DBA-created database frc_acct_qa_concurrency_test with the
 * restricted QA account from .env.qa-mysql (see tests/Qa/README.md). It wipes
 * and rebuilds that database's tables (migrate:fresh), builds isolated fixtures
 * and launches several PHP processes, each with its own MySQL connection, that
 * hit the same rows at the same moment. It never creates or drops a database,
 * and never reads .env credentials or the development database.
 */

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\DepreciationRunStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DepreciationRun;
use App\Models\DepreciationRunLine;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\InventoryBalance;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\PayrollRunPayment;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\AccountMappingService;
use App\Services\Assets\AssetPolicy;
use App\Services\Assets\DepreciationRunService;
use App\Services\Assets\FixedAssetService;
use App\Services\Inventory\InventoryService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Services\Payroll\PayrollRunService;
use App\Services\TrialBalanceService;
use App\Support\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Qa\QaMysqlGuard;

require __DIR__.'/bootstrap.php';
$root = dirname(__DIR__, 2);
$options = getopt('', ['rounds::', 'fresh', 'drop', 'confirm-wipe:']);
if (isset($options['fresh']) || isset($options['drop'])) {
    fwrite(STDERR, "Refusing to run: --fresh and --drop were removed. The DBA creates and drops the database; this script never does.\n");
    exit(2);
}
$rounds = max(1, (int) ($options['rounds'] ?? 5));
$database = concurrencyDatabase();

// 1. The DBA pre-creates the database. This run only rebuilds its tables, which needs explicit confirmation.
try {
    QaMysqlGuard::requireWipeConfirmation($argv, $database);
} catch (Throwable $exception) {
    concurrencyRefuse($exception);
}

// 2. Boot against it (the QA account is verified before and after booting) and rebuild the schema.
concurrencyBoot($root);
Artisan::call('migrate:fresh', ['--force' => true]);
foreach ([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class] as $seeder) {
    app($seeder)->run();
}

$checks = [];
$check = function (string $name, bool $passed, string $detail = '') use (&$checks): void {
    $checks[] = compact('name', 'passed', 'detail');
    echo ($passed ? '  PASS  ' : '  FAIL  ').$name.($detail !== '' ? ' — '.$detail : '').PHP_EOL;
};

/**
 * Start every worker at the same instant and collect their JSON results.
 *
 * @param  list<array<string, mixed>>  $jobs  Each: ['scenario' => string, 'arguments' => array]
 * @return list<array<string, mixed>>
 */
$race = function (array $jobs): array {
    $startAt = microtime(true) + 4.0;
    $processes = [];
    foreach ($jobs as $job) {
        $arguments = base64_encode(json_encode([...$job['arguments'], 'start_at' => $startAt]));
        $process = proc_open([PHP_BINARY, __DIR__.'/worker.php', $job['scenario'], $arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        proc_close($process);
        $results[] = json_decode((string) $output, true) ?? ['ok' => false, 'error' => 'WorkerCrash', 'message' => mb_substr(trim($errors.' '.$output), 0, 200)];
    }

    return $results;
};
$failures = fn (array $results): string => ' | failures: '.(collect($results)->where('ok', false)->map(fn (array $result): string => $result['error'].': '.Str::limit((string) ($result['message'] ?? ''), 90))->countBy()->map(fn (int $count, string $text): string => $count.'x '.$text)->implode('; ') ?: 'none');
$succeeded = fn (array $results): int => count(array_filter($results, fn (array $result): bool => $result['ok'] === true));

$tag = Str::lower(Str::random(6));
$user = function (Branch ...$branches): User {
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Accountant->value);
    $user->branches()->attach(collect($branches)->pluck('id'));

    return $user;
};
$branchA = Branch::factory()->create(['status' => 'publish']);
$branchB = Branch::factory()->create(['status' => 'publish']);

echo PHP_EOL.'MySQL '.DB::selectOne('select version() as v')->v.', isolation '.DB::selectOne('select @@transaction_isolation as i')->i.', database '.$database.PHP_EOL;
$engines = collect(DB::select('select table_name as t, engine as e from information_schema.tables where table_schema = database() and table_name in ("inventory_balances","inventory_movements","voucher_sequences","document_sequences","journal_entries","purchase_bills","operational_transactions")'));
$check('All contended tables use InnoDB', $engines->count() === 7 && $engines->every(fn ($row): bool => strtolower($row->e) === 'innodb'), $engines->map(fn ($row): string => $row->t.'='.$row->e)->implode(', '));

// --- Inventory: concurrent issues -------------------------------------------------
echo PHP_EOL."Inventory issues ({$rounds} rounds, 8 workers x 3 units against 10 in stock)".PHP_EOL;
$category = InventoryCategory::query()->create(['name' => 'Concurrency '.$tag, 'is_active' => true]);
$inventory = app(InventoryService::class);
$actors = collect(range(1, 8))->map(fn () => $user($branchA, $branchB));
$stock = function (string $suffix, string $quantity) use ($category, $branchA, $actors, $inventory, $tag): array {
    $item = InventoryItem::query()->create(['item_code' => 'CC-'.$tag.'-'.$suffix, 'name' => 'Concurrency item '.$suffix, 'category_id' => $category->id, 'unit_of_measure' => 'pcs', 'minimum_stock_level' => '0', 'is_active' => true, 'created_by' => $actors[0]->id]);
    $location = InventoryLocation::query()->create(['branch_id' => $branchA->id, 'name' => 'Store '.$tag.'-'.$suffix, 'is_active' => true]);
    $inventory->receive(['item_id' => $item->id, 'branch_id' => $branchA->id, 'location_id' => $location->id, 'quantity' => $quantity, 'transaction_date' => now()->toDateString(), 'reference' => 'OPENING'], $actors[0]);

    return [$item, $location];
};
$issuePayload = fn (InventoryItem $item, InventoryLocation $location, string $quantity, int $worker): array => ['item_id' => $item->id, 'branch_id' => $branchA->id, 'location_id' => $location->id, 'quantity' => $quantity, 'transaction_date' => now()->toDateString(), 'issued_to' => 'Worker '.$worker, 'reference' => 'RACE-'.$worker];
$balanceOf = fn (InventoryItem $item, InventoryLocation $location): string => (string) InventoryBalance::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity');

for ($round = 1; $round <= $rounds; $round++) {
    [$item, $location] = $stock('i'.$round, '10');
    $results = $race($actors->map(fn (User $actor, int $index): array => ['scenario' => 'inventory_issue', 'arguments' => ['user_id' => $actor->id, 'payload' => $issuePayload($item, $location, '3', $index)]])->all());
    $issues = InventoryMovement::query()->where('item_id', $item->id)->where('type', 'issue')->count();
    $posted = $inventory->postedQuantity($item->id, $branchA->id, $location->id);
    $check("Round {$round}: exactly 3 of 8 issues commit, balance 1.0000, never negative", $succeeded($results) === 3 && $issues === 3 && $balanceOf($item, $location) === '1.0000' && $posted === '1.0000', $succeeded($results).' ok, '.$issues.' movements, balance '.$balanceOf($item, $location).', sum of movements '.$posted);
}

[$item, $location] = $stock('pair', '10');
$results = $race(collect([0, 1])->map(fn (int $index): array => ['scenario' => 'inventory_issue', 'arguments' => ['user_id' => $actors[$index]->id, 'payload' => $issuePayload($item, $location, '6', $index)]])->all());
$check('Two users issue 6 + 6 from 10: exactly one commits and the loser leaves no movement', $succeeded($results) === 1 && InventoryMovement::query()->where('item_id', $item->id)->where('type', 'issue')->count() === 1 && $balanceOf($item, $location) === '4.0000', $succeeded($results).' ok, balance '.$balanceOf($item, $location));

// --- Inventory: concurrent cross-branch transfers ------------------------------------
[$item, $location] = $stock('transfer', '10');
$destination = InventoryLocation::query()->create(['branch_id' => $branchB->id, 'name' => 'Store B '.$tag, 'is_active' => true]);
$results = $race($actors->take(4)->map(fn (User $actor, int $index): array => ['scenario' => 'inventory_transfer', 'arguments' => ['user_id' => $actor->id, 'payload' => ['item_id' => $item->id, 'from_branch_id' => $branchA->id, 'from_location_id' => $location->id, 'to_branch_id' => $branchB->id, 'to_location_id' => $destination->id, 'quantity' => '4', 'transaction_date' => now()->toDateString(), 'reference' => 'TRF-'.$index]]])->all());
$total = (string) InventoryBalance::query()->where('item_id', $item->id)->sum('quantity');
$check('4 concurrent transfers of 4 from 10: exactly 2 commit, total quantity unchanged, legs paired', $succeeded($results) === 2 && bccomp($total, '10', 4) === 0 && $balanceOf($item, $location) === '2.0000' && $balanceOf($item, $destination) === '8.0000' && InventoryMovement::query()->where('item_id', $item->id)->whereNotNull('transfer_group')->count() === 4, $succeeded($results).' ok, source '.$balanceOf($item, $location).', destination '.$balanceOf($item, $destination).', total '.$total.$failures($results));
$check('Every balance row equals the sum of its posted movements', $inventory->inconsistentBalances()->isEmpty());

// --- Inventory: retry / double submit ---------------------------------------------------
[$item, $location] = $stock('retry', '10');
$results = $race(collect([0, 1])->map(fn (): array => ['scenario' => 'inventory_issue', 'arguments' => ['user_id' => $actors[0]->id, 'payload' => [...$issuePayload($item, $location, '2', 99), 'idempotency_key' => 'retry-'.$tag]]])->all());
$duplicates = InventoryMovement::query()->where('item_id', $item->id)->where('type', 'issue')->count();
$check('Identical issue (same idempotency key) submitted twice at once is recorded once', $duplicates === 1 && $balanceOf($item, $location) === '8.0000', $duplicates.' movement(s), balance '.$balanceOf($item, $location).$failures($results));

// --- Purchase order numbering -------------------------------------------------------------
echo PHP_EOL.'Document and voucher numbering'.PHP_EOL;
$vendor = Vendor::factory()->create();
$before = PurchaseOrder::query()->count();
$results = $race($actors->map(fn (User $actor): array => ['scenario' => 'purchase_order', 'arguments' => ['user_id' => $actor->id, 'payload' => ['vendor_id' => $vendor->id, 'branch_id' => $branchA->id, 'po_date' => now()->toDateString(), 'lines' => [['description' => 'Concurrency line', 'quantity' => '1', 'unit_price' => '10']]]]])->all());
$numbers = collect($results)->where('ok', true)->pluck('result');
$sequence = $numbers->map(fn (string $number): int => (int) Str::afterLast($number, '-'))->sort()->values();
$check('8 concurrent purchase orders get 8 distinct consecutive numbers', $numbers->count() === 8 && $numbers->unique()->count() === 8 && PurchaseOrder::query()->count() === $before + 8 && $sequence->last() - $sequence->first() === 7, $numbers->sort()->implode(', ').$failures($results));

// --- Journal posting and voucher numbering ---------------------------------------------------
$year = FiscalYear::query()->where('status', FiscalYearStatus::Open->value)->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->first()
    ?? FiscalYear::factory()->create(['name' => 'FY '.now()->year.' '.$tag, 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => FiscalYearStatus::Open, 'is_current' => false]);
$period = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->where('status', AccountingPeriodStatus::Open->value)->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->first()
    ?? AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => AccountingPeriodStatus::Open]);
$expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
$income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
// Three-person rule: the journals are prepared and approved by two users who are not among the racing posters.
$maker = $user($branchA);
$approver = $user($branchA);
$approver->syncRoles([SystemRole::Approver->value]);
$draft = function () use ($year, $period, $branchA, $expense, $income, $maker, $approver): JournalEntry {
    $journal = JournalEntry::query()->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => now()->toDateString(), 'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branchA->id, 'narration' => 'Concurrency journal', 'status' => JournalStatus::Approved, 'total_debit' => '100.0000', 'total_credit' => '100.0000', 'created_by' => $maker->id, 'submitted_by' => $maker->id, 'approved_by' => $approver->id, 'submitted_at' => now(), 'approved_at' => now()]);
    $journal->lines()->create(['line_number' => 1, 'account_id' => $expense->id, 'debit' => '100.0000', 'credit' => '0.0000']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $income->id, 'debit' => '0.0000', 'credit' => '100.0000']);

    return $journal;
};
$journals = collect(range(1, 8))->map(fn () => $draft());
$results = $race($journals->map(fn (JournalEntry $journal, int $index): array => ['scenario' => 'journal_post', 'arguments' => ['user_id' => $actors[$index]->id, 'journal_id' => $journal->id]])->all());
$vouchers = collect($results)->where('ok', true)->pluck('result');
$voucherSequence = $vouchers->map(fn (string $number): int => (int) Str::afterLast($number, '-'))->sort()->values();
$check('8 journals posted at once get 8 distinct consecutive voucher numbers', $vouchers->count() === 8 && $vouchers->unique()->count() === 8 && $voucherSequence->last() - $voucherSequence->first() === 7, $vouchers->sort()->implode(', ').$failures($results));

$single = $draft();
$results = $race($actors->take(4)->map(fn (User $actor): array => ['scenario' => 'journal_post', 'arguments' => ['user_id' => $actor->id, 'journal_id' => $single->id]])->all());
$check('The same journal posted by 4 users at once is posted exactly once', $succeeded($results) === 1 && $single->fresh()->status === JournalStatus::Posted && filled($single->fresh()->voucher_number), $succeeded($results).' ok, voucher '.$single->fresh()->voucher_number);

// --- Partial vendor payments against one bill ---------------------------------------------------
echo PHP_EOL.'Vendor bill payments'.PHP_EOL;
$payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
$bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $branchA->id, 'account_id' => Account::factory()->create()->id]);
$bill = PurchaseBill::factory()->create(['branch_id' => $branchA->id, 'vendor_id' => $vendor->id, 'payable_account_id' => $payable->id, 'status' => 'posted', 'total_amount' => '100.0000', 'created_by' => $actors[0]->id]);
$results = $race($actors->take(5)->map(fn (User $actor, int $index): array => ['scenario' => 'vendor_payment', 'arguments' => ['user_id' => $actor->id, 'payload' => ['type' => 'vendor_payment', 'branch_id' => $branchA->id, 'transaction_date' => now()->toDateString(), 'amount' => '40.0000', 'financial_account_id' => $bank->id, 'purchase_bill_id' => $bill->id, 'narration' => 'Concurrent payment '.$index]]])->all());
$reserved = (string) OperationalTransaction::query()->where('purchase_bill_id', $bill->id)->sum('amount');
$check('5 concurrent payments of 40 against a bill of 100: exactly 2 are accepted (80)', $succeeded($results) === 2 && bccomp($reserved, '80', 4) === 0, $succeeded($results).' ok, reserved '.$reserved.$failures($results));

// --- Bills against one purchase order ---------------------------------------------------------
$order = PurchaseOrder::factory()->create(['branch_id' => $branchA->id, 'vendor_id' => $vendor->id, 'status' => 'draft', 'subtotal' => '100.0000', 'total_amount' => '100.0000', 'created_by' => $actors[0]->id]);
$order->lines()->create(['line_number' => 1, 'description' => 'Concurrency order line', 'quantity' => '100.0000', 'unit_price' => '1.0000', 'line_total' => '100.0000']);
DB::table('purchase_orders')->where('id', $order->id)->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
$results = $race($actors->take(5)->map(fn (User $actor, int $index): array => ['scenario' => 'purchase_bill', 'arguments' => ['user_id' => $actor->id, 'payload' => ['vendor_id' => $vendor->id, 'branch_id' => $branchA->id, 'payable_account_id' => $payable->id, 'bill_number' => 'RACE-'.$tag.'-'.$index, 'invoice_date' => now()->toDateString(), 'purchase_order_id' => $order->id, 'lines' => [['account_id' => $expense->id, 'description' => 'Concurrent bill', 'quantity' => '1', 'unit_amount' => '40']]]]])->all());
$billed = (string) PurchaseBill::query()->where('purchase_order_id', $order->id)->sum('total_amount');
$check('5 concurrent bills of 40 against a purchase order of 100: exactly 2 are accepted (80)', $succeeded($results) === 2 && bccomp($billed, '80', 4) === 0, $succeeded($results).' ok, billed '.$billed.$failures($results));

// --- Payroll salary payments against one posted run ---------------------------------------------
echo PHP_EOL.'Payroll salary payments'.PHP_EOL;
foreach ([PayrollRunService::POLICY_SETTING, AssetPolicy::APPROVAL_SETTING] as $key) {
    // Test fixture: approve the Director policy settings in the disposable database only.
    AppSetting::query()->where('key', $key)->update(['value' => '1']);
    Cache::forget(AppSettings::cacheKey($key));
}
$payrollService = app(PayrollRunService::class);
$check('Payroll and depreciation policies are approved in '.$database, str_ends_with((string) DB::connection()->getDatabaseName(), CONCURRENCY_SUFFIX) && $payrollService->policyApproved() && app(AssetPolicy::class)->isApproved());
$checker = function (Branch $branch): User {
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Approver->value);
    $user->branches()->attach($branch->id);

    return $user;
};
$freshBranch = function () use ($actors): Branch {
    $branch = Branch::factory()->create(['status' => 'publish']);
    $actors->each(fn (User $actor) => $actor->branches()->attach($branch->id));

    return $branch;
};
$mappings = app(AccountMappingService::class);
$mappings->set(AccountMappingKey::SalaryExpense, Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure'])->id, $actors[0]);
$mappings->set(AccountMappingKey::SalaryPayable, Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit'])->id, $actors[0]);
$basic = PayrollComponent::query()->where('code', 'BASIC')->firstOrFail();

/** A posted (accrued) payroll run of one employee with the given net salary, and a cash account of its branch. */
$postedPayroll = function (string $net) use ($freshBranch, $checker, $actors, $basic, $payrollService, $period): array {
    $branch = $freshBranch();
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'created_by' => $actors[0]->id]);
    $employee->salaryItems()->create(['payroll_component_id' => $basic->id, 'amount' => $net]);
    $cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $branch->id, 'account_id' => Account::factory()->create()->id]);
    $run = $payrollService->createFromStructures(['branch_id' => $branch->id, 'accounting_period_id' => $period->id], $actors[0]);
    $run = $payrollService->validate($run, $actors[0]);
    $payrollService->submit($run, $actors[0]);
    $payrollService->approve($run->fresh(), $checker($branch));

    return [$payrollService->post($run->fresh(), $actors[0]), $cash];
};
$payJob = fn (PayrollRun $run, FinancialAccount $cash, User $actor, string $amount, string $reference): array => ['scenario' => 'payroll_pay', 'arguments' => ['user_id' => $actor->id, 'run_id' => $run->id, 'payload' => ['type' => 'cash_payment', 'financial_account_id' => $cash->id, 'amount' => $amount, 'transaction_date' => now()->toDateString(), 'reference_number' => $reference]]];
/** Linked payments, every payment transaction raised for the run, and the linked total. */
$payrollPayments = function (PayrollRun $run): array {
    $linked = PayrollRunPayment::query()->where('payroll_run_id', $run->id)->pluck('operational_transaction_id')->sort()->values();
    $raised = OperationalTransaction::query()->where('source_reference', 'payroll_run:'.$run->id)->pluck('id')->sort()->values();
    $total = (string) (OperationalTransaction::query()->whereKey($linked)->sum('amount') ?: '0');

    return [$linked, $raised, $total];
};
$losersClean = fn (array $results): bool => collect($results)->where('ok', false)->every(fn (array $result): bool => $result['error'] === 'ValidationException');
$payrollRace = function (string $label, string $net, string $amount, int $workers, int $allowed, bool $sameActor = false) use ($postedPayroll, $race, $actors, $payJob, $payrollPayments, $payrollService, $succeeded, $losersClean, $failures, $check, $tag): void {
    [$run, $cash] = $postedPayroll($net);
    $results = $race(collect(range(0, $workers - 1))->map(fn (int $index): array => $payJob($run, $cash, $actors[$sameActor ? 0 : $index], $amount, $sameActor ? 'SAL-'.$tag : 'SAL-'.$tag.'-'.$index))->all());
    [$linked, $raised, $total] = $payrollPayments($run);
    $winners = collect($results)->where('ok', true)->pluck('result')->map(fn ($id): int => (int) $id)->sort()->values();
    $expected = bcmul($amount, (string) $allowed, 4);
    $outstanding = $payrollService->outstandingAmount($run->fresh());
    $check($label, $succeeded($results) === $allowed && $linked->count() === $allowed && $raised->all() === $linked->all() && $winners->all() === $linked->all() && bccomp($total, $expected, 4) === 0 && bccomp($total, $net, 4) <= 0 && $losersClean($results) && bccomp($outstanding, bcsub($net, $expected, 4), 4) === 0,
        $succeeded($results).' ok, '.$linked->count().' linked / '.$raised->count().' raised payment(s), paid '.$total.' of net '.$net.', outstanding '.$outstanding.$failures($results));
};
for ($round = 1; $round <= $rounds; $round++) {
    $payrollRace("Round {$round}: 5 concurrent payments of the full net 1000: exactly 1 raised, total never above net", '1000.0000', '1000.0000', 5, 1);
}
$payrollRace('3 concurrent payments of 60% (600 of 1000): exactly 1 raised, losers leave no transaction', '1000.0000', '600.0000', 3, 1);
$payrollRace('4 concurrent payments of 30% (300 of 1000): exactly 3 raised (900), the 4th refused', '1000.0000', '300.0000', 4, 3);
$payrollRace('Identical full-net payment (same user, same reference) submitted twice at once is raised once', '1000.0000', '1000.0000', 2, 1, true);

// --- Depreciation: posting one approved run, preparing one branch + period -------------------------
echo PHP_EOL.'Depreciation runs'.PHP_EOL;
$depreciation = app(DepreciationRunService::class);
$assetCategory = AssetCategory::factory()->create(['code' => 'CC-'.$tag, 'default_useful_life_months' => 12]);
$activeAsset = function (Branch $branch) use ($assetCategory, $period, $actors): FixedAsset {
    $assets = app(FixedAssetService::class);
    $start = $period->start_date->toDateString();
    $asset = $assets->register(['name' => 'Concurrency asset', 'category_id' => $assetCategory->id, 'branch_id' => $branch->id, 'acquisition_date' => $start, 'in_service_date' => $start, 'acquisition_cost' => '12000.0000', 'residual_value' => '0.0000', 'useful_life_months' => 12, 'opening_accumulated_depreciation' => '0.0000', 'location' => 'Store', 'custodian' => 'Harness'], $actors[0]);

    return $assets->activate($asset, $actors[0]);
};
$activeLines = fn (FixedAsset $asset): int => DepreciationRunLine::query()->where('fixed_asset_id', $asset->id)->where('accounting_period_id', $period->id)->where('active_key', DepreciationRunLine::ACTIVE)->count();
for ($round = 1; $round <= $rounds; $round++) {
    $branch = $freshBranch();
    $asset = $activeAsset($branch);
    $run = $depreciation->prepare($branch->id, $period->id, $actors[0]);
    $depreciation->submit($run, $actors[0]);
    $depreciation->approve($run->fresh(), $checker($branch));
    $results = $race($actors->take(4)->map(fn (User $actor): array => ['scenario' => 'depreciation_post', 'arguments' => ['user_id' => $actor->id, 'run_id' => $run->id]])->all());
    $journals = JournalEntry::query()->where('source_type', DepreciationRunService::SOURCE_TYPE)->where('source_id', (string) $run->id)->get();
    $journal = $journals->first();
    $run = $run->fresh();
    $check("Round {$round}: the same depreciation run posted by 4 users at once posts exactly one journal and one voucher", $succeeded($results) === 1 && $journals->count() === 1 && $run->status === DepreciationRunStatus::Posted && $run->journal_entry_id === $journal?->id && $journal?->status === JournalStatus::Posted && filled($journal?->voucher_number) && collect($results)->where('ok', true)->pluck('result')->all() === [$journal?->voucher_number] && $activeLines($asset) === 1 && $losersClean($results),
        $succeeded($results).' ok, '.$journals->count().' journal(s), voucher '.$journals->pluck('voucher_number')->implode(', ').', '.$activeLines($asset).' active line(s) for the asset'.$failures($results));

    $branch = $freshBranch();
    $asset = $activeAsset($branch);
    $results = $race($actors->take(4)->map(fn (User $actor): array => ['scenario' => 'depreciation_prepare', 'arguments' => ['user_id' => $actor->id, 'branch_id' => $branch->id, 'period_id' => $period->id]])->all());
    $runs = DepreciationRun::query()->where('branch_id', $branch->id)->where('accounting_period_id', $period->id)->pluck('id');
    $check("Round {$round}: 4 concurrent prepares for one branch + period create exactly one run, one line per asset", $succeeded($results) === 1 && $runs->count() === 1 && collect($results)->where('ok', true)->pluck('result')->all() === $runs->all() && $activeLines($asset) === 1 && $losersClean($results),
        $succeeded($results).' ok, '.$runs->count().' run(s), '.$activeLines($asset).' active line(s) for the asset'.$failures($results));
}
$duplicateLines = DepreciationRunLine::query()->where('active_key', DepreciationRunLine::ACTIVE)->select('fixed_asset_id', 'accounting_period_id')->groupBy('fixed_asset_id', 'accounting_period_id')->havingRaw('count(*) > 1')->get();
$depreciationVouchers = JournalEntry::query()->where('source_type', DepreciationRunService::SOURCE_TYPE)->pluck('voucher_number');
$check('No asset has two active depreciation lines for one period; depreciation vouchers are distinct', $duplicateLines->isEmpty() && $depreciationVouchers->count() === $rounds && $depreciationVouchers->unique()->count() === $rounds, $duplicateLines->count().' duplicate asset/period pair(s), '.$depreciationVouchers->count().' depreciation voucher(s)');

// --- Constraints that guard the remaining races ---------------------------------------------------
// --- Phase 4: reversals, opening balances and maker-approver-poster separation ------------
// Shared fixtures are created once. Every race and ordered test below builds its own journal,
// batch or branch, so no round depends on records or voucher numbers from an earlier round.
$p4Workflow = app(JournalWorkflowService::class);
$p4Reversals = app(JournalReversalService::class);
$p4Openings = app(OpeningBalancePostingService::class);
$p4Journal = function (User $approvedBy) use ($p4Workflow, $maker, $year, $period, $branchA, $expense, $income): JournalEntry {
    auth()->setUser($maker);
    $journal = $p4Workflow->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => now()->toDateString(), 'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branchA->id, 'narration' => 'Phase 4 concurrency journal',
        'lines' => [['account_id' => $expense->id, 'debit' => '250.0000', 'credit' => '0'], ['account_id' => $income->id, 'debit' => '0', 'credit' => '250.0000']]], $maker);
    $p4Workflow->submit($journal, $maker);
    auth()->setUser($approvedBy);

    return $p4Workflow->approve($journal->fresh(), $approvedBy)->fresh();
};

// Opening balances use a separate future fiscal year so their opening date and period cannot collide with the sections above.
$openingYear = FiscalYear::factory()->create(['name' => 'FY QA Opening '.$tag, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => false]);
AccountingPeriod::factory()->create(['fiscal_year_id' => $openingYear->id, 'name' => 'January 2031', 'period_number' => 1, 'start_date' => '2031-01-01', 'end_date' => '2031-01-31', 'status' => AccountingPeriodStatus::Open]);
AppSetting::query()->updateOrCreate(['key' => 'fiscal.initial_opening_date'], ['value' => '2031-01-01']);
Cache::flush();
$openingAsset = Account::factory()->create();
$openingEquity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
$openingBranch = function () use ($actors, $maker, $approver): Branch {
    $branch = Branch::factory()->create(['status' => 'publish']);
    foreach ([...$actors->all(), $maker, $approver] as $member) {
        $member->branches()->syncWithoutDetaching([$branch->id]);
    }

    return $branch;
};
$approvedOpening = function (Branch $branch) use ($openingYear, $openingAsset, $openingEquity, $maker, $approver, $p4Openings): OpeningBalanceBatch {
    auth()->setUser($maker);
    $batch = app(OpeningBalanceService::class)->saveDraft(['fiscal_year_id' => $openingYear->id, 'branch_id' => $branch->id, 'opening_date' => '2031-01-01',
        'lines' => [['account_id' => $openingAsset->id, 'debit' => '5000.00', 'credit' => '0'], ['account_id' => $openingEquity->id, 'debit' => '0', 'credit' => '5000.00']]], $maker);
    auth()->setUser($approver);

    return $p4Openings->approve($batch, $approver)->fresh();
};
$openingJournals = fn (OpeningBalanceBatch $batch) => JournalEntry::query()->where('source_type', 'opening_balance')->where('source_id', (string) $batch->id)->get();
// An Administrator approver also holds the posting permission, so the separation rule (not a missing permission) is what refuses it.
$adminApprover = $user($branchA);
$adminApprover->syncRoles([SystemRole::Administrator->value]);
$eligible = $actors[2];
$p4Results = [];

echo PHP_EOL."Phase 4 concurrent races ({$rounds} rounds; each race starts separate processes, each with its own MySQL connection, at the same instant)".PHP_EOL;
for ($round = 1; $round <= $rounds; $round++) {
    $checksBefore = count($checks);

    // 1. The same approved manual journal posted by 4 eligible users at once.
    $journal = $p4Journal($approver);
    $results = $race($actors->slice(3, 4)->values()->map(fn (User $actor): array => ['scenario' => 'journal_post', 'arguments' => ['user_id' => $actor->id, 'journal_id' => $journal->id]])->all());
    $p4Results = [...$p4Results, ...$results];
    $fresh = $journal->fresh();
    $check("Round {$round}: one approved journal posted by 4 users at once is posted exactly once", $succeeded($results) === 1 && $fresh->status === JournalStatus::Posted && filled($fresh->voucher_number) && JournalEntry::query()->where('voucher_number', $fresh->voucher_number)->count() === 1, $succeeded($results).' ok, voucher '.$fresh->voucher_number.$failures($results));

    // 2. Concurrent reversal requests for the same posted journal.
    $original = app(JournalPostingService::class)->post($p4Journal($approver), $actors->last());
    $results = $race($actors->take(4)->map(fn (User $actor): array => ['scenario' => 'reversal_request', 'arguments' => ['user_id' => $actor->id, 'journal_id' => $original->id, 'period_id' => $period->id, 'date' => now()->toDateString()]])->values()->all());
    $p4Results = [...$p4Results, ...$results];
    $requests = JournalEntry::query()->where('reversal_of_id', $original->id)->get();
    $check("Round {$round}: 4 concurrent reversal requests for one journal: exactly one request, original still posted", $succeeded($results) === 1 && $requests->count() === 1 && $requests->first()->status === JournalStatus::Submitted && $original->fresh()->status === JournalStatus::Posted, $succeeded($results).' ok'.$failures($results));

    // 3. Concurrent posting of the same approved reversal.
    $request = $requests->first();
    if ($request !== null) {
        auth()->setUser($approver);
        $p4Reversals->approve($request, $approver);
    }
    $reversalPosters = $actors->reject(fn (User $actor): bool => $request !== null && $actor->id === $request->created_by)->take(4)->values();
    $results = $race($reversalPosters->map(fn (User $actor): array => ['scenario' => 'reversal_post', 'arguments' => ['user_id' => $actor->id, 'reversal_id' => $request?->id ?? 0]])->all());
    $p4Results = [...$p4Results, ...$results];
    $reversing = JournalEntry::query()->where('reversal_of_id', $original->id)->get();
    $check("Round {$round}: 4 concurrent posts of one approved reversal: one reversing voucher, original reversed once", $succeeded($results) === 1 && $reversing->count() === 1 && $reversing->first()->status === JournalStatus::Posted && filled($reversing->first()->voucher_number) && $original->fresh()->status === JournalStatus::Reversed, $succeeded($results).' ok'.$failures($results));

    // 4. Concurrent posting of the same approved opening balance (a new branch every round).
    $batch = $approvedOpening($openingBranch());
    $results = $race($actors->take(4)->map(fn (User $actor): array => ['scenario' => 'opening_post', 'arguments' => ['user_id' => $actor->id, 'batch_id' => $batch->id]])->values()->all());
    $p4Results = [...$p4Results, ...$results];
    $journals = $openingJournals($batch);
    $check("Round {$round}: 4 concurrent posts of one approved opening balance: exactly one posted journal linked to the batch", $succeeded($results) === 1 && $batch->fresh()->status === 'posted' && $journals->count() === 1 && $journals->first()->status === JournalStatus::Posted && $batch->fresh()->journal_entry_id === $journals->first()->id, $succeeded($results).' ok'.$failures($results));

    // 5. Return to Draft racing two posting attempts on the same approved batch.
    $returnBranch = $openingBranch();
    $secondApprover = $user($returnBranch);
    $secondApprover->syncRoles([SystemRole::Approver->value]);
    $batch = $approvedOpening($returnBranch);
    $results = $race([
        ['scenario' => 'opening_post', 'arguments' => ['user_id' => $actors[0]->id, 'batch_id' => $batch->id]],
        ['scenario' => 'opening_post', 'arguments' => ['user_id' => $actors[1]->id, 'batch_id' => $batch->id]],
        ['scenario' => 'opening_return', 'arguments' => ['user_id' => $approver->id, 'batch_id' => $batch->id]],
        ['scenario' => 'opening_return', 'arguments' => ['user_id' => $secondApprover->id, 'batch_id' => $batch->id]],
    ]);
    $p4Results = [...$p4Results, ...$results];
    $posts = count(array_filter(array_slice($results, 0, 2), fn (array $result): bool => $result['ok'] === true));
    $returns = count(array_filter(array_slice($results, 2, 2), fn (array $result): bool => $result['ok'] === true));
    $final = $batch->fresh();
    $journalCount = $openingJournals($batch)->count();
    $consistent = ($final->status === 'posted' && $posts === 1 && $returns === 0 && $journalCount === 1)
        || ($final->status === 'draft' && $returns === 1 && $posts === 0 && $journalCount === 0 && $final->approved_by === null);
    $check("Round {$round}: Return to Draft racing two posts: exactly one outcome wins and batch, approval and journal agree", $consistent, "status {$final->status}, {$posts} post(s), {$returns} return(s), {$journalCount} journal(s)".$failures($results));

    // 6. Maker, approver and an eligible poster racing to post the same journal.
    $contested = $p4Journal($adminApprover);
    $results = $race([
        ['scenario' => 'journal_post', 'arguments' => ['user_id' => $maker->id, 'journal_id' => $contested->id]],
        ['scenario' => 'journal_post', 'arguments' => ['user_id' => $adminApprover->id, 'journal_id' => $contested->id]],
        ['scenario' => 'journal_post', 'arguments' => ['user_id' => $eligible->id, 'journal_id' => $contested->id]],
    ]);
    $p4Results = [...$p4Results, ...$results];
    $check("Round {$round}: maker, approver and an eligible poster racing: only the independent poster posts", $results[0]['ok'] === false && $results[1]['ok'] === false && $results[2]['ok'] === true && (int) $contested->fresh()->posted_by === $eligible->id, 'posted by '.($contested->fresh()->posted_by ?? 'nobody').$failures($results));

    echo '  Round '.$round.': '.(count($checks) - $checksBefore).' race checks run'.PHP_EOL;
}

// --- Phase 4: controlled ordering ---------------------------------------------------------
// These tests SEQUENCE operations rather than overlap them: each step is a separate worker
// process with its own connection, and the next step starts only after the previous worker has
// exited and reported its result (the acknowledgement). They prove the rule for a given order;
// they are not simultaneous lock contention, which the races above cover.
echo PHP_EOL.'Phase 4 controlled ordering (sequenced: each step starts only after the previous worker has reported; not simultaneous)'.PHP_EOL;
$runOne = function (string $scenario, array $arguments): array {
    $payload = base64_encode(json_encode([...$arguments, 'start_at' => 0]));
    $process = proc_open([PHP_BINARY, __DIR__.'/worker.php', $scenario, $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    proc_close($process);

    return json_decode((string) $output, true) ?? ['ok' => false, 'error' => 'WorkerCrash', 'message' => mb_substr(trim($errors.' '.$output), 0, 200)];
};
$describe = fn (array $result): string => $result['ok'] ? 'ok' : $result['error'].': '.Str::limit((string) ($result['message'] ?? ''), 90);
$orderedJournal = function (User $refused, string $reason, User $approvedBy, string $label) use ($p4Journal, $runOne, $describe, $check, $eligible, $actors, &$p4Results): void {
    $journal = $p4Journal($approvedBy);
    $first = $runOne('journal_post', ['user_id' => $refused->id, 'journal_id' => $journal->id]);
    $afterFirst = $journal->fresh();
    $check("Ordered {$label}: posting first is refused ({$reason}); the journal stays Approved with no voucher", $first['ok'] === false && str_contains((string) ($first['message'] ?? ''), $reason) && $afterFirst->status === JournalStatus::Approved && $afterFirst->voucher_number === null && $afterFirst->posted_by === null, $describe($first));
    $second = $runOne('journal_post', ['user_id' => $eligible->id, 'journal_id' => $journal->id]);
    $third = $runOne('journal_post', ['user_id' => $actors[3]->id, 'journal_id' => $journal->id]);
    $final = $journal->fresh();
    $check("Ordered {$label}: an independent poster then posts it exactly once", $second['ok'] === true && $third['ok'] === false && $final->status === JournalStatus::Posted && (int) $final->posted_by === $eligible->id && JournalEntry::query()->where('voucher_number', $final->voucher_number)->count() === 1, $describe($second).' then '.$describe($third));
    $p4Results = [...$p4Results, $first, $second, $third];
};

// A. The maker tries to post while the journal is still Approved.
$orderedJournal($maker, 'You prepared this journal', $approver, 'A (maker first)');
// B. The approver, who also holds the posting permission, tries to post while it is still Approved.
$orderedJournal($adminApprover, 'You approved this journal', $adminApprover, 'B (approver first)');

// C. Opening balance: an eligible poster completes the post first, then Return to Draft is attempted.
$batch = $approvedOpening($openingBranch());
$first = $runOne('opening_post', ['user_id' => $eligible->id, 'batch_id' => $batch->id]);
$posted = $batch->fresh();
$journals = $openingJournals($batch);
$journal = $journals->first();
$sums = $journal ? DB::table('journal_lines')->where('journal_entry_id', $journal->id)->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first() : null;
$postedAudit = AuditLog::query()->where('event', 'opening_balance.posted')->where('auditable_id', $batch->id)->first();
$identities = $journal !== null
    && [(int) $posted->created_by, (int) $posted->approved_by, (int) $posted->posted_by] === [$maker->id, $approver->id, $eligible->id]
    && [(int) $journal->created_by, (int) $journal->approved_by, (int) $journal->posted_by] === [$maker->id, $approver->id, $eligible->id]
    && (int) $postedAudit?->actor_id === $eligible->id && (int) ($postedAudit?->properties['approved_by'] ?? 0) === $approver->id;
$check('Ordered C (post first): one balanced journal, batch Posted, maker, approver and poster recorded', $first['ok'] === true && $posted->status === 'posted' && $journals->count() === 1 && (int) $posted->journal_entry_id === $journal->id
    && bccomp((string) $sums->d, (string) $sums->c, 4) === 0 && bccomp((string) $sums->d, (string) $journal->total_debit, 4) === 0 && $identities, $describe($first));
$second = $runOne('opening_return', ['user_id' => $approver->id, 'batch_id' => $batch->id]);
$after = $batch->fresh();
$check('Ordered C (post first): the later Return to Draft is refused; the batch stays Posted with its approval and one journal', $second['ok'] === false && str_contains((string) ($second['message'] ?? ''), 'has not been posted') && $after->status === 'posted' && (int) $after->approved_by === $approver->id && $openingJournals($batch)->count() === 1, $describe($second));
$p4Results = [...$p4Results, $first, $second];

// --- Integrity after all concurrent activity ----------------------------------------------
echo PHP_EOL.'Integrity after concurrent activity'.PHP_EOL;
$unbalanced = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
    ->whereIn('journal_entries.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
    ->select('journal_lines.journal_entry_id')->groupBy('journal_lines.journal_entry_id')->havingRaw('SUM(journal_lines.debit) <> SUM(journal_lines.credit)')->pluck('journal_entry_id');
$check('Every posted journal balances line by line', $unbalanced->isEmpty(), $unbalanced->implode(', '));
$headerMismatch = DB::table('journal_entries as e')->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
    ->whereRaw('e.total_debit <> (select coalesce(sum(l.debit), 0) from journal_lines l where l.journal_entry_id = e.id)')->count();
$check('Posted journal totals equal the sum of their lines (no partial lines)', $headerMismatch === 0, $headerMismatch.' mismatched');
$leftovers = JournalEntry::query()->where('source_type', 'opening_balance')->where('status', '!=', JournalStatus::Posted->value)->count();
$check('Losing opening balance workers left no unposted journal behind', $leftovers === 0, $leftovers.' left');
$duplicateVouchers = DB::table('journal_entries')->select('voucher_number')->whereNotNull('voucher_number')->groupBy('voucher_number')->havingRaw('COUNT(*) > 1')->get()->count();
$duplicateKeys = DB::table('journal_entries')->select('source_key')->whereNotNull('source_key')->groupBy('source_key')->havingRaw('COUNT(*) > 1')->get()->count();
$check('No duplicate journal voucher numbers or source keys', $duplicateVouchers === 0 && $duplicateKeys === 0, "{$duplicateVouchers} voucher, {$duplicateKeys} source key duplicates");
$selfPosted = JournalEntry::query()->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
    ->where(fn ($query) => $query->whereNull('source_type')->orWhere('source_type', 'journal_reversal'))
    ->where(fn ($query) => $query->whereColumn('posted_by', 'created_by')->orWhereColumn('posted_by', 'submitted_by')->orWhereColumn('posted_by', 'approved_by'))->count();
$selfPostedOpening = OpeningBalanceBatch::query()->where('status', 'posted')
    ->where(fn ($query) => $query->whereColumn('posted_by', 'created_by')->orWhereColumn('posted_by', 'updated_by')->orWhereColumn('posted_by', 'approved_by'))->count();
$check('No manual journal, reversal or opening balance was posted by its preparer or approver', $selfPosted === 0 && $selfPostedOpening === 0, "{$selfPosted} journal(s), {$selfPostedOpening} opening balance(s)");
$unexpected = collect($p4Results)->where('ok', false)->reject(fn (array $result): bool => in_array($result['error'], ['ValidationException', 'HttpException', 'AccessDeniedHttpException'], true));
$check('Phase 4 workers raised no unexpected error (SQL, lock wait, deadlock or crash)', $unexpected->isEmpty(), count($p4Results).' worker result(s); '.$unexpected->map(fn (array $result): string => $result['error'].': '.Str::limit((string) ($result['message'] ?? ''), 80))->implode('; '));
$branchIds = Branch::query()->pluck('id')->all();
foreach ([$year, $openingYear] as $trialYear) {
    $trial = app(TrialBalanceService::class)->report($trialYear->id, $trialYear->end_date->toDateString(), $branchIds);
    $check("Trial Balance for {$trialYear->name} is balanced after concurrent posting", $trial['balanced'] === true, $trial['total_debit'].' / '.$trial['total_credit']);
}

echo PHP_EOL.'Unique constraints present on MySQL'.PHP_EOL;
$unique = collect(DB::select('select table_name as t, group_concat(column_name order by seq_in_index) as c from information_schema.statistics where table_schema = database() and non_unique = 0 group by table_name, index_name'))->map(fn ($row): string => $row->t.'('.$row->c.')');
foreach (['depreciation_run_lines(fixed_asset_id,accounting_period_id,active_key)', 'payroll_runs(branch_id,accounting_period_id,active_key)', 'journal_entries(source_key)', 'journal_entries(voucher_number)', 'voucher_sequences(voucher_type_id,scope_key)', 'document_sequences(document_type,scope_key)', 'purchase_orders(po_number)', 'inventory_balances(item_id,branch_id,location_id)'] as $expected) {
    $check('Unique index '.$expected, $unique->contains($expected));
}

$failed = count(array_filter($checks, fn (array $item): bool => ! $item['passed']));
echo PHP_EOL.count($checks).' checks, '.$failed.' failed.'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
