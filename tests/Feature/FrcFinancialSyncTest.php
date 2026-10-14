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
use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Models\FrcSyncException;
use App\Models\FrcSyncRun;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Frc\FinancialSync\FrcFinancialSyncService;
use App\Services\Frc\FinancialSync\FrcSyncOutcome;
use App\Services\Frc\FrcApiClient;
use App\Services\Frc\ServiceSyncService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const FRCSYNC_TOKEN = 'frcsync-test-token-do-not-leak';
const FRCSYNC_ENROLLMENTS = 'http://frc.test/api/integrations/accounting/v1/enrollments*';
const FRCSYNC_PAYMENTS = 'http://frc.test/api/integrations/accounting/v1/payments*';

beforeEach(function () {
    config()->set('services.frc.enabled', true);
    config()->set('services.frc.base_url', 'http://frc.test/api');
    config()->set('services.frc.token', FRCSYNC_TOKEN);
    config()->set('services.frc.branches_endpoint', 'integrations/accounting/v1/branches');
    config()->set('services.frc.services_endpoint', 'integrations/accounting/v1/services');
    config()->set('services.frc.retry_times', 0);
    config()->set('services.frc.retry_delay_ms', 0);
    $this->seed(RolePermissionSeeder::class);
    $this->branch = Branch::factory()->create(['frc_branch_id' => 2, 'name' => 'Lahore']);
    $this->otherBranch = Branch::factory()->create(['frc_branch_id' => 3, 'name' => 'Karachi']);
    $this->service = FrcService::factory()->create(['frc_service_id' => 5, 'name' => 'Speech Therapy']);
    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message.json_encode($event->context));
});

function frcsyncEnrollment(array $overrides = []): array
{
    return [
        'id' => 330, 'branch_id' => 2, 'service_id' => 5, 'status' => 'active', 'payment_status' => 'partial',
        'final_total' => '10000.00', 'discount_amount' => '500.00', 'paid_amount' => '3000.00', 'remaining_amount' => '7000.00',
        'approved_at' => '2026-09-01T09:00:00+05:00', 'created_at' => '2026-09-01T08:00:00+05:00', 'updated_at' => '2026-09-10T10:00:00+05:00', 'deleted_at' => null,
        ...$overrides,
    ];
}

function frcsyncPayment(array $overrides = [], array $enrollment = []): array
{
    return [
        'id' => 1201, 'receipt_number' => 'FRC-000045', 'enrollment_id' => 330, 'amount' => '3000.00', 'payment_method' => 'cash', 'status' => 'paid',
        'payment_date' => '2026-09-14', 'verified_at' => '2026-09-14T10:22:00+05:00', 'created_at' => '2026-09-14T10:00:00+05:00',
        'updated_at' => '2026-09-14T10:22:00+05:00', 'deleted_at' => null,
        'enrollment' => ['id' => $overrides['enrollment_id'] ?? 330, 'branch_id' => 2, 'service_id' => 5, 'status' => 'active', 'updated_at' => '2026-09-10T10:00:00+05:00', ...$enrollment],
        ...$overrides,
    ];
}

function frcsyncPage(array $records, bool $hasMore = false, ?string $nextSince = null, ?int $nextAfterId = null): array
{
    return ['data' => $records, 'meta' => ['per_page' => 100, 'has_more' => $hasMore, 'next_updated_since' => $nextSince, 'next_after_id' => $nextAfterId, 'server_time' => '2026-10-14T10:00:00+05:00']];
}

/** Fakes both endpoints; each argument is the list of pages returned in order. */
function frcsyncFake(array $enrollmentPages = [], array $paymentPages = []): void
{
    // A fresh factory, because Http::fake() appends stubs and the first match would win.
    $factory = new Factory(app('events'));
    app()->instance(Factory::class, $factory);
    Http::swap($factory);
    $sequence = function (array $pages) {
        $sequence = Http::sequence();
        foreach ($pages ?: [frcsyncPage([])] as $page) {
            $sequence->push($page, 200);
        }

        return $sequence->whenEmpty(Http::response(frcsyncPage([]), 200));
    };
    Http::fake([FRCSYNC_ENROLLMENTS => $sequence($enrollmentPages), FRCSYNC_PAYMENTS => $sequence($paymentPages)]);
}

function frcsyncRun(string $mode = 'full'): FrcSyncOutcome
{
    return app(FrcFinancialSyncService::class)->sync($mode);
}

function frcsyncUser(SystemRole $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    if ($branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/** A posted journal and its fee posting link, written directly as fixtures. */
function frcsyncPosting(object $test, FrcPayment $payment, ?string $amount = null): FrcFeePosting
{
    $test->seed([AppSettingSeeder::class, VoucherTypeSeeder::class]);
    $admin = User::factory()->create();
    $year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'name' => 'September 2026', 'period_number' => 3, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => AccountingPeriodStatus::Open]);
    $cash = Account::factory()->create(['code' => '111001', 'name' => 'Cash in Hand', 'account_type' => 'asset', 'normal_balance' => 'debit', 'financial_statement' => 'balance_sheet']);
    $income = Account::factory()->create(['code' => '411001', 'name' => 'Fee Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $amount ??= (string) $payment->amount;
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-09-14', 'fiscal_year_id' => $year->id,
        'accounting_period_id' => $period->id, 'branch_id' => $payment->branch_id, 'narration' => 'FRC fee fixture', 'status' => JournalStatus::Draft, 'created_by' => $admin->id,
    ]);
    $journal->lines()->create(['line_number' => 1, 'account_id' => $cash->id, 'debit' => $amount, 'credit' => '0']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $income->id, 'debit' => '0', 'credit' => $amount]);
    DB::table('journal_entries')->where('id', $journal->id)->update(['status' => JournalStatus::Posted->value, 'voucher_number' => 'FR-TEST-1', 'total_debit' => $amount, 'total_credit' => $amount]);
    $payment->forceFill(['posting_status' => 'posted'])->save();

    return FrcFeePosting::query()->create([
        'kind' => 'payment', 'basis' => 'cash', 'frc_payment_id' => $payment->id, 'amount' => $amount, 'source_key' => 'frc_payment:'.$payment->frc_payment_id,
        'journal_entry_id' => $journal->id, 'status' => 'posted', 'posted_by' => $admin->id,
    ]);
}

/** Asserts the token appears nowhere: runs, exceptions, audit logs and log messages. */
function frcsyncAssertNoTokenLeak(object $test): void
{
    $haystack = json_encode([
        FrcSyncRun::query()->get()->toArray(), FrcSyncException::query()->get()->toArray(), AuditLog::query()->get()->toArray(), $test->logged,
    ]);
    expect($haystack)->not->toContain(FRCSYNC_TOKEN);
}

test('the sync sends the bearer token and reads enrollments before payments', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [frcsyncPage([frcsyncPayment()])]);

    $outcome = frcsyncRun();

    expect($outcome->successful)->toBeTrue()->and($outcome->runs)->toHaveCount(2)
        ->and($outcome->runs[0]->entity)->toBe('enrollments')->and($outcome->runs[1]->entity)->toBe('payments');
    $recorded = Http::recorded();
    expect($recorded[0][0]->url())->toContain('/enrollments')->and($recorded[1][0]->url())->toContain('/payments');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.FRCSYNC_TOKEN) && $request->hasHeader('Accept', 'application/json') && $request->method() === 'GET');

    $payment = FrcPayment::query()->sole();
    expect($payment->amount)->toBe('3000.0000')
        ->and($payment->branch_id)->toBe($this->branch->id)
        ->and($payment->service_id)->toBe($this->service->id)
        ->and($payment->frc_enrollment_mirror_id)->toBe(FrcEnrollment::query()->sole()->id)
        ->and($payment->posting_status)->toBe('unposted')
        ->and($payment->source_updated_at->equalTo(Carbon::parse('2026-09-14T10:22:00+05:00')))->toBeTrue();
    expect(AuditLog::query()->where('event', 'frc_sync.started')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'frc_sync.completed')->count())->toBe(2);
    frcsyncAssertNoTokenLeak($this);
});

test('connection and authorisation failures end the run failed without advancing the cursor', function (int|string $failure, string $expected) {
    $previous = FrcSyncRun::query()->create(['entity' => 'enrollments', 'mode' => 'full', 'status' => 'completed', 'cursor_to' => '2026-09-01 10:00:00', 'cursor_to_id' => 50, 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
    if ($failure === 'missing_token') {
        config()->set('services.frc.token');
        Http::fake();
    } elseif ($failure === 'malformed') {
        Http::fake(['*' => Http::response('<html>'.FRCSYNC_TOKEN.'</html>', 200, ['Content-Type' => 'text/html'])]);
    } elseif ($failure === 'bad_envelope') {
        Http::fake(['*' => Http::response(['rows' => [], 'token' => FRCSYNC_TOKEN], 200)]);
    } else {
        Http::fake(['*' => Http::response(['message' => 'Bad token '.FRCSYNC_TOKEN], $failure)]);
    }

    $outcome = frcsyncRun('incremental');

    $run = FrcSyncRun::query()->where('entity', 'enrollments')->latest('id')->first();
    expect($outcome->successful)->toBeFalse()
        ->and($run->status)->toBe('failed')
        ->and($run->error)->toContain($expected)
        ->and($run->cursor_to)->toBeNull()
        ->and($run->cursor_from->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($run->cursor_from_id)->toBe(50)
        ->and(FrcSyncRun::query()->where('entity', 'payments')->exists())->toBeFalse()
        ->and(app(FrcFinancialSyncService::class)->lastCursor('enrollments')[1])->toBe($previous->cursor_to_id)
        ->and(AuditLog::query()->where('event', 'frc_sync.failed')->exists())->toBeTrue()
        ->and($outcome->message)->not->toContain(FRCSYNC_TOKEN);
    frcsyncAssertNoTokenLeak($this);
})->with([
    'missing token' => ['missing_token', 'token is missing'],
    '401' => [401, 'HTTP 401'],
    '403' => [403, 'HTTP 403'],
    '404' => [404, 'awaiting approval'],
    'malformed json' => ['malformed', 'invalid response'],
    'bad envelope' => ['bad_envelope', 'malformed enrollments page'],
]);

test('a timeout ends the run failed with a sanitised message', function () {
    app()->bind(FrcApiClient::class, fn () => new class extends FrcApiClient
    {
        protected function send(string $endpoint, array $query): Response
        {
            throw new ConnectionException('cURL timeout with Bearer '.FRCSYNC_TOKEN);
        }
    });

    $outcome = frcsyncRun();

    expect($outcome->successful)->toBeFalse()
        ->and(FrcSyncRun::query()->sole()->error)->toBe('FRC API server is unreachable or the connection timed out.');
    frcsyncAssertNoTokenLeak($this);
});

test('pagination follows the cursor across three pages and stores the final cursor', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [
        frcsyncPage([frcsyncPayment(['id' => 1, 'updated_at' => '2026-09-14T10:00:00+05:00'])], true, '2026-09-14T10:00:00+05:00', 1),
        frcsyncPage([frcsyncPayment(['id' => 2, 'updated_at' => '2026-09-15T10:00:00+05:00'])], true, '2026-09-15T10:00:00+05:00', 2),
        frcsyncPage([frcsyncPayment(['id' => 3, 'updated_at' => '2026-09-16T10:00:00+05:00'])], false),
    ]);

    frcsyncRun();

    $run = FrcSyncRun::query()->where('entity', 'payments')->sole();
    expect($run->status)->toBe('completed')->and($run->pages)->toBe(3)->and($run->fetched)->toBe(3)->and($run->created)->toBe(3)
        ->and($run->cursor_to->equalTo(Carbon::parse('2026-09-16T10:00:00+05:00')))->toBeTrue()
        ->and($run->cursor_to_id)->toBe(3)
        ->and(FrcPayment::query()->count())->toBe(3);
    $paymentRequests = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $request) => str_contains($request->url(), '/payments'))->values();
    expect($paymentRequests)->toHaveCount(3)
        ->and($paymentRequests[0]->data())->not->toHaveKey('updated_since')
        ->and($paymentRequests[1]['after_id'])->toBe(1)
        ->and(Carbon::parse($paymentRequests[2]['updated_since'])->equalTo(Carbon::parse('2026-09-15T10:00:00+05:00')))->toBeTrue()
        ->and($paymentRequests[2]['after_id'])->toBe(2);
});

test('an incremental sync starts from the stored cursor of the last successful run', function () {
    FrcSyncRun::query()->create(['entity' => 'payments', 'mode' => 'full', 'status' => 'completed', 'cursor_to' => Carbon::parse('2026-09-20T12:00:00+05:00'), 'cursor_to_id' => 77, 'started_at' => now(), 'finished_at' => now()]);
    FrcSyncRun::query()->create(['entity' => 'payments', 'mode' => 'incremental', 'status' => 'failed', 'cursor_from' => now(), 'started_at' => now(), 'finished_at' => now()->addMinute()]);
    frcsyncFake([frcsyncPage([])], [frcsyncPage([])]);

    frcsyncRun('incremental');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payments')
        && Carbon::parse($request['updated_since'])->equalTo(Carbon::parse('2026-09-20T12:00:00+05:00'))
        && $request['after_id'] === 77);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/enrollments') && ! isset($request['updated_since']));
    $latest = FrcSyncRun::query()->where('entity', 'payments')->latest('id')->first();
    expect($latest->cursor_to_id)->toBe(77)->and($latest->mode)->toBe('incremental');

    // A full sync ignores the stored cursor.
    frcsyncFake();
    frcsyncRun('full');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payments') && ! isset($request['updated_since']));
});

test('a repeated cursor or a has_more page without a cursor fails safely', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()], true, '2026-09-10T10:00:00+05:00', 330), frcsyncPage([frcsyncEnrollment()], true, '2026-09-10T10:00:00+05:00', 330)]);

    $outcome = frcsyncRun();

    $run = FrcSyncRun::query()->sole();
    expect($outcome->successful)->toBeFalse()->and($run->status)->toBe('failed')->and($run->error)->toContain('same cursor twice')
        ->and($run->cursor_to)->toBeNull()->and(FrcEnrollment::query()->count())->toBe(1);

    frcsyncFake([frcsyncPage([frcsyncEnrollment()], true)]);
    frcsyncRun();
    expect(FrcSyncRun::query()->latest('id')->first()->error)->toContain('no next cursor');
});

test('duplicates across pages and repeated syncs never duplicate records', function () {
    $pages = [
        frcsyncPage([frcsyncPayment(['id' => 10]), frcsyncPayment(['id' => 11, 'updated_at' => '2026-09-14T11:00:00+05:00'])], true, '2026-09-14T11:00:00+05:00', 11),
        frcsyncPage([frcsyncPayment(['id' => 11, 'updated_at' => '2026-09-14T11:00:00+05:00'])]),
    ];
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], $pages);
    frcsyncRun();
    $first = FrcSyncRun::query()->where('entity', 'payments')->sole();
    expect($first->created)->toBe(2)->and($first->unchanged)->toBe(1);

    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], $pages);
    frcsyncRun('full');
    $second = FrcSyncRun::query()->where('entity', 'payments')->latest('id')->first();
    expect($second->created)->toBe(0)->and($second->updated)->toBe(0)->and($second->unchanged)->toBe(3)
        ->and(FrcPayment::query()->count())->toBe(2)->and(FrcEnrollment::query()->count())->toBe(1);
});

test('an updated record updates the mirror and a stale out-of-order record is ignored', function () {
    frcsyncFake([], [frcsyncPage([frcsyncPayment(['status' => 'pending_verification', 'receipt_number' => null])])]);
    frcsyncRun();

    frcsyncFake([], [frcsyncPage([frcsyncPayment(['status' => 'paid', 'updated_at' => '2026-09-15T09:00:00+05:00'])])]);
    frcsyncRun();
    $payment = FrcPayment::query()->sole();
    expect($payment->source_status)->toBe('paid')->and($payment->receipt_number)->toBe('FRC-000045')
        ->and(FrcSyncRun::query()->where('entity', 'payments')->latest('id')->first()->updated)->toBe(1);

    frcsyncFake([], [frcsyncPage([frcsyncPayment(['status' => 'rejected', 'amount' => '1.00', 'updated_at' => '2026-09-14T08:00:00+05:00'])])]);
    frcsyncRun();
    $payment->refresh();
    expect($payment->source_status)->toBe('paid')->and($payment->amount)->toBe('3000.0000')
        ->and(FrcSyncRun::query()->where('entity', 'payments')->latest('id')->first()->unchanged)->toBe(1);
});

test('an invalid record is skipped with a sanitised exception while the others import', function (array $invalid, string $message) {
    frcsyncFake([], [frcsyncPage([frcsyncPayment(['id' => 1]), frcsyncPayment(['id' => 2, ...$invalid]), frcsyncPayment(['id' => 3])])]);

    $outcome = frcsyncRun();

    $run = FrcSyncRun::query()->where('entity', 'payments')->sole();
    $exception = FrcSyncException::query()->where('code', 'invalid_record')->sole();
    expect($outcome->successful)->toBeTrue()
        ->and($run->created)->toBe(2)->and($run->failed)->toBe(1)
        ->and(FrcPayment::query()->pluck('frc_payment_id')->sort()->values()->all())->toBe([1, 3])
        ->and($exception->external_id)->toBe(2)
        ->and($exception->message)->toContain($message)
        ->and($exception->message)->not->toContain('child_name')->not->toContain('Ayesha');
})->with([
    'three decimals' => [['amount' => '10.123'], 'at most two decimals'],
    'negative amount' => [['amount' => '-5.00'], 'non-negative'],
    'float amount' => [['amount' => 10.5], 'at most two decimals'],
    'unknown status' => [['status' => 'approved'], 'unknown payment status'],
    'unknown method' => [['payment_method' => 'cheque'], 'unknown payment method'],
    'bad date' => [['payment_date' => '2026-02-30'], 'not a valid date'],
    'missing enrollment' => [['enrollment_id' => null, 'enrollment' => null], 'enrollment_id is required'],
    'missing updated_at' => [['updated_at' => null, 'child_name' => 'Ayesha'], 'updated_at is required'],
]);

test('partial payments are separate records and pending verification is mirrored', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [frcsyncPage([
        frcsyncPayment(['id' => 1, 'amount' => '3000.00']),
        frcsyncPayment(['id' => 2, 'amount' => '2500.50', 'receipt_number' => 'FRC-000046', 'payment_method' => 'easypaisa']),
        frcsyncPayment(['id' => 3, 'amount' => '4499.50', 'receipt_number' => null, 'status' => 'pending_verification', 'verified_at' => null, 'payment_method' => 'bank_transfer']),
    ])]);

    frcsyncRun();

    $payments = FrcPayment::query()->orderBy('frc_payment_id')->get();
    expect($payments)->toHaveCount(3)
        ->and($payments->pluck('frc_enrollment_id')->unique()->all())->toBe([330])
        ->and($payments[1]->amount)->toBe('2500.5000')
        ->and($payments[2]->source_status)->toBe('pending_verification')
        ->and($payments[2]->receipt_number)->toBeNull()
        ->and($payments[2]->posting_status)->toBe('unposted');
});

test('unknown branches and services stay unmapped with exceptions and are never created', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment(['branch_id' => 99, 'service_id' => 88])])], [frcsyncPage([frcsyncPayment([], ['branch_id' => 99, 'service_id' => 88])])]);

    frcsyncRun();

    $payment = FrcPayment::query()->sole();
    expect($payment->branch_id)->toBeNull()->and($payment->service_id)->toBeNull()
        ->and($payment->frc_branch_id)->toBe(99)->and($payment->frc_service_id)->toBe(88)
        ->and(Branch::query()->count())->toBe(2)->and(FrcService::query()->count())->toBe(1)
        ->and(FrcSyncException::query()->where('code', 'unmapped_branch')->count())->toBe(2)
        ->and(FrcSyncException::query()->where('code', 'unmapped_service')->count())->toBe(2);

    // Repeating the sync does not pile up duplicate open exceptions.
    frcsyncFake([frcsyncPage([frcsyncEnrollment(['branch_id' => 99, 'service_id' => 88])])], [frcsyncPage([frcsyncPayment([], ['branch_id' => 99, 'service_id' => 88])])]);
    frcsyncRun();
    expect(FrcSyncException::query()->whereNull('resolved_at')->count())->toBe(4);

    // Once the branch exists locally, the next sync maps it and closes the exception.
    Branch::factory()->create(['frc_branch_id' => 99, 'name' => 'Islamabad']);
    frcsyncFake([frcsyncPage([frcsyncEnrollment(['branch_id' => 99, 'service_id' => 88])])], [frcsyncPage([frcsyncPayment([], ['branch_id' => 99, 'service_id' => 88])])]);
    frcsyncRun();
    expect($payment->fresh()->branch_id)->not->toBeNull()
        ->and(FrcSyncException::query()->where('code', 'unmapped_branch')->whereNull('resolved_at')->count())->toBe(0);
});

test('a change after posting flags needs_review and leaves the journal untouched', function (array $change, string $expected) {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [frcsyncPage([frcsyncPayment()])]);
    frcsyncRun();
    $payment = FrcPayment::query()->sole();
    $posting = frcsyncPosting($this, $payment);
    $journalBefore = JournalEntry::query()->with('lines')->find($posting->journal_entry_id)->toArray();

    frcsyncFake([frcsyncPage([])], [frcsyncPage([frcsyncPayment(['updated_at' => '2026-09-20T10:00:00+05:00', ...$change])])]);
    frcsyncRun('incremental');

    $payment->refresh();
    expect($payment->posting_status)->toBe('needs_review')
        ->and($payment->posting_note)->toContain($expected)->toContain('journal was not changed')
        ->and(FrcSyncException::query()->where('code', 'changed_after_posting')->where('external_id', 1201)->exists())->toBeTrue()
        ->and(JournalEntry::query()->with('lines')->find($posting->journal_entry_id)->toArray())->toBe($journalBefore)
        ->and($posting->fresh()->status)->toBe('posted')
        ->and(JournalEntry::query()->count())->toBe(1);
})->with([
    'amount' => [['amount' => '3500.00'], 'amount'],
    'method' => [['payment_method' => 'card'], 'method'],
    'date' => [['payment_date' => '2026-09-15'], 'payment date'],
    'refunded' => [['status' => 'refunded'], 'status paid → refunded'],
    'cancelled' => [['status' => 'cancelled'], 'status paid → cancelled'],
    'deleted' => [['deleted_at' => '2026-09-20T10:00:00+05:00'], 'deleted in FRC'],
]);

test('personal fields sent by the source are never stored or shown', function () {
    $personal = ['child_name' => 'Ayesha Khan', 'guardian_phone' => '0300-1234567', 'cnic' => '35202-1234567-1', 'transaction_reference' => 'TXN-SECRET-9', 'notes' => 'clinical note'];
    frcsyncFake([frcsyncPage([frcsyncEnrollment($personal)])], [frcsyncPage([frcsyncPayment($personal, $personal)])]);
    frcsyncRun();

    $stored = json_encode([DB::table('frc_payments')->get(), DB::table('frc_enrollments')->get(), DB::table('frc_sync_exceptions')->get(), DB::table('audit_logs')->get()]);
    foreach ($personal as $value) {
        expect($stored)->not->toContain($value);
    }
    expect(array_keys((array) DB::table('frc_payments')->first()))->not->toContain('child_name');

    $admin = frcsyncUser(SystemRole::Administrator);
    $response = $this->actingAs($admin)->get(route('frc-integration.payments.show', FrcPayment::query()->sole()))->assertOk();
    foreach ($personal as $value) {
        $response->assertDontSee($value);
    }
});

test('a second sync of the same entity is refused while one is running', function () {
    frcsyncFake();
    $lock = Cache::lock(FrcFinancialSyncService::lockKey('payments'), 60);
    expect($lock->get())->toBeTrue();

    $outcome = frcsyncRun();

    expect($outcome->successful)->toBeFalse()->and($outcome->alreadyRunning)->toBeTrue()
        ->and($outcome->message)->toContain('already running')
        ->and(FrcSyncRun::query()->count())->toBe(0);
    Http::assertNothingSent();

    $this->actingAs(frcsyncUser(SystemRole::Accountant, $this->branch))
        ->post(route('frc-integration.sync'), ['mode' => 'full', 'confirm' => '1'])
        ->assertRedirect()->assertSessionHas('error', fn (string $message) => str_contains($message, 'already running'));

    $lock->release();
    expect(frcsyncRun()->successful)->toBeTrue();
});

test('screens require frc_integration permissions and syncing requires the sync permission', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [frcsyncPage([frcsyncPayment()])]);
    frcsyncRun();
    $payment = FrcPayment::query()->sole();
    $nobody = User::factory()->create();
    $viewer = frcsyncUser(SystemRole::Auditor, $this->branch);
    $accountant = frcsyncUser(SystemRole::Accountant, $this->branch);

    foreach (['frc-integration.index', 'frc-integration.payments.index', 'frc-integration.payments.unmapped', 'frc-integration.runs.index', 'frc-integration.exceptions.index', 'frc-integration.reconciliation'] as $route) {
        $this->actingAs($nobody)->get(route($route))->assertForbidden();
        $this->actingAs($viewer)->get(route($route))->assertOk();
    }
    $this->actingAs($nobody)->get(route('frc-integration.payments.show', $payment))->assertForbidden();
    $this->actingAs($viewer)->get(route('frc-integration.payments.show', $payment))->assertOk()->assertSee('FRC-000045');

    $this->actingAs($viewer)->post(route('frc-integration.sync'), ['mode' => 'full', 'confirm' => '1'])->assertForbidden();
    $this->actingAs($viewer)->post(route('frc-integration.check'))->assertForbidden();
    $this->actingAs($accountant)->post(route('frc-integration.sync'), ['mode' => 'full'])->assertSessionHasErrors('confirm');
    $this->actingAs($accountant)->post(route('frc-integration.sync'), ['mode' => 'incremental', 'confirm' => '1'])->assertRedirect(route('frc-integration.index'))->assertSessionHas('status');
    expect(FrcSyncRun::query()->where('triggered_by', $accountant->id)->count())->toBe(2);

    $exception = FrcSyncException::query()->create(['entity' => 'payments', 'external_id' => 1201, 'code' => 'invalid_record', 'message' => 'Payment 1201: test.']);
    $this->actingAs($viewer)->post(route('frc-integration.exceptions.resolve', $exception))->assertForbidden();
    $this->actingAs($accountant)->post(route('frc-integration.exceptions.resolve', $exception))->assertRedirect();
    expect($exception->fresh()->resolved_by)->toBe($accountant->id)
        ->and(AuditLog::query()->where('event', 'frc_sync.exception_resolved')->exists())->toBeTrue();
});

test('lists and figures are branch scoped and unmapped records need view_all', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment(), frcsyncEnrollment(['id' => 331, 'branch_id' => 3]), frcsyncEnrollment(['id' => 332, 'branch_id' => 99])])], [frcsyncPage([
        frcsyncPayment(['id' => 1, 'receipt_number' => 'FRC-LHR-1']),
        frcsyncPayment(['id' => 2, 'receipt_number' => 'FRC-KHI-2', 'enrollment_id' => 331, 'amount' => '700.00'], ['branch_id' => 3]),
        frcsyncPayment(['id' => 3, 'receipt_number' => 'FRC-UNM-3', 'enrollment_id' => 332, 'amount' => '900.00'], ['branch_id' => 99]),
    ])]);
    frcsyncRun();
    $lahoreUser = frcsyncUser(SystemRole::Accountant, $this->branch);
    $admin = frcsyncUser(SystemRole::Administrator);

    $this->actingAs($lahoreUser)->get(route('frc-integration.payments.index'))->assertOk()
        ->assertSee('FRC-LHR-1')->assertDontSee('FRC-KHI-2')->assertDontSee('FRC-UNM-3')->assertSee('3,000.00')->assertDontSee('4,600.00');
    $this->actingAs($lahoreUser)->get(route('frc-integration.payments.unmapped'))->assertOk()->assertDontSee('FRC-UNM-3');
    $this->actingAs($lahoreUser)->get(route('frc-integration.payments.show', FrcPayment::query()->where('frc_payment_id', 2)->sole()))->assertForbidden();
    $this->actingAs($lahoreUser)->get(route('frc-integration.payments.show', FrcPayment::query()->where('frc_payment_id', 3)->sole()))->assertForbidden();
    $this->actingAs($lahoreUser)->get(route('frc-integration.payments.index', ['branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->actingAs($lahoreUser)->get(route('frc-integration.exceptions.index'))->assertOk()->assertDontSee('Payment 3:');

    $this->actingAs($admin)->get(route('frc-integration.payments.index'))->assertOk()->assertSee('FRC-LHR-1')->assertSee('FRC-KHI-2')->assertSee('FRC-UNM-3')->assertSee('4,600.00');
    $this->actingAs($admin)->get(route('frc-integration.payments.unmapped'))->assertOk()->assertSee('FRC-UNM-3')->assertDontSee('FRC-LHR-1');
    $this->actingAs($admin)->get(route('frc-integration.payments.index', ['search' => 'KHI']))->assertOk()->assertSee('FRC-KHI-2')->assertDontSee('FRC-LHR-1');
    $this->actingAs($admin)->get(route('frc-integration.payments.index', ['search' => '3']))->assertOk()->assertSee('FRC-UNM-3');
    $this->actingAs($admin)->get(route('frc-integration.exceptions.index'))->assertOk()->assertSee('Payment 3:');
});

test('empty states and the endpoints-awaiting-approval notice are honest', function () {
    $admin = frcsyncUser(SystemRole::Administrator);
    $this->actingAs($admin)->get(route('frc-integration.index'))->assertOk()->assertSee('Nothing has been synced yet')->assertDontSee('awaiting approval.</strong>', false);
    $this->actingAs($admin)->get(route('frc-integration.payments.index'))->assertOk()->assertSee('No FRC payments mirrored yet');
    $this->actingAs($admin)->get(route('frc-integration.reconciliation'))->assertOk()->assertSee('Nothing to reconcile')->assertSee('diagnostic reference');

    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
    $this->actingAs($admin)->post(route('frc-integration.sync'), ['mode' => 'full', 'confirm' => '1'])->assertSessionHas('error');
    $this->actingAs($admin)->get(route('frc-integration.index'))->assertOk()->assertSee('FRC endpoints awaiting approval');
});

test('the console command refuses to run without --confirm', function () {
    frcsyncFake([frcsyncPage([frcsyncEnrollment()])], [frcsyncPage([frcsyncPayment()])]);

    $this->artisan('frc:sync-financial')->expectsOutputToContain('Refusing to sync without --confirm')->assertFailed();
    $this->artisan('frc:sync-financial --full')->assertFailed();
    expect(FrcSyncRun::query()->count())->toBe(0);
    Http::assertNothingSent();

    $this->artisan('frc:sync-financial --full --confirm')->assertSuccessful();
    expect(FrcSyncRun::query()->where('mode', 'full')->where('status', 'completed')->count())->toBe(2)
        ->and(FrcPayment::query()->count())->toBe(1);
});

test('the existing branch and service sync keeps working', function () {
    Http::fake([
        'http://frc.test/api/integrations/accounting/v1/services*' => Http::response(['data' => [['id' => 5, 'name' => 'Speech Therapy (renamed)', 'status' => 'active']]]),
    ]);

    $result = app(ServiceSyncService::class)->sync();

    expect($result->successful)->toBeTrue()->and($this->service->fresh()->name)->toBe('Speech Therapy (renamed)')
        ->and(FrcService::query()->count())->toBe(1)->and(FrcSyncRun::query()->count())->toBe(0);
});
