<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Models\FrcSyncException;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Frc\FinancialSync\FrcReconciliationService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['frc_branch_id' => 2, 'name' => 'Lahore']);
    $this->otherBranch = Branch::factory()->create(['frc_branch_id' => 3, 'name' => 'Karachi']);
    $this->service = FrcService::factory()->create(['frc_service_id' => 5, 'name' => 'Speech Therapy']);
    $this->admin = frcreconUser(SystemRole::Administrator);
    $year = FiscalYear::factory()->create(['name' => 'FY 2026-27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'name' => 'September 2026', 'period_number' => 3, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => AccountingPeriodStatus::Open]);
    $this->year = $year;
    $this->cash = Account::factory()->create(['code' => '111001', 'name' => 'Cash in Hand', 'account_type' => 'asset', 'normal_balance' => 'debit', 'financial_statement' => 'balance_sheet']);
    $this->income = Account::factory()->create(['code' => '411001', 'name' => 'Fee Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);

    // Enrollments: two eligible in Lahore, one cancelled, one eligible in Karachi.
    $this->active = frcreconEnrollment(330, 'active');
    $this->completed = frcreconEnrollment(331, 'completed');
    $this->cancelled = frcreconEnrollment(332, 'cancelled');
    $this->karachi = frcreconEnrollment(333, 'approved', $this->otherBranch);

    // Seven paid payments: 18,000 in total, 11,000 cash and 7,000 card/bank transfer/easypaisa.
    $this->paid = collect([
        frcreconPayment(1, $this->active, '3000.00', 'cash'),
        frcreconPayment(2, $this->active, '4000.00', 'cash'),
        frcreconPayment(3, $this->completed, '2500.00', 'cash'),
        frcreconPayment(4, $this->completed, '1500.00', 'cash'),
        frcreconPayment(5, $this->active, '2000.00', 'card'),
        frcreconPayment(6, $this->completed, '3000.00', 'bank_transfer'),
        frcreconPayment(7, $this->active, '2000.00', 'easypaisa'),
    ]);
    frcreconPayment(8, $this->active, '1500.00', 'bank_transfer', 'pending_verification');
    frcreconPayment(9, $this->completed, '1000.00', 'jazzcash', 'rejected');
    $this->cancelledPaid = frcreconPayment(10, $this->cancelled, '5000.00', 'cash');
});

function frcreconUser(SystemRole $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    if ($branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

function frcreconEnrollment(int $id, string $status, ?Branch $branch = null): FrcEnrollment
{
    $branch ??= Branch::query()->where('frc_branch_id', 2)->first();

    return FrcEnrollment::query()->create([
        'frc_enrollment_id' => $id, 'frc_branch_id' => $branch->frc_branch_id, 'frc_service_id' => 5, 'branch_id' => $branch->id,
        'service_id' => FrcService::query()->value('id'), 'source_status' => $status, 'final_total' => '20000', 'content_hash' => str_repeat('a', 64),
        'source_updated_at' => '2026-09-01 10:00:00',
    ]);
}

function frcreconPayment(int $id, FrcEnrollment $enrollment, string $amount, string $method, string $status = 'paid', string $date = '2026-09-14', array $overrides = []): FrcPayment
{
    return FrcPayment::query()->create([
        'frc_payment_id' => $id, 'receipt_number' => $status === 'paid' ? sprintf('FRC-%06d', $id) : null, 'frc_enrollment_id' => $enrollment->frc_enrollment_id,
        'frc_enrollment_mirror_id' => $enrollment->id, 'frc_branch_id' => $enrollment->frc_branch_id, 'frc_service_id' => $enrollment->frc_service_id,
        'branch_id' => $enrollment->branch_id, 'service_id' => $enrollment->service_id, 'amount' => $amount, 'payment_method' => $method,
        'source_status' => $status, 'enrollment_status' => $enrollment->source_status, 'payment_date' => $date, 'content_hash' => str_repeat('b', 64),
        'source_updated_at' => '2026-09-14 10:00:00', 'posting_status' => 'unposted', ...$overrides,
    ]);
}

/** A posted (or reversed) journal and its fee posting, written directly as fixtures. */
function frcreconPost(object $test, FrcPayment $payment, ?string $amount = null, string $status = 'posted'): FrcFeePosting
{
    static $number = 0;
    $number++;
    $amount ??= (string) $payment->amount;
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-09-14', 'fiscal_year_id' => $test->year->id,
        'accounting_period_id' => $test->period->id, 'branch_id' => $payment->branch_id ?? $test->branch->id, 'narration' => 'FRC fee fixture', 'status' => JournalStatus::Draft, 'created_by' => $test->admin->id,
    ]);
    $journal->lines()->create(['line_number' => 1, 'account_id' => $test->cash->id, 'debit' => $amount, 'credit' => '0']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $test->income->id, 'debit' => '0', 'credit' => $amount]);
    DB::table('journal_entries')->where('id', $journal->id)->update(['status' => JournalStatus::Posted->value, 'voucher_number' => 'FR-RECON-'.$number]);
    if ($status === 'posted') {
        $payment->forceFill(['posting_status' => 'posted'])->save();
    }

    return FrcFeePosting::query()->create([
        'kind' => 'payment', 'basis' => 'cash', 'frc_payment_id' => $payment->id, 'amount' => $amount, 'source_key' => 'frc_payment:'.$payment->frc_payment_id.':'.$number,
        'journal_entry_id' => $journal->id, 'status' => $status, 'posted_by' => $test->admin->id,
    ]);
}

function frcreconRun(object $test, array $scope = ['branch_id' => null], ?User $user = null): array
{
    return app(FrcReconciliationService::class)->reconcile($user ?? $test->admin, ['branch_id' => $test->branch->id, ...$scope]);
}

test('source figures follow the finance report rules exactly', function () {
    $result = frcreconRun($this);

    expect($result['source'])->toMatchArray([
        'paid_count' => 7, 'paid_total' => '18000.0000', 'cash_total' => '11000.0000', 'online_total' => '7000.0000',
        'pending_count' => 1, 'pending_total' => '1500.0000', 'rejected_count' => 1, 'rejected_total' => '1000.0000',
        'cancelled_count' => 0, 'refunded_count' => 0,
        'excluded_paid_count' => 1, 'excluded_paid_total' => '5000.0000',
        'record_count' => 9, 'all_records' => 10,
    ])->and($result['ledger'])->toMatchArray([
        'eligible_count' => 7, 'eligible_total' => '18000.0000', 'posted_total' => '0.0000',
        'unposted_eligible_count' => 7, 'unposted_eligible_total' => '18000.0000', 'reversed_total' => '0.0000', 'unmapped_count' => 0,
    ])->and($result['status'])->toBe('not_reconciled')
        ->and($result['difference_counts'][FrcReconciliationService::DiffMissingPosting])->toBe(7)
        ->and(collect($result['rows'])->pluck('frc_payment_id')->all())->toBe([1, 2, 3, 4, 5, 6, 7]);
});

test('it is reconciled only when every eligible payment is posted once with the same amount and nothing is open', function () {
    foreach ($this->paid as $payment) {
        frcreconPost($this, $payment);
    }

    $result = frcreconRun($this);
    expect($result['status'])->toBe('reconciled')->and($result['rows'])->toBe([])
        ->and($result['ledger']['posted_total'])->toBe('18000.0000')->and($result['ledger']['unposted_eligible_total'])->toBe('0.0000');
    $this->actingAs($this->admin)->get(route('frc-integration.reconciliation', ['branch_id' => $this->branch->id]))->assertOk()->assertSee('Reconciled.');

    FrcSyncException::query()->create(['entity' => 'payments', 'external_id' => 3, 'code' => 'unmapped_service', 'message' => 'Payment 3: test.']);
    $result = frcreconRun($this);
    expect($result['status'])->toBe('not_reconciled')->and($result['open_exceptions']['count'])->toBe(1)
        ->and($result['rows'][0]['types'])->toBe([FrcReconciliationService::DiffOpenException]);

    FrcSyncException::query()->update(['resolved_at' => now()]);
    expect(frcreconRun($this)['status'])->toBe('reconciled');
});

test('each difference type is detected at receipt level', function () {
    [$p1, $p2, $p3, $p4, $p5, $p6, $p7] = $this->paid->all();
    frcreconPost($this, $p1, '2999.00');                    // amount mismatch
    frcreconPost($this, $p2);
    frcreconPost($this, $p2);                               // duplicate posting
    frcreconPost($this, $p3);
    $p3->forceFill(['posting_status' => 'needs_review', 'posting_note' => 'changed'])->save(); // needs review
    frcreconPost($this, $p4);
    frcreconPost($this, $p5);
    frcreconPost($this, $p6, status: 'reversed');           // reversed only, so missing
    frcreconPost($this, $this->cancelledPaid);              // posted but enrollment cancelled
    $p7->forceFill(['service_id' => null])->save();         // unmapped and missing

    $result = frcreconRun($this);
    $types = collect($result['rows'])->mapWithKeys(fn (array $row) => [$row['frc_payment_id'] => $row['types']]);

    expect($result['status'])->toBe('not_reconciled')
        ->and($types[1])->toBe([FrcReconciliationService::DiffAmountMismatch])
        ->and($types[2])->toBe([FrcReconciliationService::DiffDuplicatePosting])
        ->and($types[3])->toBe([FrcReconciliationService::DiffNeedsReview])
        ->and($types->has(4))->toBeFalse()->and($types->has(5))->toBeFalse()
        ->and($types[6])->toBe([FrcReconciliationService::DiffMissingPosting])
        ->and($types[7])->toBe([FrcReconciliationService::DiffMissingPosting, FrcReconciliationService::DiffUnmapped])
        ->and($types[10])->toBe([FrcReconciliationService::DiffPostedNotEligible])
        ->and($result['ledger']['reversed_total'])->toBe('3000.0000')
        ->and($result['ledger']['posted_total'])->toBe('21999.0000')
        ->and($result['ledger']['unposted_eligible_total'])->toBe('5000.0000')
        ->and($result['ledger']['needs_review_count'])->toBe(1)
        ->and($result['ledger']['unmapped_count'])->toBe(1);

    // A payment refunded at the source after posting is no longer eligible.
    $p4->forceFill(['source_status' => 'refunded'])->save();
    $types = collect(frcreconRun($this)['rows'])->mapWithKeys(fn (array $row) => [$row['frc_payment_id'] => $row['types']]);
    expect($types[4])->toBe([FrcReconciliationService::DiffPostedNotEligible]);
});

test('branch scoping and date filters restrict the reconciliation', function () {
    frcreconPayment(20, $this->karachi, '700.00', 'cash');
    frcreconPayment(21, $this->active, '900.00', 'cash', 'paid', '2026-10-02');
    $lahoreUser = frcreconUser(SystemRole::Accountant, $this->branch);

    $all = app(FrcReconciliationService::class)->reconcile($this->admin, []);
    expect($all['source']['paid_total'])->toBe('19600.0000');

    $lahore = app(FrcReconciliationService::class)->reconcile($lahoreUser, []);
    expect($lahore['source']['paid_total'])->toBe('18900.0000');

    $september = app(FrcReconciliationService::class)->reconcile($lahoreUser, ['from' => '2026-09-01', 'to' => '2026-09-30']);
    expect($september['source']['paid_total'])->toBe('18000.0000')->and($september['source']['paid_count'])->toBe(7);

    $karachi = app(FrcReconciliationService::class)->reconcile($this->admin, ['branch_id' => $this->otherBranch->id]);
    expect($karachi['source']['paid_total'])->toBe('700.0000');

    $this->actingAs($lahoreUser)->get(route('frc-integration.reconciliation', ['branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->actingAs($lahoreUser)->get(route('frc-integration.reconciliation', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk()->assertSee('18,000.00')->assertSee('11,000.00')->assertSee('7,000.00')->assertDontSee('FRC-000020');
});

test('payments whose branch is unmapped are visible only to all-branch users', function () {
    $unmapped = frcreconPayment(30, $this->active, '400.00', 'cash', overrides: ['branch_id' => null, 'frc_branch_id' => 99]);
    $lahoreUser = frcreconUser(SystemRole::Accountant, $this->branch);

    expect(app(FrcReconciliationService::class)->reconcile($lahoreUser, [])['source']['paid_total'])->toBe('18000.0000');
    $all = app(FrcReconciliationService::class)->reconcile($this->admin, []);
    expect($all['source']['paid_total'])->toBe('18400.0000')
        ->and(collect($all['rows'])->firstWhere('frc_payment_id', 30)['types'])->toContain(FrcReconciliationService::DiffUnmapped)
        ->and($unmapped->exists)->toBeTrue();
});

test('the reconciliation exports to csv, xlsx and pdf with the right permissions', function () {
    frcreconPost($this, $this->paid[0], '2999.00');
    $query = ['branch_id' => $this->branch->id, 'from' => '2026-09-01', 'to' => '2026-09-30'];

    $csv = $this->actingAs($this->admin)->get(route('frc-integration.reconciliation.export', ['format' => 'csv', ...$query]));
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $content = $csv->streamedContent();
    expect($content)->toContain('FRC Fee Reconciliation')->toContain('Status: Not reconciled')
        ->toContain('"Total paid",,,,18000.0000,,"7 payments"')->toContain('"Cash received",,,,11000.0000')->toContain('"Online/bank received",,,,7000.0000')
        ->toContain('"Posted amount differs from the source amount",FRC-000001,1,2026-09-14,3000.0000,2999.0000')
        ->toContain('diagnostic reference')->toContain('Branch: Lahore');

    $xlsx = $this->actingAs($this->admin)->get(route('frc-integration.reconciliation.export', ['format' => 'xlsx', ...$query]));
    $xlsx->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'frcrecon');
    file_put_contents($path, $xlsx->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
    unlink($path);
    expect(collect($sheet)->first(fn (array $row) => $row[0] === 'Total paid')[4])->toBe('18,000.00');

    $pdf = $this->actingAs($this->admin)->get(route('frc-integration.reconciliation.export', ['format' => 'pdf', ...$query]));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($pdf->streamedContent(), '%PDF'))->toBeTrue();

    $approver = frcreconUser(SystemRole::Approver, $this->branch);
    $this->actingAs($approver)->get(route('frc-integration.reconciliation', $query))->assertOk();
    $this->actingAs($approver)->get(route('frc-integration.reconciliation.export', ['format' => 'csv', ...$query]))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('frc-integration.reconciliation.export', ['format' => 'csv', ...$query]))->assertForbidden();
});

test('the source payment list totals follow the finance report rule and show excluded paid amounts', function () {
    $this->actingAs($this->admin)->get(route('frc-integration.payments.index'))
        ->assertOk()
        ->assertSeeInOrder(['Paid total', '18,000.00', 'Cash', '11,000.00', 'Online / bank', '7,000.00'])
        ->assertSee('5,000.00 paid on other enrollments excluded');

    $this->actingAs($this->admin)->get(route('frc-integration.payments.index', ['method' => 'cash']))
        ->assertOk()
        ->assertSeeInOrder(['Paid total', '11,000.00']);
});
