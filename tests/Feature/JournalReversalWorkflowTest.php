<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\ApprovalAuthorityService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\TrialBalanceService;
use App\Services\YearEndClosingService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->accountant = reversalUser(SystemRole::Accountant, $this->branch);
    $this->poster = reversalUser(SystemRole::Accountant, $this->branch);
    $this->approver = reversalUser(SystemRole::Approver, $this->branch);
    $this->admin = reversalUser(SystemRole::Administrator, $this->branch);
    $this->director = reversalUser(SystemRole::Director, $this->branch);
    $this->outsideDirector = reversalUser(SystemRole::Director, $this->otherBranch);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->jan = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->feb = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'February 2026', 'period_number' => 2, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => AccountingPeriodStatus::Open]);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->service = FrcService::factory()->create(['status' => 'publish']);
    $this->reversals = app(JournalReversalService::class);
});

function reversalUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A posted manual journal prepared by $maker, approved by an eligible approver and posted by the Administrator. */
function postedManualJournal(object $test, string $amount = '10000.0000', ?User $maker = null): JournalEntry
{
    $maker ??= $test->accountant;
    $workflow = app(JournalWorkflowService::class);
    $test->actingAs($maker);
    $journal = $workflow->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->jan->id, 'branch_id' => $test->branch->id,
        'narration' => 'Electricity accrual fixture', 'reference_number' => 'UAT-REV',
        'lines' => [
            ['account_id' => $test->expense->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Electricity', 'service_id' => $test->service->id],
            ['account_id' => $test->income->id, 'debit' => '0', 'credit' => $amount],
        ],
    ], $maker);
    $workflow->submit($journal, $maker);
    $checker = app(ApprovalAuthorityService::class)->exceedsJournalThreshold($amount) ? $test->director : $test->approver;
    $test->actingAs($checker);
    $workflow->approve($journal->fresh(), $checker);

    // A third user posts: the Administrator, unless the Administrator prepared the journal.
    return app(JournalPostingService::class)->post($journal->fresh(), $maker->is($test->admin) ? $test->poster : $test->admin);
}

function reversalForm(array $overrides = []): array
{
    return ['accounting_period_id' => AccountingPeriod::query()->where('name', 'February 2026')->value('id'), 'transaction_date' => '2026-02-05', 'reason' => 'Electricity bill recorded twice', ...$overrides];
}

function requestReversal(object $test, JournalEntry $original, ?User $requester = null, array $overrides = []): JournalEntry
{
    $test->actingAs($requester ?? $test->accountant)->post(route('journals.reverse', $original), reversalForm($overrides))->assertRedirect()->assertSessionHasNoErrors();

    return JournalEntry::query()->where('reversal_of_id', $original->id)->sole();
}

// --- Request -----------------------------------------------------------------

test('requesting a reversal posts nothing and the original stays posted', function () {
    $original = postedManualJournal($this);
    $this->actingAs($this->accountant)->get(route('journals.show', $original))->assertOk()
        ->assertSee('Request Reversal')->assertDontSee('Reverse journal')->assertDontSee('>Reverse</button>', false);

    $request = requestReversal($this, $original);

    expect($original->fresh()->status)->toBe(JournalStatus::Posted)
        ->and($request->status)->toBe(JournalStatus::Submitted)
        ->and($request->voucher_number)->toBeNull()
        ->and($request->posted_by)->toBeNull()->and($request->approved_by)->toBeNull()
        ->and([$request->created_by, $request->submitted_by])->toBe([$this->accountant->id, $this->accountant->id])
        ->and(AuditLog::query()->where('event', 'journal.reversal_requested')->where('auditable_id', $original->id)->value('properties'))
        ->toMatchArray(['reversal_journal_id' => $request->id, 'amount' => '10000.0000', 'approval_tier' => 'standard', 'reason' => 'Electricity bill recorded twice', 'branch_id' => $this->branch->id, 'transaction_date' => '2026-02-05']);

    // The generic journal actions cannot move a reversal request.
    $this->actingAs($this->admin)->post(route('journals.post', $request))->assertSessionHasErrors('status');
    $this->post(route('journals.approve', $request))->assertSessionHasErrors('status');
    $this->post(route('journals.reject', $request), ['reason' => 'Generic return attempt'])->assertSessionHasErrors('status');
    expect($request->fresh()->status)->toBe(JournalStatus::Submitted)->and($original->fresh()->status)->toBe(JournalStatus::Posted);

    $this->actingAs($this->accountant)->get(route('journals.show', $original))->assertOk()->assertSee('Reversal requested')->assertDontSee('Request Reversal');
});

test('reason length date and period rules are enforced at request approval and posting', function () {
    $original = postedManualJournal($this);
    $this->actingAs($this->accountant);

    $this->post(route('journals.reverse', $original), reversalForm(['reason' => 'too short']))->assertSessionHasErrors('reason');
    $this->post(route('journals.reverse', $original), reversalForm(['accounting_period_id' => $this->jan->id, 'transaction_date' => '2026-01-19']))->assertSessionHasErrors('transaction_date');
    $this->post(route('journals.reverse', $original), reversalForm(['transaction_date' => '2026-03-05']))->assertSessionHasErrors('accounting_period_id');
    $this->feb->update(['status' => AccountingPeriodStatus::Closed]);
    $this->post(route('journals.reverse', $original), reversalForm())->assertSessionHasErrors('accounting_period_id');
    expect(JournalEntry::query()->where('reversal_of_id', $original->id)->exists())->toBeFalse();

    // With the original period closed, a later open period is accepted.
    $this->feb->update(['status' => AccountingPeriodStatus::Open]);
    $this->jan->update(['status' => AccountingPeriodStatus::Closed]);
    $request = requestReversal($this, $original);

    // A period closed after the request blocks approval and posting until it reopens.
    $this->feb->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => $this->reversals->approve($request, $this->approver))->toThrow(ValidationException::class);
    $this->feb->update(['status' => AccountingPeriodStatus::Open]);
    $this->reversals->approve($request, $this->approver);
    $this->feb->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => $this->reversals->post($request->fresh(), $this->poster))->toThrow(ValidationException::class)
        ->and($original->fresh()->status)->toBe(JournalStatus::Posted);
});

// --- Separation of duties ------------------------------------------------------

test('requester approver and poster must be three different users and the preparer cannot approve', function () {
    $original = postedManualJournal($this, '10000.0000', $this->admin);
    $request = requestReversal($this, $original, $this->director);

    expect(fn () => $this->reversals->approve($request, $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $this->reversals->approve($request, $this->admin))->toThrow(ValidationException::class);
    $this->actingAs($this->accountant)->post(route('journals.reversal.approve', $request))->assertForbidden();

    $this->actingAs($this->approver)->post(route('journals.reversal.approve', $request))->assertRedirect()->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(JournalStatus::Approved)->and($original->fresh()->status)->toBe(JournalStatus::Posted);

    $this->actingAs($this->approver)->post(route('journals.reversal.post', $request))->assertForbidden();
    expect(fn () => $this->reversals->post($request->fresh(), $this->director))->toThrow(ValidationException::class);

    $this->actingAs($this->poster)->post(route('journals.reversal.post', $request))->assertRedirect()->assertSessionHasNoErrors();
    $posted = $request->fresh();
    expect($posted->status)->toBe(JournalStatus::Posted)->and($posted->voucher_number)->toStartWith('JV-')
        ->and([$posted->created_by, $posted->approved_by, $posted->posted_by])->toBe([$this->director->id, $this->approver->id, $this->poster->id])
        ->and($original->fresh()->status)->toBe(JournalStatus::Reversed)
        ->and($original->fresh()->reversed_by)->toBe($this->poster->id);
});

test('an accountant can neither reverse alone nor approve or post their own request', function () {
    $original = postedManualJournal($this);
    $request = requestReversal($this, $original);

    $this->actingAs($this->accountant)->post(route('journals.reversal.approve', $request))->assertForbidden();
    expect(fn () => $this->reversals->post($request->fresh(), $this->accountant))->toThrow(ValidationException::class);
    $this->reversals->approve($request, $this->approver);
    $this->actingAs($this->accountant)->post(route('journals.reversal.post', $request))->assertSessionHasErrors('approval');

    expect($original->fresh()->status)->toBe(JournalStatus::Posted)->and($request->fresh()->status)->toBe(JournalStatus::Approved);
});

// --- Threshold -----------------------------------------------------------------

test('reversal approval at the threshold needs an approver and above it a director of the branch', function () {
    $atLimit = postedManualJournal($this, '150000.0000');
    $request = requestReversal($this, $atLimit);
    $this->reversals->approve($request, $this->approver);
    expect($request->fresh()->status)->toBe(JournalStatus::Approved);

    $above = postedManualJournal($this, '150000.0100');
    expect($above->total_debit)->toBe('150000.0100')->and($above->total_credit)->toBe('150000.0100');
    $request = requestReversal($this, $above);
    expect(AuditLog::query()->where('event', 'journal.reversal_requested')->where('auditable_id', $above->id)->value('properties')['approval_tier'])->toBe('director');

    foreach ([$this->approver, $this->admin, $this->outsideDirector] as $ineligible) {
        expect(fn () => $this->reversals->approve($request->fresh(), $ineligible))->toThrow(HttpException::class);
    }
    $this->admin->givePermissionTo('journals.approve');
    $this->actingAs($this->admin)->post(route('journals.reversal.approve', $request))->assertForbidden();
    $this->actingAs($this->approver)->get(route('journals.show', $request))->assertOk()->assertDontSee('Approve Reversal')->assertSee('approval needs a Director');

    $this->actingAs($this->director)->get(route('journals.show', $request))->assertOk()->assertSee('Approve Reversal');
    $this->reversals->approve($request->fresh(), $this->director);
    expect($request->fresh()->approved_by)->toBe($this->director->id)
        ->and(AuditLog::query()->where('event', 'journal.reversal_approved')->where('auditable_id', $above->id)->value('properties'))
        ->toMatchArray(['authority_basis' => 'director_role', 'approval_tier' => 'director', 'amount' => '150000.0100']);
});

test('a director who prepared the original journal cannot approve its reversal', function () {
    $this->director->givePermissionTo(['journals.create', 'journals.submit']);
    $workflow = app(JournalWorkflowService::class);
    $other = reversalUser(SystemRole::Director, $this->branch);
    $this->actingAs($this->director);
    $journal = $workflow->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20', 'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->jan->id, 'branch_id' => $this->branch->id, 'narration' => 'Director prepared journal',
        'lines' => [['account_id' => $this->expense->id, 'debit' => '200000.0000', 'credit' => '0'], ['account_id' => $this->income->id, 'debit' => '0', 'credit' => '200000.0000']]], $this->director);
    $workflow->submit($journal, $this->director);
    expect(fn () => $workflow->approve($journal->fresh(), $this->director))->toThrow(ValidationException::class);
    $workflow->approve($journal->fresh(), $other);
    $original = app(JournalPostingService::class)->post($journal->fresh(), $this->admin);

    $request = requestReversal($this, $original);
    expect(fn () => $this->reversals->approve($request, $this->director))->toThrow(ValidationException::class);
    $this->reversals->approve($request, $other);
    expect($request->fresh()->status)->toBe(JournalStatus::Approved);
});

test('manual journal approval follows the same director threshold', function () {
    $workflow = app(JournalWorkflowService::class);
    $make = function (string $amount) use ($workflow): JournalEntry {
        $this->actingAs($this->accountant);
        $journal = $workflow->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20', 'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->jan->id, 'branch_id' => $this->branch->id, 'narration' => 'Threshold journal '.$amount,
            'lines' => [['account_id' => $this->expense->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $this->income->id, 'debit' => '0', 'credit' => $amount]]], $this->accountant);

        return $workflow->submit($journal, $this->accountant)->fresh();
    };

    $atLimit = $make('150000.0000');
    expect($workflow->approve($atLimit, $this->approver)->status)->toBe(JournalStatus::Approved);

    $above = $make('150000.0100');
    foreach ([$this->approver, $this->admin, $this->outsideDirector] as $ineligible) {
        expect($workflow->canApprove($above, $ineligible))->toBeFalse()
            ->and(fn () => $workflow->approve($above->fresh(), $ineligible))->toThrow(HttpException::class);
    }
    $this->actingAs($this->approver)->post(route('journals.approve', $above))->assertForbidden();
    $this->get(route('journals.show', $above))->assertOk()->assertDontSee('>Approve</button>', false)->assertSee('only a Director with authority for this branch');
    $this->actingAs($this->director)->get(route('journals.show', $above))->assertOk()->assertSee('>Approve</button>', false);

    expect($workflow->approve($above->fresh(), $this->director)->status)->toBe(JournalStatus::Approved)
        ->and(AuditLog::query()->where('event', 'journal.approved')->where('auditable_id', $above->id)->value('properties'))->toMatchArray(['amount' => '150000.0100', 'approval_tier' => 'director', 'authority_basis' => 'director_role']);
});

test('posting rechecks that the approver still holds the authority used', function () {
    $original = postedManualJournal($this, '200000.0000');
    $request = requestReversal($this, $original);
    $this->reversals->approve($request, $this->director);
    $this->director->removeRole(SystemRole::Director->value);

    expect(fn () => $this->reversals->post($request->fresh(), $this->poster))->toThrow(ValidationException::class)
        ->and($original->fresh()->status)->toBe(JournalStatus::Posted)
        ->and($request->fresh()->status)->toBe(JournalStatus::Approved);
});

// --- Protected sources -----------------------------------------------------------

test('opening balance closing source document and reversal journals cannot be reversed from the journal screen', function () {
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $asset = Account::factory()->create(['account_type' => 'asset']);
    $batch = OpeningBalanceBatch::factory()->create(['branch_id' => $this->branch, 'fiscal_year_id' => $this->year, 'opening_date' => '2026-01-01', 'status' => 'approved', 'total_debit' => '890000.0000', 'total_credit' => '890000.0000', 'created_by' => $this->accountant, 'approved_by' => $this->approver, 'approved_at' => now()]);
    $batch->lines()->create(['account_id' => $asset->id, 'debit' => '890000.0000', 'credit' => '0.0000']);
    $batch->lines()->create(['account_id' => $equity->id, 'debit' => '0.0000', 'credit' => '890000.0000']);
    $opening = JournalEntry::query()->findOrFail(app(OpeningBalancePostingService::class)->post($batch, $this->admin)->journal_entry_id);

    $system = fn (string $source) => JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20', 'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->jan->id,
        'branch_id' => $this->branch->id, 'narration' => 'System journal', 'source_type' => $source, 'source_key' => $source.':probe', 'status' => JournalStatus::Posted, 'voucher_number' => 'SYS-'.$source,
        'total_debit' => '100.0000', 'total_credit' => '100.0000', 'created_by' => $this->admin->id,
    ]);
    $original = postedManualJournal($this);
    $request = requestReversal($this, $original);
    $this->reversals->approve($request, $this->approver);
    $reversalJournal = $this->reversals->post($request->fresh(), $this->poster);

    foreach (['opening balance' => $opening, 'year-end closing' => $system(YearEndClosingService::SOURCE_TYPE), 'payment' => $system('cash_payment'), 'payroll' => $system('payroll_run'), 'reversal' => $reversalJournal] as $case => $journal) {
        $this->actingAs($this->accountant)->post(route('journals.reverse', $journal), reversalForm())->assertSessionHasErrors('status');
        $this->get(route('journals.show', $journal))->assertOk()->assertDontSee('Request Reversal');
        expect($journal->fresh()->status)->toBe(JournalStatus::Posted, $case)
            ->and(JournalEntry::query()->where('reversal_of_id', $journal->id)->exists())->toBeFalse();
    }
    expect($batch->fresh()->status)->toBe('posted');
});

// --- Forged requests and branch isolation ---------------------------------------

test('forged requests without permission or branch access are refused at every step', function () {
    $original = postedManualJournal($this);
    $cashier = reversalUser(SystemRole::Cashier, $this->branch);
    $auditor = reversalUser(SystemRole::Auditor, $this->branch);
    $outsider = reversalUser(SystemRole::Accountant, $this->otherBranch);

    foreach ([$cashier, $auditor, $this->approver, $outsider] as $user) {
        $this->actingAs($user)->post(route('journals.reverse', $original), reversalForm())->assertForbidden();
    }
    $request = requestReversal($this, $original);
    $this->actingAs($this->outsideDirector)->post(route('journals.reversal.approve', $request))->assertForbidden();
    $this->actingAs($auditor)->post(route('journals.reversal.approve', $request))->assertForbidden();
    $this->actingAs($cashier)->post(route('journals.reversal.post', $request))->assertForbidden();
    $this->actingAs($outsider)->post(route('journals.reversal.return', $request), ['reason' => 'Outside branch return attempt'])->assertForbidden();
    $this->actingAs($this->poster)->post(route('journals.reversal.cancel', $request), ['reason' => 'Not my request to cancel'])->assertForbidden();

    expect($request->fresh()->status)->toBe(JournalStatus::Submitted)->and($original->fresh()->status)->toBe(JournalStatus::Posted);
});

// --- Duplicates, returns and cancellations ---------------------------------------

test('duplicate requests are blocked and returned or cancelled requests leave no stale lock', function () {
    $original = postedManualJournal($this);
    $request = requestReversal($this, $original);

    $this->actingAs($this->poster)->post(route('journals.reverse', $original), reversalForm())->assertSessionHasErrors('status');
    expect(fn () => JournalEntry::query()->create([
        'voucher_type_id' => $original->voucher_type_id, 'transaction_date' => '2026-02-05', 'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->feb->id, 'branch_id' => $this->branch->id,
        'narration' => 'Concurrent duplicate', 'source_type' => JournalReversalService::SOURCE_TYPE, 'source_key' => 'concurrent:'.$original->id, 'status' => JournalStatus::Submitted, 'reversal_of_id' => $original->id, 'created_by' => $this->poster->id,
    ]))->toThrow(QueryException::class);

    // The requester cannot return their own request; an approver returns it with a reason.
    $this->actingAs($this->accountant)->post(route('journals.reversal.return', $request), ['reason' => 'Returning my own request'])->assertSessionHasErrors('approval');
    $this->actingAs($this->approver)->post(route('journals.reversal.return', $request), ['reason' => 'short'])->assertSessionHasErrors('reason');
    $this->post(route('journals.reversal.return', $request), ['reason' => 'Duplicate entry is actually valid'])->assertRedirect(route('journals.show', $original))->assertSessionHasNoErrors();
    expect(JournalEntry::query()->find($request->id))->toBeNull()
        ->and($original->fresh()->status)->toBe(JournalStatus::Posted)
        ->and(AuditLog::query()->where('event', 'journal.reversal_returned')->where('auditable_id', $original->id)->value('properties'))
        ->toMatchArray(['reversal_journal_id' => $request->id, 'reason' => 'Duplicate entry is actually valid', 'request_reason' => 'Electricity bill recorded twice', 'requested_by' => $this->accountant->id, 'status' => 'submitted']);

    // An approved request can be cancelled by its requester, and a new request is then possible.
    $second = requestReversal($this, $original);
    $this->reversals->approve($second, $this->approver);
    $this->actingAs($this->accountant)->post(route('journals.reversal.cancel', $second), ['reason' => 'Requested against the wrong voucher'])->assertRedirect()->assertSessionHasNoErrors();
    expect(JournalEntry::query()->find($second->id))->toBeNull()
        ->and(AuditLog::query()->where('event', 'journal.reversal_cancelled')->where('auditable_id', $original->id)->value('properties')['status'])->toBe('approved');

    $third = requestReversal($this, $original);
    expect($third->status)->toBe(JournalStatus::Submitted);

    // Once posted, no further request is possible.
    $this->reversals->approve($third, $this->approver);
    $this->reversals->post($third->fresh(), $this->poster);
    $this->actingAs($this->poster)->post(route('journals.reverse', $original), reversalForm())->assertSessionHasErrors('status');
    expect(JournalEntry::query()->where('reversal_of_id', $original->id)->count())->toBe(1);
});

// --- Financial effect ---------------------------------------------------------------

test('the posted reversal negates the original line by line and the trial balance stays balanced', function () {
    $original = postedManualJournal($this, '25000.5000');
    $request = requestReversal($this, $original);
    $this->reversals->approve($request, $this->approver);
    $this->actingAs($this->poster)->get(route('journals.show', $request))->assertOk()->assertSee('Post Reversal')->assertDontSee('Approve Reversal');
    $this->actingAs($this->approver)->get(route('journals.show', $request))->assertOk()->assertDontSee('Post Reversal');
    $posted = $this->reversals->post($request->fresh(), $this->poster);

    $originalLines = $original->lines()->orderBy('line_number')->get();
    $reversalLines = $posted->lines()->orderBy('line_number')->get();
    foreach ($originalLines as $index => $line) {
        expect($reversalLines[$index]->only(['line_number', 'account_id', 'description', 'service_id', 'fund_id']))->toBe($line->only(['line_number', 'account_id', 'description', 'service_id', 'fund_id']))
            ->and($reversalLines[$index]->debit)->toBe($line->credit)
            ->and($reversalLines[$index]->credit)->toBe($line->debit);
    }
    $trial = app(TrialBalanceService::class)->report($this->year->id, '2026-02-28', [$this->branch->id]);
    $log = AuditLog::query()->where('event', 'journal.reversed')->where('auditable_id', $original->id)->sole();

    expect($trial['balanced'])->toBeTrue()->and($trial['total_debit'])->toBe('0.0000')
        ->and($original->fresh()->status)->toBe(JournalStatus::Reversed)
        ->and($posted->reversal_of_id)->toBe($original->id)
        ->and($log->properties)->toMatchArray(['reversal_journal_id' => $posted->id, 'requested_by' => $this->accountant->id, 'approved_by' => $this->approver->id, 'posted_by' => $this->poster->id, 'voucher_number' => $posted->voucher_number, 'amount' => '25000.5000'])
        ->and(json_encode(AuditLog::query()->where('auditable_id', $original->id)->pluck('properties')))->not->toContain('password')->not->toContain('token');
    $this->actingAs($this->accountant)->get(route('journals.show', $original))->assertOk()->assertSee('This posted voucher has been reversed')->assertSee($posted->voucher_number);
});
