<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Services\BankReconciliation\BankBook;
use App\Services\BankReconciliation\ReconciliationReport;
use App\Services\BankReconciliation\ReconciliationService;
use App\Services\FeePosting\FeeSettlementMappingService;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branchA = Branch::factory()->create(['status' => 'publish', 'name' => 'Shared Bank Branch A']);
    $this->branchB = Branch::factory()->create(['status' => 'publish', 'name' => 'Shared Bank Branch B']);
    $bankLedger = fn (string $code) => Account::factory()->create(['code' => $code, 'financial_account_type' => 'bank']);
    $this->mcbLedger = $bankLedger('11203');
    $this->otherBankLedger = $bankLedger('11204');
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->accountantB = User::factory()->create();
    $this->accountantB->assignRole(SystemRole::Accountant->value);
    $this->accountantB->branches()->attach($this->branchB);

    $this->mcb = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'MCB Main', 'branch_id' => $this->branchA, 'account_id' => $this->mcbLedger, 'scope' => FinancialAccount::SCOPE_ORGANIZATION]);
    $this->branchBank = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Branch A Local Bank', 'branch_id' => $this->branchA, 'account_id' => $this->otherBankLedger]);
});

function sharedBankReceipt(object $test, Branch $branch, FinancialAccount $account, string $amount, User $actor): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $test->actingAs($actor);
    $receipt = $service->create([
        'type' => OperationalTransactionType::BankReceipt->value, 'branch_id' => $branch->id, 'transaction_date' => '2026-01-15', 'party_name' => 'Fee payer',
        'amount' => $amount, 'financial_account_id' => $account->id, 'contra_account_id' => $test->income->id, 'narration' => 'Shared bank receipt',
    ], $actor);

    return $service->post($receipt, $actor);
}

function sharedBankForm(object $test, array $overrides = []): array
{
    return ['type' => 'bank', 'name' => 'MCB Shared', 'account_id' => $test->mcbLedger->id, 'branch_id' => $test->branchA->id, 'scope' => 'organization',
        'bank_name' => 'MCB Bank', 'account_title' => 'FRC', 'account_number' => 'PK36SCBL0000001123456702', 'currency' => 'PKR', ...$overrides];
}

test('every branch can use the shared bank account and each transaction keeps its own branch', function () {
    $fromA = sharedBankReceipt($this, $this->branchA, $this->mcb, '100.0000', $this->admin);
    $fromB = sharedBankReceipt($this, $this->branchB, $this->mcb, '250.0000', $this->accountantB);

    expect((int) $fromA->branch_id)->toBe($this->branchA->id)->and((int) $fromB->branch_id)->toBe($this->branchB->id)
        ->and((int) JournalEntry::query()->findOrFail($fromB->journal_entry_id)->branch_id)->toBe($this->branchB->id)
        ->and((int) JournalEntry::query()->findOrFail($fromA->journal_entry_id)->branch_id)->toBe($this->branchA->id)
        ->and($this->mcb->usableIn($this->branchB->id))->toBeTrue()
        ->and($this->branchBank->usableIn($this->branchB->id))->toBeFalse();

    // A branch-only account is still refused for another branch.
    expect(fn () => sharedBankReceipt($this, $this->branchB, $this->branchBank, '10.0000', $this->accountantB))->toThrow(ValidationException::class);

    $form = $this->actingAs($this->accountantB)->get(route('transactions.create', ['type' => 'bank_receipt']))->assertOk()->getContent();
    expect($form)->toMatch('/<option value="'.$this->mcb->id.'"[^>]*data-shared="1"/')->not->toContain('Branch A Local Bank');
});

test('the shared account has one consolidated book, while a branch account keeps its own branch', function () {
    sharedBankReceipt($this, $this->branchA, $this->mcb, '100.0000', $this->admin);
    sharedBankReceipt($this, $this->branchB, $this->mcb, '250.0000', $this->accountantB);
    $branchOnly = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchB, 'account_id' => $this->otherBankLedger]);
    sharedBankReceipt($this, $this->branchA, $this->branchBank, '40.0000', $this->admin);

    $book = app(BankBook::class);
    expect($book->lines($this->mcb)->count())->toBe(2)
        ->and((string) $book->lines($this->mcb)->sum('journal_lines.debit'))->toStartWith('350')
        ->and($book->lines($this->branchBank)->count())->toBe(1)
        ->and($book->lines($branchOnly)->count())->toBe(0);

    // The all-branch user sees the consolidated balance on the account list.
    $this->actingAs($this->admin)->get(route('financial-accounts.index'))->assertOk()->assertSee('All Branches — Shared')->assertSee('350.00');
});

test('consolidated statements, balances and reconciliation need access to every branch', function () {
    sharedBankReceipt($this, $this->branchA, $this->mcb, '100.0000', $this->admin);
    sharedBankReceipt($this, $this->branchB, $this->mcb, '250.0000', $this->accountantB);
    $reconciliations = app(ReconciliationService::class);

    expect(fn () => $reconciliations->authorizeAccount($this->mcb, $this->accountantB, 'bank_reconciliation.view'))->toThrow(HttpException::class);
    $reconciliations->authorizeAccount($this->mcb, $this->admin, 'bank_reconciliation.view');

    $this->actingAs($this->accountantB)->get(route('financial-accounts.index'))->assertOk()->assertDontSee('MCB Main');
    $this->get(route('financial-accounts.edit', $this->mcb))->assertForbidden();

    // The branch user's bank book shows only their own branch's lines on the shared account.
    $book = $this->get(route('reports.books', ['kind' => 'bank']))->assertOk()->getContent();
    expect($book)->toContain('250.00')->not->toContain('100.00')->toContain('MCB Main');
});

test('only an all-branch user can create a shared account, only for bank, on a ledger of its own', function () {
    $this->mcb->delete();

    $this->actingAs($this->accountantB)->post(route('financial-accounts.store'), sharedBankForm($this, ['branch_id' => $this->branchB->id]))->assertForbidden();
    $this->actingAs($this->admin)->post(route('financial-accounts.store'), sharedBankForm($this, ['type' => 'cash']))->assertSessionHasErrors('scope');
    // The ledger is already used by a branch account.
    $this->post(route('financial-accounts.store'), sharedBankForm($this, ['account_id' => $this->otherBankLedger->id]))->assertSessionHasErrors('account_id');
    expect(FinancialAccount::query()->where('scope', 'organization')->exists())->toBeFalse();

    $this->post(route('financial-accounts.store'), sharedBankForm($this))->assertSessionHasNoErrors();
    $shared = FinancialAccount::query()->where('scope', 'organization')->firstOrFail();
    expect($shared->account_id)->toBe($this->mcbLedger->id)->and((int) $shared->branch_id)->toBe($this->branchA->id);

    // No branch account may join the shared ledger afterwards.
    $this->post(route('financial-accounts.store'), sharedBankForm($this, ['scope' => 'branch', 'branch_id' => $this->branchB->id, 'name' => 'Duplicate MCB']))->assertSessionHasErrors('account_id');
    expect(FinancialAccount::query()->where('account_id', $this->mcbLedger->id)->count())->toBe(1);
});

test('a used branch account can be made shared, but a used shared account cannot go back to one branch', function () {
    sharedBankReceipt($this, $this->branchA, $this->branchBank, '40.0000', $this->admin);
    $payload = fn (FinancialAccount $account, string $scope) => sharedBankForm($this, ['name' => $account->name, 'account_id' => $account->account_id, 'branch_id' => $account->branch_id, 'scope' => $scope]);

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $this->branchBank), $payload($this->branchBank, 'organization'))->assertSessionHasNoErrors();
    expect($this->branchBank->fresh()->isShared())->toBeTrue()->and(JournalEntry::query()->where('status', 'posted')->count())->toBe(1);

    $this->put(route('financial-accounts.update', $this->branchBank), $payload($this->branchBank->fresh(), 'branch'))->assertSessionHasErrors('scope');
    expect($this->branchBank->fresh()->isShared())->toBeTrue();
});

test('fee settlement mapping accepts the shared account for any branch but not another branch account', function () {
    $fees = app(FeeSettlementMappingService::class);

    expect((string) $fees->accountIssue($this->mcb, $this->branchB->id, 'bank'))->not->toContain('another branch')
        ->and($fees->accountIssue($this->branchBank, $this->branchB->id, 'bank'))->toContain('another branch');
});

test('new reconciliation is offered only with the permission and a bank account the user may reconcile', function () {
    $this->actingAs($this->admin)->get(route('bank-reconciliations.index'))->assertOk()
        ->assertSee('Bank Accounts — Branch-Specific &amp; Shared', false)->assertDontSee('One bank account per ledger and branch')
        ->assertSee(route('bank-reconciliations.create'), false);

    // Branch B has only the shared MCB, which a branch-limited user may not reconcile.
    $this->actingAs($this->accountantB)->get(route('bank-reconciliations.index'))->assertOk()->assertDontSee(route('bank-reconciliations.create'), false);

    FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchB, 'account_id' => $this->otherBankLedger]);
    $this->get(route('bank-reconciliations.index'))->assertOk()->assertSee(route('bank-reconciliations.create'), false);

    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $viewer->branches()->attach($this->branchB);
    $this->actingAs($viewer)->get(route('bank-reconciliations.index'))->assertOk()->assertDontSee(route('bank-reconciliations.create'), false);
});

test('a branch-limited user of the custodian branch cannot reconcile or upload statements for the shared account by direct request', function () {
    $accountantA = User::factory()->create();
    $accountantA->assignRole(SystemRole::Accountant->value);
    $accountantA->branches()->attach($this->branchA);
    $this->actingAs($accountantA);
    $payload = ['statement_date' => '2026-01-31', 'statement_closing_balance' => '0.00'];

    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->mcb->id])->assertForbidden();
    $this->post(route('bank-statements.store'), ['financial_account_id' => $this->mcb->id, 'statement_file' => UploadedFile::fake()->createWithContent('mcb.csv', "date,description,debit,credit\n")])->assertForbidden();
    expect(BankReconciliation::query()->count())->toBe(0)->and(BankStatement::query()->count())->toBe(0);

    // The same user can still reconcile the branch's own bank account.
    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->branchBank->id])->assertSessionHasNoErrors()->assertRedirect();
    expect(BankReconciliation::query()->where('financial_account_id', $this->branchBank->id)->count())->toBe(1);
});

test('the statement upload form appears only with the permission and a bank account the user may upload for', function () {
    $message = 'No bank accounts available for statement upload';
    $this->actingAs($this->admin)->get(route('bank-statements.index'))->assertOk()->assertSee('name="statement_file"', false)->assertDontSee($message);

    // Branch B has only the shared MCB, which a branch-limited user may not upload for.
    $this->actingAs($this->accountantB)->get(route('bank-statements.index'))->assertOk()
        ->assertDontSee('name="statement_file"', false)->assertDontSee('>Upload</button>', false)->assertSee($message);

    FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchB, 'account_id' => $this->otherBankLedger]);
    $this->get(route('bank-statements.index'))->assertOk()->assertSee('name="statement_file"', false)->assertDontSee($message);

    // Viewing without the upload permission keeps the listing but offers no upload at all.
    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $viewer->branches()->attach($this->branchB);
    $this->actingAs($viewer)->get(route('bank-statements.index'))->assertOk()->assertSee('No statements')
        ->assertDontSee('name="statement_file"', false)->assertDontSee($message);
});

test('the reconciliation statement and export show the shared scope and custodian instead of a single branch', function () {
    $this->actingAs($this->admin);
    $payload = ['statement_date' => '2026-01-31', 'statement_closing_balance' => '0.00'];
    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->mcb->id])->assertSessionHasNoErrors();
    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->branchBank->id])->assertSessionHasNoErrors();
    $shared = BankReconciliation::query()->where('financial_account_id', $this->mcb->id)->firstOrFail();
    $local = BankReconciliation::query()->where('financial_account_id', $this->branchBank->id)->firstOrFail();

    $this->get(route('bank-reconciliations.statement', $shared))->assertOk()
        ->assertSee('All Branches — Shared')->assertSee('Custodian branch: Shared Bank Branch A')->assertDontSee('<small>Branch</small>', false);
    $this->get(route('bank-reconciliations.show', $shared))->assertOk()->assertSee('All Branches — Shared (custodian: Shared Bank Branch A)');
    $this->get(route('bank-reconciliations.statement', $local))->assertOk()
        ->assertSee('<small>Branch</small>', false)->assertDontSee('All Branches — Shared');

    $report = app(ReconciliationReport::class);
    expect($report->table($shared)->meta)->toMatchArray(['Account scope' => 'All Branches — Shared', 'Custodian branch' => 'Shared Bank Branch A'])->not->toHaveKey('Branch')
        ->and($report->table($local)->meta)->toMatchArray(['Branch' => 'Shared Bank Branch A'])->not->toHaveKey('Account scope');
    $this->get(route('bank-reconciliations.export', [$shared, 'csv']))->assertOk();
});

test('the statement subtitle and reconciliation listing name the shared scope and custodian', function () {
    $this->actingAs($this->admin);
    $payload = ['statement_date' => '2026-01-31', 'statement_closing_balance' => '0.00'];
    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->mcb->id])->assertSessionHasNoErrors();
    $this->post(route('bank-reconciliations.store'), [...$payload, 'financial_account_id' => $this->branchBank->id])->assertSessionHasNoErrors();
    $shared = BankReconciliation::query()->where('financial_account_id', $this->mcb->id)->firstOrFail();

    $this->get(route('bank-reconciliations.statement', $shared))->assertOk()->assertSee('MCB Main • All Branches — Shared (custodian: Shared Bank Branch A)');

    $index = $this->get(route('bank-reconciliations.index'))->assertOk()->getContent();
    // Both tables (accounts and reconciliations) label the shared account; the branch account keeps its branch.
    expect(substr_count($index, 'All Branches — Shared'))->toBe(2)
        ->and(substr_count($index, 'Custodian: Shared Bank Branch A'))->toBe(2)
        ->and($index)->toContain('<td>Shared Bank Branch A</td>');
});

test('a shared account draft can be cancelled only by a user with access to every branch', function () {
    $this->actingAs($this->admin)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->mcb->id, 'statement_date' => '2026-01-31', 'statement_closing_balance' => '0.00'])->assertSessionHasNoErrors();
    $draft = BankReconciliation::query()->where('financial_account_id', $this->mcb->id)->firstOrFail();
    $accountantA = User::factory()->create();
    $accountantA->assignRole(SystemRole::Accountant->value);
    $accountantA->branches()->attach($this->branchA);

    $this->actingAs($accountantA)->post(route('bank-reconciliations.cancel', $draft), ['cancellation_reason' => 'Custodian branch cannot cancel'])->assertForbidden();
    expect($draft->fresh()->isDraft())->toBeTrue();
    $this->actingAs($this->admin)->post(route('bank-reconciliations.cancel', $draft), ['cancellation_reason' => 'Started with the wrong date'])->assertSessionHasNoErrors();
    expect($draft->fresh()->isCancelled())->toBeTrue()->and($draft->fresh()->open_key)->toBeNull();
});

test('a shared statement shows its scope and stays on record while a cancelled reconciliation refers to it', function () {
    $this->actingAs($this->admin);
    $statement = BankStatement::factory()->create(['financial_account_id' => $this->mcb->id, 'branch_id' => $this->branchA->id, 'uploaded_by' => $this->admin->id, 'status' => BankStatement::IMPORTED]);
    $local = BankStatement::factory()->create(['financial_account_id' => $this->branchBank->id, 'branch_id' => $this->branchA->id, 'uploaded_by' => $this->admin->id]);

    $this->get(route('bank-statements.show', $statement))->assertOk()->assertSee('MCB Main • All Branches — Shared (custodian: Shared Bank Branch A)');
    $this->get(route('bank-statements.show', $local))->assertOk()->assertSee('Branch A Local Bank • Shared Bank Branch A •')->assertDontSee('All Branches — Shared');

    $this->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->mcb->id, 'bank_statement_id' => $statement->id])->assertSessionHasNoErrors();
    $reconciliation = BankReconciliation::query()->where('bank_statement_id', $statement->id)->firstOrFail();
    $this->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Dummy statement test run'])->assertSessionHasNoErrors();

    $this->delete(route('bank-statements.destroy', $statement))->assertSessionHasErrors('statement');
    expect($statement->fresh())->not->toBeNull()->and($reconciliation->fresh()->isCancelled())->toBeTrue()
        ->and($reconciliation->fresh()->bank_statement_id)->toBe($statement->id);
});

test('withdraw is offered only for an unreferenced statement; a statement of a cancelled reconciliation is retained', function () {
    $this->actingAs($this->admin);
    $make = fn (FinancialAccount $account) => BankStatement::factory()->create(['financial_account_id' => $account->id, 'branch_id' => $this->branchA->id, 'uploaded_by' => $this->admin->id, 'status' => BankStatement::IMPORTED]);
    $retained = $make($this->mcb);
    $free = $make($this->branchBank);
    $this->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->mcb->id, 'bank_statement_id' => $retained->id])->assertSessionHasNoErrors();
    $this->post(route('bank-reconciliations.cancel', BankReconciliation::query()->where('bank_statement_id', $retained->id)->firstOrFail()), ['cancellation_reason' => 'Dummy statement test run'])->assertSessionHasNoErrors();

    $this->get(route('bank-statements.show', $retained))->assertOk()->assertDontSee('Withdraw statement')->assertSee('Used by a reconciliation; retained for audit history.');
    $this->get(route('bank-statements.show', $free))->assertOk()->assertSee('Withdraw statement')->assertDontSee('retained for audit history');

    // Without the prepare permission there is no button, and no misleading retention note for an unreferenced statement.
    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $viewer->branches()->attach($this->branchA);
    $this->actingAs($viewer)->get(route('bank-statements.show', $free))->assertOk()->assertDontSee('Withdraw statement')->assertDontSee('retained for audit history');

    // The eligible statement can still be withdrawn; the retained one is still refused on the server.
    $this->actingAs($this->admin)->delete(route('bank-statements.destroy', $free))->assertSessionHasNoErrors();
    $this->delete(route('bank-statements.destroy', $retained))->assertSessionHasErrors('statement');
    expect($free->fresh())->toBeNull()->and($retained->fresh())->not->toBeNull();
});
