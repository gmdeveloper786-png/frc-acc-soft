<?php

/*
 * READ-ONLY sanity check of the isolated UAT database after
 * `php tests/Uat/setup.php --fresh`. Boots through uatBoot() (which refuses
 * anything but a *_uat database), writes nothing and prints counts only
 * (no passwords, no .env values).
 *
 *   php tests/Uat/check-uat.php
 */

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Budget;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FixedAsset;
use App\Models\FrcService;
use App\Models\Fund;
use App\Models\ImprestFloat;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require __DIR__.'/bootstrap.php';
uatBoot(dirname(__DIR__, 2));

$line = fn (string $label, mixed $value) => print str_pad($label, 34).': '.$value.PHP_EOL;
$status = fn (string $model) => $model::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->get()
    ->map(fn ($row) => (is_object($row->status) ? $row->status->value : $row->status).'='.$row->n)->implode(', ') ?: 'none';

$line('database', DB::connection()->getDatabaseName());
$line('users', User::query()->count());
$line('roles', Role::query()->count());
$line('accounts (ledgers)', Account::query()->count().' ('.Account::query()->where('level', 'ledger')->count().')');
$line('account mappings', AccountMapping::query()->count());
$line('financial accounts', FinancialAccount::query()->orderBy('name')->pluck('name')->implode(', '));
foreach (Fund::query()->with('permittedAccounts')->orderBy('code')->get() as $fund) {
    $line("fund {$fund->code} ({$fund->type})", $fund->permittedAccounts->pluck('code')->implode(', ') ?: 'no permitted ledgers');
}
$grant = Fund::query()->where('code', 'GRT-01')->first();
$line('GRT-01 grant', $grant ? "{$grant->donor_name}, {$grant->grant_amount}, {$grant->start_date?->format('Y-m-d')}..{$grant->end_date?->format('Y-m-d')}, parent ".$grant->parent?->code : 'missing');
$line('donation category funds', DB::table('donation_categories')->leftJoin('funds', 'funds.id', '=', 'donation_categories.fund_id')->orderBy('donation_categories.name')->get(['donation_categories.name', 'funds.code'])->map(fn ($r) => "{$r->name}→".($r->code ?? '-'))->implode(', '));
$line('imprest floats', ImprestFloat::query()->with(['branch', 'account'])->get()->map(fn ($f) => "{$f->branch->name} {$f->account->code} {$f->float_amount} {$f->custodian_name}")->implode('; ') ?: 'none');
$line('employees', Employee::query()->with('branch')->orderBy('employee_code')->get()->map(fn ($e) => "{$e->employee_code}@{$e->branch->name}")->implode(', '));
$line('salary structure items', DB::table('employee_salary_items')->count());
$line('payroll components', PayrollComponent::query()->orderBy('sort_order')->get()->map(fn ($c) => $c->code.($c->account_id ? '→'.Account::query()->find($c->account_id)?->code : ''))->implode(', '));
$line('payroll runs', DB::table('payroll_runs')->count());
$line('inventory items', InventoryItem::query()->orderBy('item_code')->pluck('item_code')->implode(', '));
$line('inventory balances', InventoryBalance::query()->with(['item', 'location'])->get()->map(fn ($b) => "{$b->item->item_code}@{$b->location->name}={$b->quantity}")->implode(', '));
$line('fixed assets (status)', $status(FixedAsset::class));
$line('budgets (status)', $status(Budget::class));
$line('operational transactions (status)', $status(OperationalTransaction::class));
$line('frc services', FrcService::query()->count());
$migrator = app('migrator');
$pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
$line('pending migrations', count($pending) === 0 ? 'none' : implode(', ', $pending));
$line('journals (status)', $status(JournalEntry::class));
$line('posted/reversed journals', JournalEntry::query()->whereIn('status', ['posted', 'reversed'])->count());
$line('journal lines', DB::table('journal_lines')->count());
$authority = app(ApprovalAuthorityService::class);
$line('Director users', User::query()->get()->filter(fn (User $u) => $authority->holdsDirectorRole($u))->pluck('email')->implode(', ') ?: 'none');
$settings = app(AppSettings::class);
foreach (['fees.accounting_policy_approved', 'assets.depreciation_policy_approved', 'payroll.calculation_policy_approved'] as $key) {
    $line($key, $settings->string($key, '0'));
}
