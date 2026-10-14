<?php

/*
 * Real MySQL/InnoDB concurrency verification.
 *
 *   php tests/Concurrency/run.php [--rounds=5] [--fresh] [--drop]
 *
 * Creates (or reuses) the disposable database "<dev database>_concurrency_test",
 * migrates it, builds isolated fixtures and launches several PHP processes that
 * hit the same rows at the same moment. --fresh drops and recreates that one
 * database first; --drop removes it at the end. The development database is
 * never read or written.
 */

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\InventoryBalance;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\Inventory\InventoryService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
$root = dirname(__DIR__, 2);
$options = getopt('', ['rounds::', 'fresh', 'drop']);
$rounds = max(1, (int) ($options['rounds'] ?? 5));

// 1. Create the disposable database with a raw connection (no application boot yet).
$env = concurrencyEnv($root);
$database = concurrencyDatabase($root);
if (! str_ends_with($database, CONCURRENCY_SUFFIX)) {
    exit("Refusing to run.\n");
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'), $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (isset($options['fresh'])) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// 2. Boot against it (guarded) and migrate forward.
concurrencyBoot($root);
Artisan::call('migrate', ['--force' => true]);
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
$approver = $user($branchA);
$draft = function () use ($year, $period, $branchA, $expense, $income, $actors, $approver): JournalEntry {
    $journal = JournalEntry::query()->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => now()->toDateString(), 'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branchA->id, 'narration' => 'Concurrency journal', 'status' => JournalStatus::Approved, 'total_debit' => '100.0000', 'total_credit' => '100.0000', 'created_by' => $actors[0]->id, 'submitted_by' => $actors[0]->id, 'approved_by' => $approver->id, 'submitted_at' => now(), 'approved_at' => now()]);
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

// --- Constraints that guard the remaining races ---------------------------------------------------
echo PHP_EOL.'Unique constraints present on MySQL'.PHP_EOL;
$unique = collect(DB::select('select table_name as t, group_concat(column_name order by seq_in_index) as c from information_schema.statistics where table_schema = database() and non_unique = 0 group by table_name, index_name'))->map(fn ($row): string => $row->t.'('.$row->c.')');
foreach (['depreciation_run_lines(fixed_asset_id,accounting_period_id,active_key)', 'payroll_runs(branch_id,accounting_period_id,active_key)', 'journal_entries(source_key)', 'journal_entries(voucher_number)', 'voucher_sequences(voucher_type_id,scope_key)', 'document_sequences(document_type,scope_key)', 'purchase_orders(po_number)', 'inventory_balances(item_id,branch_id,location_id)'] as $expected) {
    $check('Unique index '.$expected, $unique->contains($expected));
}

$failed = count(array_filter($checks, fn (array $item): bool => ! $item['passed']));
echo PHP_EOL.count($checks).' checks, '.$failed.' failed.'.PHP_EOL;
if (isset($options['drop'])) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    echo "Disposable database {$database} dropped.".PHP_EOL;
}
exit($failed === 0 ? 0 : 1);
