<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FeeSettlementMapping;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\FeePosting\FeePostingEligibility;
use App\Services\FeePosting\FeePostingService;
use App\Services\FeePosting\FeeSettlementMappingService;
use App\Services\TrialBalanceService;
use Database\Factories\FrcEnrollmentFactory;
use Database\Factories\FrcPaymentFactory;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->poster = User::factory()->create();
    $this->poster->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->september = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'Sep 2026', 'period_number' => 3, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => AccountingPeriodStatus::Open]);
    $this->october = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'Oct 2026', 'period_number' => 4, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['name' => 'Main Branch']);
    $this->otherBranch = Branch::factory()->create(['name' => 'Second Branch']);

    $this->incomeLedger = Account::factory()->create(['code' => '411001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->generalIncome = Account::factory()->create(['code' => '411099', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->receivable = Account::factory()->create(['code' => '121001']);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::FeeIncomeGeneral->value, 'account_id' => $this->generalIncome->id]);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::AccountsReceivable->value, 'account_id' => $this->receivable->id]);

    $this->service = FrcService::factory()->create(['name' => 'Speech Therapy', 'revenue_account_id' => $this->incomeLedger->id]);
    $this->otherService = FrcService::factory()->create(['name' => 'Occupational Therapy']);

    $this->cashLedger = Account::factory()->create(['code' => '111001']);
    $this->bankLedger = Account::factory()->create(['code' => '112001']);
    $this->otherCashLedger = Account::factory()->create(['code' => '111002']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch->id, 'account_id' => $this->cashLedger->id]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch->id, 'account_id' => $this->bankLedger->id]);
    $otherCash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->otherBranch->id, 'account_id' => $this->otherCashLedger->id]);
    FeeSettlementMapping::query()->create(['branch_id' => $this->branch->id, 'payment_method' => 'cash', 'financial_account_id' => $this->cash->id]);
    FeeSettlementMapping::query()->create(['branch_id' => $this->branch->id, 'payment_method' => 'easypaisa', 'financial_account_id' => $this->bank->id]);
    FeeSettlementMapping::query()->create(['branch_id' => $this->otherBranch->id, 'payment_method' => 'cash', 'financial_account_id' => $otherCash->id]);

    feepostSetting('fees.accounting_policy_approved', '1');
    $this->actingAs($this->poster);
});

function feepostSetting(string $key, string $value): void
{
    AppSetting::query()->where('key', $key)->update(['value' => $value]);
}

function feepostEnrollment(object $test, array $overrides = []): FrcEnrollment
{
    return FrcEnrollmentFactory::new()->create(['branch_id' => $test->branch->id, 'service_id' => $test->service->id, 'final_total' => '10000.0000', 'source_approved_at' => '2026-09-01 10:00:00', 'source_updated_at' => '2026-09-01 10:00:00', ...$overrides]);
}

function feepostPayment(object $test, array $overrides = [], ?FrcEnrollment $enrollment = null): FrcPayment
{
    $enrollment ??= feepostEnrollment($test, array_intersect_key($overrides, ['branch_id' => 1, 'service_id' => 1]));

    return FrcPaymentFactory::new()->create([
        'frc_enrollment_id' => $enrollment->frc_enrollment_id, 'frc_enrollment_mirror_id' => $enrollment->id,
        'branch_id' => $enrollment->branch_id, 'service_id' => $enrollment->service_id, 'enrollment_status' => $enrollment->source_status,
        'amount' => '3000.0000', 'payment_method' => 'cash', 'source_status' => 'paid', 'payment_date' => '2026-09-14', ...$overrides,
    ]);
}

function feepostService(): FeePostingService
{
    return app(FeePostingService::class);
}

/** Net credit (credit − debit) on a ledger over posted and reversed journals, optionally by service or branch. */
function feepostNetCredit(Account $account, ?int $serviceId = null, ?int $branchId = null): string
{
    $lines = JournalLine::query()->where('account_id', $account->id)
        ->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))
        ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])->when($branchId, fn ($q) => $q->where('branch_id', $branchId)))
        ->get();

    return number_format($lines->sum(fn (JournalLine $line) => (float) $line->credit - (float) $line->debit), 2, '.', '');
}

test('cash basis posts the exact balanced FR journal and the posting link', function () {
    $payment = feepostPayment($this, ['receipt_number' => 'FRC-000045']);
    $posting = feepostService()->postPayment($payment, $this->poster);

    $journal = JournalEntry::query()->with(['lines', 'voucherType'])->findOrFail($posting->journal_entry_id);
    expect($journal->status)->toBe(JournalStatus::Posted)
        ->and($journal->voucherType->code)->toBe('FR')
        ->and($journal->transaction_date->toDateString())->toBe('2026-09-14')
        ->and($journal->branch_id)->toBe($this->branch->id)
        ->and($journal->accounting_period_id)->toBe($this->september->id)
        ->and($journal->source_type)->toBe('frc_payment')
        ->and($journal->source_key)->toBe('frc_payment:'.$payment->frc_payment_id)
        ->and($journal->source_id)->toBe((string) $payment->id)
        ->and($journal->reference_number)->toBe('FRC-000045')
        ->and($journal->total_debit)->toBe('3000.0000')
        ->and($journal->total_credit)->toBe('3000.0000')
        ->and($journal->lines)->toHaveCount(2);
    [$debit, $credit] = [$journal->lines[0], $journal->lines[1]];
    expect($debit->account_id)->toBe($this->cashLedger->id)->and($debit->debit)->toBe('3000.0000')->and($debit->credit)->toBe('0.0000')->and($debit->service_id)->toBe($this->service->id)
        ->and($credit->account_id)->toBe($this->incomeLedger->id)->and($credit->credit)->toBe('3000.0000')->and($credit->debit)->toBe('0.0000')->and($credit->service_id)->toBe($this->service->id);

    expect($posting->kind)->toBe('payment')->and($posting->basis)->toBe('cash')->and($posting->status)->toBe('posted')
        ->and($posting->amount)->toBe('3000.0000')->and($posting->source_key)->toBe($journal->source_key)
        ->and($payment->fresh()->posting_status)->toBe('posted')
        ->and(AuditLog::query()->where('event', 'fee_posting.payment_posted')->where('auditable_id', $posting->id)->exists())->toBeTrue();
});

test('a non-cash method settles into its own mapped account', function () {
    $payment = feepostPayment($this, ['payment_method' => 'easypaisa']);
    $posting = feepostService()->postPayment($payment, $this->poster);
    expect($posting->journalEntry->lines[0]->account_id)->toBe($this->bankLedger->id);

    $card = feepostPayment($this, ['payment_method' => 'card']);
    expect(app(FeePostingEligibility::class)->forPayment($card, $this->poster)->has('settlement_mapping'))->toBeTrue();
});

test('posting the same payment again is idempotent', function () {
    $payment = feepostPayment($this);
    feepostService()->postPayment($payment, $this->poster);

    expect(fn () => feepostService()->postPayment($payment->fresh(), $this->poster))->toThrow(ValidationException::class, 'Already posted');
    $results = feepostService()->postBatch('payment', [$payment->id], $this->poster);
    expect($results[$payment->id]['status'])->toBe('skipped')->and($results[$payment->id]['message'])->toContain('Already posted')
        ->and(JournalEntry::query()->count())->toBe(1)->and(FrcFeePosting::query()->count())->toBe(1);
});

test('a concurrent post that loses the unique source key race reports already posted and creates one journal', function () {
    $payment = feepostPayment($this);
    // The other user's journal reached the database first with the same source key.
    JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'FR')->value('id'), 'transaction_date' => '2026-09-14',
        'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->september->id, 'branch_id' => $this->branch->id,
        'narration' => 'Concurrent winner', 'source_system' => 'frc', 'source_type' => 'frc_payment', 'source_id' => (string) $payment->id,
        'source_key' => 'frc_payment:'.$payment->frc_payment_id, 'status' => JournalStatus::Approved, 'created_by' => $this->poster->id,
    ]);

    expect(fn () => feepostService()->postPayment($payment, $this->poster))->toThrow(ValidationException::class, 'Already posted');
    expect(JournalEntry::query()->count())->toBe(1)
        ->and(FrcFeePosting::query()->count())->toBe(0)
        ->and($payment->fresh()->posting_status)->toBe('unposted');
});

test('payments that are not paid or deleted at source are never posted', function (array $overrides, string $reason) {
    $payment = feepostPayment($this, $overrides);
    $plan = app(FeePostingEligibility::class)->forPayment($payment, $this->poster);

    expect($plan->eligible())->toBeFalse()->and($plan->has($reason))->toBeTrue();
    expect(fn () => feepostService()->postPayment($payment, $this->poster))->toThrow(ValidationException::class);
    expect(JournalEntry::query()->count())->toBe(0);
})->with([
    'pending verification' => [['source_status' => 'pending_verification'], 'source_not_paid'],
    'rejected' => [['source_status' => 'rejected'], 'source_not_paid'],
    'cancelled' => [['source_status' => 'cancelled'], 'source_not_paid'],
    'refunded' => [['source_status' => 'refunded'], 'source_not_paid'],
    'deleted at source' => [['source_deleted_at' => '2026-09-20 10:00:00'], 'deleted_at_source'],
]);

test('payments of enrollments that are not approved, active or completed are never posted', function (string $status) {
    $payment = feepostPayment($this, [], feepostEnrollment($this, ['source_status' => $status]));
    $plan = app(FeePostingEligibility::class)->forPayment($payment, $this->poster);
    expect($plan->has('enrollment_not_approved'))->toBeTrue();

    $orphan = feepostPayment($this);
    $orphan->update(['frc_enrollment_mirror_id' => null]);
    expect(app(FeePostingEligibility::class)->forPayment($orphan->fresh(), $this->poster)->has('enrollment_missing'))->toBeTrue();
    expect(JournalEntry::query()->count())->toBe(0);
})->with(['pending', 'rejected', 'cancelled']);

test('missing configuration blocks posting with the precise reason', function (Closure $breakIt, string $reason) {
    $payment = feepostPayment($this);
    $breakIt($this, $payment);
    $plan = app(FeePostingEligibility::class)->forPayment($payment->fresh(), $this->poster);

    expect($plan->has($reason))->toBeTrue();
    $results = feepostService()->postBatch('payment', [$payment->id], $this->poster);
    expect($results[$payment->id]['status'])->toBe('skipped')
        ->and($results[$payment->id]['message'])->toContain($plan->reasons[$reason])
        ->and(JournalEntry::query()->count())->toBe(0);
})->with([
    'missing settlement mapping' => [fn ($test) => FeeSettlementMapping::query()->where('branch_id', $test->branch->id)->where('payment_method', 'cash')->delete(), 'settlement_mapping'],
    'settlement account no longer valid' => [fn ($test) => $test->cash->update(['is_active' => false]), 'settlement_mapping'],
    'missing fee income mapping' => [function ($test, $payment) {
        $payment->update(['service_id' => $test->otherService->id]);
        AccountMapping::query()->where('mapping_key', AccountMappingKey::FeeIncomeGeneral->value)->delete();
    }, 'fee_income_mapping'],
    'unmapped branch' => [fn ($test, $payment) => $payment->update(['branch_id' => null]), 'branch_unmapped'],
    'unmapped service' => [fn ($test, $payment) => $payment->update(['service_id' => null]), 'service_unmapped'],
    'closed period' => [fn ($test) => $test->september->update(['status' => AccountingPeriodStatus::Closed]), 'period_closed'],
    'no period for the date' => [fn ($test, $payment) => $payment->update(['payment_date' => '2027-08-01']), 'period_closed'],
    'unapproved policy' => [fn () => feepostSetting('fees.accounting_policy_approved', '0'), 'policy_not_approved'],
    'flagged for review' => [fn ($test, $payment) => $payment->update(['posting_status' => 'needs_review']), 'needs_review'],
]);

test('a synchronised payment of a service without its own fee income ledger stays unposted', function () {
    $quran = FrcService::factory()->create(['name' => 'Quran Teaching']);
    $payment = feepostPayment($this, ['service_id' => $quran->id, 'amount' => '2500.0000']);

    $plan = app(FeePostingEligibility::class)->forPayment($payment->fresh(), $this->poster);
    expect($plan->has('fee_income_mapping'))->toBeTrue()
        ->and($plan->reasons['fee_income_mapping'])->toContain('Quran Teaching has no fee income ledger')->toContain('General Fee Income is not used');

    $results = feepostService()->postBatch('payment', [$payment->id], $this->poster);
    expect($results[$payment->id]['status'])->toBe('skipped')
        ->and($payment->fresh()->posting_status)->toBe('unposted')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->where('account_id', $this->generalIncome->id)->exists())->toBeFalse();

    $this->get(route('fee-posting.payments.index'))->assertOk()->assertSee('Quran Teaching has no fee income ledger');
});

test('school readiness posts to its own mapped ledger with branch and service attribution, once', function () {
    $dayProgramme = Account::factory()->create(['code' => '42105', 'name' => 'Day Programme Fees', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $schoolReadiness = FrcService::factory()->create(['name' => 'School Readiness Program', 'revenue_account_id' => $dayProgramme->id]);
    $payment = feepostPayment($this, ['service_id' => $schoolReadiness->id, 'amount' => '5000.0000']);

    $journal = feepostService()->postPayment($payment, $this->poster)->journalEntry;
    expect($journal->branch_id)->toBe($this->branch->id)
        ->and($journal->lines[1]->account_id)->toBe($dayProgramme->id)
        ->and($journal->lines[1]->service_id)->toBe($schoolReadiness->id)
        ->and($journal->lines[1]->credit)->toBe('5000.0000')
        ->and(feepostNetCredit($dayProgramme, $schoolReadiness->id))->toBe('5000.00');

    $again = feepostService()->postBatch('payment', [$payment->id], $this->poster);
    expect($again[$payment->id]['status'])->toBe('skipped')
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(JournalLine::query()->where('account_id', $this->generalIncome->id)->exists())->toBeFalse();
});

test('a service fee income ledger that is not an active postable income account blocks posting', function (Closure $ledger) {
    $service = FrcService::factory()->create(['name' => 'Group Therapy', 'revenue_account_id' => $ledger()->id]);
    $payment = feepostPayment($this, ['service_id' => $service->id]);

    $plan = app(FeePostingEligibility::class)->forPayment($payment->fresh(), $this->poster);
    expect($plan->reasons['fee_income_mapping'] ?? '')->toContain('Group Therapy is not an active, postable income account');
    expect(feepostService()->postBatch('payment', [$payment->id], $this->poster)[$payment->id]['status'])->toBe('skipped')
        ->and(JournalEntry::query()->count())->toBe(0);
})->with([
    'an expense ledger' => [fn () => Account::factory()->create(['code' => '53101', 'account_type' => 'expense', 'normal_balance' => 'debit', 'financial_statement' => 'income_expenditure'])],
    'an inactive income ledger' => [fn () => Account::factory()->create(['code' => '42199', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure', 'is_active' => false])],
    'a non-postable income heading' => [fn () => Account::factory()->create(['code' => '421', 'level' => 'sub_group', 'is_postable' => false, 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure'])],
]);

test('accrual basis recognises income once from the enrollment and settles receivable from payments', function () {
    feepostSetting('fees.accounting_basis', 'accrual');
    $enrollment = feepostEnrollment($this, ['final_total' => '10000.0000']);
    $first = feepostPayment($this, ['amount' => '3000.0000'], $enrollment);

    // Payment before recognition is refused.
    expect(app(FeePostingEligibility::class)->forPayment($first, $this->poster)->has('enrollment_not_recognised'))->toBeTrue();
    expect(fn () => feepostService()->postPayment($first, $this->poster))->toThrow(ValidationException::class);

    $recognition = feepostService()->recogniseEnrollment($enrollment, $this->poster);
    $journal = $recognition->journalEntry->load(['lines', 'voucherType']);
    expect($journal->voucherType->code)->toBe('JV')
        ->and($journal->source_key)->toBe('frc_enrollment:'.$enrollment->frc_enrollment_id.':v1')
        ->and($journal->source_type)->toBe('frc_enrollment')
        ->and($journal->lines[0]->account_id)->toBe($this->receivable->id)->and($journal->lines[0]->debit)->toBe('10000.0000')
        ->and($journal->lines[1]->account_id)->toBe($this->incomeLedger->id)->and($journal->lines[1]->credit)->toBe('10000.0000')
        ->and($recognition->basis)->toBe('accrual')->and($recognition->kind)->toBe('enrollment');

    $second = feepostPayment($this, ['amount' => '2500.0000', 'payment_method' => 'easypaisa'], $enrollment);
    $results = feepostService()->postBatch('payment', [$first->id, $second->id], $this->poster);
    expect(collect($results)->pluck('status')->all())->toBe(['posted', 'posted']);

    $paymentJournal = FrcFeePosting::query()->where('frc_payment_id', $first->id)->first()->journalEntry->load('lines', 'voucherType');
    expect($paymentJournal->voucherType->code)->toBe('FR')
        ->and($paymentJournal->lines[0]->account_id)->toBe($this->cashLedger->id)->and($paymentJournal->lines[0]->debit)->toBe('3000.0000')
        ->and($paymentJournal->lines[1]->account_id)->toBe($this->receivable->id)->and($paymentJournal->lines[1]->credit)->toBe('3000.0000');

    // Income = final_total once; receivable = final_total − paid; cash and bank hold what was received.
    expect(feepostNetCredit($this->incomeLedger))->toBe('10000.00')
        ->and(feepostNetCredit($this->receivable))->toBe('-4500.00')
        ->and(feepostNetCredit($this->cashLedger))->toBe('-3000.00')
        ->and(feepostNetCredit($this->bankLedger))->toBe('-2500.00')
        ->and(FrcFeePosting::query()->pluck('basis')->unique()->all())->toBe(['accrual']);

    // Recognising again with an unchanged total posts nothing.
    expect(fn () => feepostService()->recogniseEnrollment($enrollment->fresh(), $this->poster))->toThrow(ValidationException::class);
    expect(JournalEntry::query()->count())->toBe(3);
});

test('a changed enrollment total posts an adjustment for the exact difference only', function () {
    feepostSetting('fees.accounting_basis', 'accrual');
    $enrollment = feepostEnrollment($this, ['final_total' => '10000.0000']);
    feepostService()->recogniseEnrollment($enrollment, $this->poster);

    $enrollment->update(['final_total' => '12000.0000', 'source_updated_at' => '2026-10-05 09:00:00']);
    $up = feepostService()->recogniseEnrollment($enrollment->fresh(), $this->poster);
    expect($up->source_key)->toBe('frc_enrollment:'.$enrollment->frc_enrollment_id.':v2')
        ->and($up->amount)->toBe('2000.0000')
        ->and($up->journalEntry->transaction_date->toDateString())->toBe('2026-10-05')
        ->and($up->journalEntry->lines[0]->account_id)->toBe($this->receivable->id)
        ->and($up->journalEntry->lines[0]->debit)->toBe('2000.0000');

    $enrollment->update(['final_total' => '9000.0000']);
    $down = feepostService()->recogniseEnrollment($enrollment->fresh(), $this->poster);
    expect($down->source_key)->toBe('frc_enrollment:'.$enrollment->frc_enrollment_id.':v3')
        ->and($down->amount)->toBe('-3000.0000')
        ->and($down->journalEntry->lines[0]->account_id)->toBe($this->incomeLedger->id)
        ->and($down->journalEntry->lines[0]->debit)->toBe('3000.0000')
        ->and($down->journalEntry->lines[1]->account_id)->toBe($this->receivable->id)
        ->and($down->journalEntry->lines[1]->credit)->toBe('3000.0000');

    expect(feepostNetCredit($this->incomeLedger))->toBe('9000.00')
        ->and(app(FeePostingEligibility::class)->forEnrollment($enrollment->fresh(), $this->poster)->has('nothing_to_recognise'))->toBeTrue();
});

test('cash basis never recognises enrollments and credits income once per payment', function () {
    $enrollment = feepostEnrollment($this);
    expect(app(FeePostingEligibility::class)->forEnrollment($enrollment, $this->poster)->has('basis_not_accrual'))->toBeTrue();
    expect(fn () => feepostService()->recogniseEnrollment($enrollment, $this->poster))->toThrow(ValidationException::class);

    $payment = feepostPayment($this, ['amount' => '4000.0000'], $enrollment);
    feepostService()->postPayment($payment, $this->poster);
    feepostService()->postBatch('payment', [$payment->id], $this->poster);
    expect(feepostNetCredit($this->incomeLedger))->toBe('4000.00')->and(feepostNetCredit($this->receivable))->toBe('0.00');
});

test('posting under a different basis than existing postings is refused', function () {
    feepostService()->postPayment(feepostPayment($this), $this->poster);
    feepostSetting('fees.accounting_basis', 'accrual');

    $enrollment = feepostEnrollment($this);
    $plan = app(FeePostingEligibility::class)->forEnrollment($enrollment, $this->poster);
    expect($plan->has('basis_conflict'))->toBeTrue()->and($plan->reasons['basis_conflict'])->toContain('migration decision');
    expect(fn () => feepostService()->recogniseEnrollment($enrollment, $this->poster))->toThrow(ValidationException::class, 'cash basis');
    expect(app(FeePostingEligibility::class)->forPayment(feepostPayment($this), $this->poster)->has('basis_conflict'))->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('reversal posts an opposite journal keeps the original and updates statuses', function () {
    $payment = feepostPayment($this);
    $posting = feepostService()->postPayment($payment, $this->poster);
    $originalLines = $posting->journalEntry->lines->map(fn ($line) => [$line->account_id, $line->debit, $line->credit])->all();
    $payment->update(['source_status' => 'refunded', 'posting_status' => 'needs_review']);

    $this->post(route('fee-posting.postings.reverse', $posting), ['accounting_period_id' => $this->october->id, 'transaction_date' => '2026-10-02', 'reason' => 'Refunded at source'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $posting->refresh();
    $original = JournalEntry::query()->with('lines')->findOrFail($posting->journal_entry_id);
    $reversal = JournalEntry::query()->with('lines')->findOrFail($posting->reversal_journal_entry_id);
    expect($posting->status)->toBe('reversed')->and($posting->reversed_by)->toBe($this->poster->id)->and($posting->reversal_reason)->toBe('Refunded at source')
        ->and($original->status)->toBe(JournalStatus::Reversed)
        ->and($original->lines->map(fn ($line) => [$line->account_id, $line->debit, $line->credit])->all())->toBe($originalLines)
        ->and($reversal->status)->toBe(JournalStatus::Posted)->and($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->transaction_date->toDateString())->toBe('2026-10-02')
        ->and($reversal->lines[0]->account_id)->toBe($this->cashLedger->id)->and($reversal->lines[0]->credit)->toBe('3000.0000')
        ->and($reversal->lines[1]->account_id)->toBe($this->incomeLedger->id)->and($reversal->lines[1]->debit)->toBe('3000.0000')
        ->and($payment->fresh()->posting_status)->toBe('reversed')
        ->and(feepostNetCredit($this->incomeLedger))->toBe('0.00')
        ->and(AuditLog::query()->where('event', 'fee_posting.reversed')->exists())->toBeTrue();

    expect(fn () => feepostService()->reverse($posting, $this->october, '2026-10-02', 'Again please', $this->poster))->toThrow(ValidationException::class);
});

test('a reversed payment that is still paid at source returns to unposted and can be posted again once', function () {
    $payment = feepostPayment($this, ['amount' => '3000.0000']);
    $posting = feepostService()->postPayment($payment, $this->poster);
    $payment->update(['amount' => '3500.0000', 'posting_status' => 'needs_review']);
    feepostService()->reverse($posting, $this->october, '2026-10-02', 'Amount corrected at source', $this->poster);

    expect($payment->fresh()->posting_status)->toBe('unposted');
    $repost = feepostService()->postPayment($payment->fresh(), $this->poster);
    expect($repost->source_key)->toBe('frc_payment:'.$payment->frc_payment_id.':r1')
        ->and($repost->amount)->toBe('3500.0000')
        ->and(feepostNetCredit($this->incomeLedger))->toBe('3500.00');
});

test('posting and reversal require their permissions', function () {
    $payment = feepostPayment($this);
    $withoutJournalPost = User::factory()->create();
    $withoutJournalPost->givePermissionTo(['frc_integration.view', 'frc_integration.post', 'branches.view_all']);
    $withoutFeePost = User::factory()->create();
    $withoutFeePost->givePermissionTo(['frc_integration.view', 'journals.post', 'branches.view_all']);

    foreach ([$withoutJournalPost, $withoutFeePost] as $user) {
        $this->actingAs($user)->post(route('fee-posting.payments.post'), ['ids' => [$payment->id]])->assertForbidden();
        expect(fn () => feepostService()->postPayment($payment, $user))->toThrow(HttpException::class);
    }
    expect(JournalEntry::query()->count())->toBe(0);

    $this->actingAs($this->poster);
    $posting = feepostService()->postPayment($payment, $this->poster);
    $withoutReverse = User::factory()->create();
    $withoutReverse->givePermissionTo(['frc_integration.view', 'journals.reverse', 'branches.view_all']);
    $this->actingAs($withoutReverse)->post(route('fee-posting.postings.reverse', $posting), ['accounting_period_id' => $this->october->id, 'transaction_date' => '2026-10-02', 'reason' => 'Not allowed'])->assertForbidden();
    expect($posting->fresh()->status)->toBe('posted');
});

test('posting is limited to the actor branches', function () {
    $foreign = feepostPayment($this, ['branch_id' => $this->otherBranch->id]);
    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);
    $accountant->branches()->attach($this->branch);

    $this->actingAs($accountant)->post(route('fee-posting.payments.post'), ['ids' => [$foreign->id]])->assertForbidden();
    expect(fn () => feepostService()->postPayment($foreign, $accountant))->toThrow(HttpException::class);
    $this->get(route('fee-posting.payments.index'))->assertOk()->assertDontSee($foreign->receipt_number);

    $own = feepostPayment($this);
    $this->post(route('fee-posting.payments.post'), ['ids' => [$own->id]])->assertRedirect()->assertSessionHas('posting_results');
    expect($own->fresh()->posting_status)->toBe('posted')->and(JournalEntry::query()->count())->toBe(1);

    $this->actingAs($this->poster);
    $foreignPosting = feepostService()->postPayment($foreign, $this->poster);
    $this->actingAs($accountant)->post(route('fee-posting.postings.reverse', $foreignPosting), ['accounting_period_id' => $this->october->id, 'transaction_date' => '2026-10-02', 'reason' => 'Other branch'])->assertForbidden();
});

test('service-wise and branch-wise income are correct and the trial balance balances after a mixed batch', function () {
    $occupationalIncome = Account::factory()->create(['code' => '411002', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->otherService->update(['revenue_account_id' => $occupationalIncome->id]);
    $speechMain = feepostPayment($this, ['amount' => '3000.0000']);
    $speechMainBank = feepostPayment($this, ['amount' => '2000.0000', 'payment_method' => 'easypaisa']);
    $occupationalMain = feepostPayment($this, ['amount' => '1500.0000', 'service_id' => $this->otherService->id]);
    $speechOther = feepostPayment($this, ['amount' => '4000.0000', 'branch_id' => $this->otherBranch->id]);
    $pending = feepostPayment($this, ['amount' => '9999.0000', 'source_status' => 'pending_verification']);
    $unmappedMethod = feepostPayment($this, ['amount' => '700.0000', 'payment_method' => 'jazzcash']);

    $results = feepostService()->postBatch('payment', [$speechMain->id, $speechMainBank->id, $occupationalMain->id, $speechOther->id, $pending->id, $unmappedMethod->id], $this->poster);
    expect(collect($results)->where('status', 'posted'))->toHaveCount(4)
        ->and($results[$pending->id]['status'])->toBe('skipped')
        ->and($results[$unmappedMethod->id]['message'])->toContain('JazzCash');

    $speechIncome = (float) feepostNetCredit($this->incomeLedger, $this->service->id);
    expect(number_format($speechIncome, 2, '.', ''))->toBe('9000.00')
        ->and(feepostNetCredit($occupationalIncome, $this->otherService->id))->toBe('1500.00')
        ->and(feepostNetCredit($this->generalIncome))->toBe('0.00')
        ->and(feepostNetCredit($this->incomeLedger, null, $this->branch->id))->toBe('5000.00')
        ->and(feepostNetCredit($this->incomeLedger, null, $this->otherBranch->id))->toBe('4000.00')
        ->and(feepostNetCredit($this->otherCashLedger))->toBe('-4000.00');

    $batchAudit = AuditLog::query()->where('event', 'fee_posting.batch')->latest('id')->first();
    expect($batchAudit->properties['posted'])->toBe(4)->and($batchAudit->properties['skipped'])->toBe(2);

    $report = app(TrialBalanceService::class)->report($this->year->id, '2027-06-30', [$this->branch->id, $this->otherBranch->id]);
    expect($report['balanced'])->toBeTrue()->and($report['total_debit'])->toBe('10500.0000');
});

test('the preview shows journal lines and skip reasons without writing anything', function () {
    $eligible = feepostPayment($this, ['receipt_number' => 'FRC-111111']);
    $skipped = feepostPayment($this, ['receipt_number' => 'FRC-222222', 'source_status' => 'pending_verification']);
    $auditsBefore = AuditLog::query()->count();

    $this->get(route('fee-posting.payments.index', ['branch_id' => $this->branch->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'payment_method' => 'cash']))
        ->assertOk()
        ->assertSee('FRC-111111')->assertSee($this->cashLedger->code)->assertSee($this->incomeLedger->code)
        ->assertSee('FRC-222222')->assertSee('only paid payments are posted', false)
        ->assertSee('Post selected payments');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(FrcFeePosting::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditsBefore)
        ->and($eligible->fresh()->posting_status)->toBe('unposted')
        ->and($skipped->fresh()->posting_status)->toBe('unposted');
});

test('screens show the policy banner and the accrual screen only under accrual', function () {
    feepostSetting('fees.accounting_policy_approved', '0');
    $this->get(route('fee-posting.payments.index'))->assertOk()
        ->assertSee('Fee posting is blocked.')->assertSee('proposed default')
        ->assertSee('No unposted FRC payments match these filters.')
        ->assertDontSee('Enrollment Recognition');
    $this->get(route('fee-posting.enrollments.index'))->assertOk()->assertSee('applies only under the accrual basis');

    feepostSetting('fees.accounting_basis', 'accrual');
    feepostSetting('fees.accounting_policy_approved', '1');
    feepostEnrollment($this, ['frc_enrollment_id' => 777001]);
    $this->get(route('fee-posting.enrollments.index'))->assertOk()->assertSee('#777001')->assertSee($this->receivable->code)->assertSee('Enrollment Recognition');
    $this->get(route('fee-posting.postings.index'))->assertOk()->assertSee('No FRC fees have been posted yet.');

    expect(JournalEntry::query()->count())->toBe(0);
});

test('the posted receipts list links journals and is branch scoped', function () {
    $posting = feepostService()->postPayment(feepostPayment($this, ['receipt_number' => 'FRC-333333']), $this->poster);
    feepostService()->postPayment(feepostPayment($this, ['receipt_number' => 'FRC-444444', 'branch_id' => $this->otherBranch->id]), $this->poster);

    $this->get(route('fee-posting.postings.index'))->assertOk()
        ->assertSee('FRC-333333')->assertSee(route('journals.show', $posting->journal_entry_id))->assertSee('FRC-444444');

    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);
    $accountant->branches()->attach($this->branch);
    $this->actingAs($accountant)->get(route('fee-posting.postings.index'))->assertOk()->assertSee('FRC-333333')->assertDontSee('FRC-444444');
});

test('payments of two branches settle into their own cash accounts on one shared organisational ledger', function () {
    $secondCash = FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Cash - Second Branch', 'branch_id' => $this->otherBranch->id, 'account_id' => $this->cashLedger->id]);
    $settlement = app(FeeSettlementMappingService::class);
    $settlement->save($this->otherBranch, 'cash', $secondCash->id, $this->poster);

    expect($settlement->accountIssue($this->cash, $this->otherBranch->id, 'cash'))->toContain('belongs to another branch')
        ->and(fn () => $settlement->save($this->otherBranch, 'cash', $this->cash->id, $this->poster))->toThrow(ValidationException::class);

    $main = feepostService()->postPayment(feepostPayment($this, ['amount' => '3000.0000']), $this->poster)->journalEntry;
    $second = feepostService()->postPayment(feepostPayment($this, ['amount' => '1200.0000', 'branch_id' => $this->otherBranch->id]), $this->poster)->journalEntry;

    expect($main->branch_id)->toBe($this->branch->id)
        ->and($main->lines[0]->account_id)->toBe($this->cashLedger->id)
        ->and($main->lines[0]->debit)->toBe('3000.0000')
        ->and($second->branch_id)->toBe($this->otherBranch->id)
        ->and($second->lines[0]->account_id)->toBe($this->cashLedger->id)
        ->and($second->lines[0]->debit)->toBe('1200.0000');

    $cashFor = function (array $branchIds): string {
        $report = app(TrialBalanceService::class)->report($this->year->id, '2027-06-30', $branchIds);

        return $report['rows']->first(fn (array $row): bool => $row['account']->id === $this->cashLedger->id)['debit'];
    };
    expect($cashFor([$this->branch->id]))->toBe('3000.0000')
        ->and($cashFor([$this->otherBranch->id]))->toBe('1200.0000')
        ->and($cashFor([$this->branch->id, $this->otherBranch->id]))->toBe('4200.0000');
});
